// frontend/e2e/fixtures/hold.js
//
// Deterministic stale-response races: hold the matching (older) request until the newer one has
// rendered, then let the older one finish last.
//
//   mode 'continue' (default): the request reaches the server only after release.
//   mode 'snapshot': the server answers at once, but the browser only gets that (now stale) answer
//     after release — use when a mutation happens between the read and its delivery.
//   mode 'abort': the request fails after release (a superseded request that errors).
//
// Only requests with `method` (default GET) are held; `skip` lets the first N matches through and
// `times` caps how many are held. `seen` resolves when the first request is held (for 'snapshot':
// once the server has answered it).
export async function holdRequests(
  page,
  matcher,
  { mode = 'continue', method = 'GET', skip = 0, times = Number.POSITIVE_INFINITY } = {},
) {
  let release;
  const released = new Promise((resolve) => {
    release = resolve;
  });
  let markSeen;
  const seen = new Promise((resolve) => {
    markSeen = resolve;
  });
  let matched = 0;

  await page.route(matcher, async (route) => {
    if (route.request().method() !== method) return route.fallback();
    matched += 1;
    if (matched <= skip || matched > skip + times) return route.fallback();
    if (mode === 'snapshot') {
      const response = await route.fetch();
      markSeen();
      await released;
      await route.fulfill({ response });
      return;
    }
    markSeen();
    await released;
    if (mode === 'abort') await route.abort();
    else await route.continue();
  });

  const isHeld = (url, requestMethod) => requestMethod === method && matcher(new URL(url));

  // Release, wait until the held request has finished (response or failure), then until the page
  // is idle, so whatever the view does with the stale result has happened before assertions run.
  async function releaseAndSettle() {
    const finished =
      mode === 'abort'
        ? page.waitForEvent('requestfailed', (req) => isHeld(req.url(), req.method()))
        : page.waitForResponse((res) => isHeld(res.url(), res.request().method()));
    release();
    await finished;
    await page.waitForLoadState('networkidle');
  }

  return { seen, release, releaseAndSettle };
}
