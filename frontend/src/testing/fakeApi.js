// frontend/src/testing/fakeApi.js
//
// A stand-in for src/axios.js in Vitest unit tests. A test swaps it in with
//
//   vi.mock('../../axios.js', async () => ({ default: (await import('../../testing/fakeApi.js')).api }));
//
// and answers requests by method and URL:
//
//   api.on('get', '/api/shift-colors', [{ id: 1, hex: '#fff' }]);
//   api.on('post', '/api/schedule/assign', (call) => ({ id: 7, ...call.body }));
//   api.on('put', /\/shifts\/\d+\/times$/, () => { throw httpError(422, { message: 'Bad times' }); });
//
// A handler is the response `data`, or a function of the recorded call returning it (or throwing).
// The latest handler for a URL wins. Every call is recorded in `api.calls` as
// { method, url, body?, params? }: `body` is the request body (for DELETE, axios's `config.data`),
// `params` the query parameters. `api.sent(method, url)` filters them. Each test file gets its own
// module registry, so the exported `api` is per file; call `api.reset()` in beforeEach.
export function httpError(status, data = {}) {
  return { response: { status, data } };
}

export function createFakeApi() {
  const calls = [];
  let handlers = [];

  const matches = (pattern, url) =>
    pattern instanceof RegExp ? pattern.test(url) : pattern === url;

  async function request(method, url, body, config) {
    const call = { method, url };
    if (body !== undefined) call.body = body;
    if (config?.data !== undefined) call.body = config.data;
    if (config?.params !== undefined) call.params = config.params;
    calls.push(call);
    const handler = handlers.find((h) => h.method === method && matches(h.url, url));
    if (!handler) throw new Error(`fakeApi: no handler for ${method.toUpperCase()} ${url}`);
    const data =
      typeof handler.respond === 'function' ? await handler.respond(call) : handler.respond;
    return { data };
  }

  return {
    calls,
    reset() {
      calls.length = 0;
      handlers = [];
    },
    on(method, url, respond) {
      handlers.unshift({ method, url, respond });
      return this;
    },
    sent(method, url) {
      return calls.filter((c) => c.method === method && (url === undefined || matches(url, c.url)));
    },
    get: (url, config) => request('get', url, undefined, config),
    post: (url, body, config) => request('post', url, body, config),
    put: (url, body, config) => request('put', url, body, config),
    delete: (url, config) => request('delete', url, undefined, config),
  };
}

export const api = createFakeApi();

// The real timer, taken before any test installs fake ones (vi.useFakeTimers), so flush() still
// returns under fake timers instead of waiting for a tick that never comes.
const realSetTimeout = globalThis.setTimeout;

/** Lets pending promise callbacks (and `await`s inside the code under test) run. */
export async function flush() {
  await new Promise((resolve) => realSetTimeout(resolve, 0));
}
