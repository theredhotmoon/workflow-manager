// frontend/e2e/auth/body-limit.spec.js
import { randomBytes } from 'node:crypto';
import { request as playwrightRequest } from '@playwright/test';
import { getEmployeeIdByEmail } from '../fixtures/api-setup.js';
import { expect, getXsrfHeader, STATEFUL_ORIGIN, test } from '../fixtures/auth.js';

// Request bodies are capped at 2 MB in Nginx, login included; only the procedure writes (create,
// update, draft) take up to 50 MB, because pasted images are stored inside the HTML as data: URLs.
// These requests go through the dev proxy to the backend container's Nginx.

const THREE_MB = 3 * 1024 * 1024;

function crc32(bytes) {
  let crc = 0xffffffff;
  for (const byte of bytes) {
    crc ^= byte;
    for (let k = 0; k < 8; k++) crc = (crc >>> 1) ^ (0xedb88320 & -(crc & 1));
  }
  return (crc ^ 0xffffffff) >>> 0;
}

function pngChunk(type, data) {
  const head = Buffer.alloc(4);
  head.writeUInt32BE(data.length);
  const body = Buffer.concat([Buffer.from(type, 'latin1'), data]);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(body));
  return Buffer.concat([head, body, crc]);
}

// A PNG of about `bytes` (valid header, random pixel data: the server checks the image type).
function pngDataUrl(bytes) {
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(1000, 0);
  ihdr.writeUInt32BE(1000, 4);
  ihdr.set([8, 2, 0, 0, 0], 8);
  const png = Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    pngChunk('IHDR', ihdr),
    pngChunk('IDAT', randomBytes(bytes)),
    pngChunk('IEND', Buffer.alloc(0)),
  ]);
  return `data:image/png;base64,${png.toString('base64')}`;
}

test.describe('Request body limits', () => {
  test('a login with a 3 MB body is refused before it reaches the app', async () => {
    const api = await playwrightRequest.newContext({
      baseURL: process.env.E2E_BASE_URL,
      extraHTTPHeaders: STATEFUL_ORIGIN,
    });
    try {
      await api.get('/sanctum/csrf-cookie');
      const xsrf = (await api.storageState()).cookies.find((c) => c.name === 'XSRF-TOKEN');
      // Valid credentials: without the limit this would log in (200).
      const res = await api.post('/api/login', {
        data: {
          email: 'regular@shiftchanger.com',
          password: 'password123',
          padding: 'x'.repeat(THREE_MB),
        },
        headers: {
          Accept: 'application/json',
          'X-XSRF-TOKEN': decodeURIComponent(xsrf.value),
        },
      });
      expect([413, 422]).toContain(res.status());
    } finally {
      await api.dispose();
    }
  });

  test('a procedure with a 3 MB pasted image is still saved, published and as a draft', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const headers = { Accept: 'application/json', ...(await getXsrfHeader(context)) };
    const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
    const image = pngDataUrl(THREE_MB);

    const created = await context.request.post('/api/procedures', {
      data: {
        title: 'Body Limit Image Procedure',
        owner_id: adminId,
        content: `<p>Wiring</p><img src="${image}" alt="board">`,
      },
      headers,
    });
    expect(created.status()).toBe(201);
    const { id } = await created.json();

    const draft = await context.request.put(`/api/procedures/${id}/draft`, {
      data: {
        title: 'Body Limit Image Procedure',
        content: `<p>Wiring, v2</p><img src="${image}" alt="board">`,
      },
      headers,
    });
    expect(draft.status()).toBe(200);

    const shown = await (await context.request.get(`/api/procedures/${id}`, { headers })).json();
    expect(shown.content).toContain(image);
  });
});
