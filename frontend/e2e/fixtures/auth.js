// frontend/e2e/fixtures/auth.js
import { test as base, expect } from '@playwright/test';

export async function getXsrfHeader(context) {
  const cookies = await context.cookies();
  const xsrf = cookies.find((c) => c.name === 'XSRF-TOKEN');
  return xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value) } : {};
}

export async function loginAs(context, email, password) {
  await context.request.get('/sanctum/csrf-cookie');
  const headers = await getXsrfHeader(context);
  const res = await context.request.post('/api/login', { data: { email, password }, headers });
  if (!res.ok()) {
    throw new Error(`loginAs(${email}) failed: ${res.status()} ${await res.text()}`);
  }
}

export const test = base.extend({
  adminPage: async ({ browser }, use) => {
    const context = await browser.newContext();
    await loginAs(context, 'admin@shiftchanger.com', '1234');
    const page = await context.newPage();
    await use(page);
    await context.close();
  },
  employeePage: async ({ browser }, use) => {
    const context = await browser.newContext();
    await loginAs(context, 'regular@shiftchanger.com', 'password123');
    const page = await context.newPage();
    await use(page);
    await context.close();
  },
});

export { expect };
