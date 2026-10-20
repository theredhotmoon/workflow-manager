import { expect, test } from 'vitest';
import { fetchAllPages } from './fetchAllPages.js';

// A fake GET over `items` that pages like Laravel's paginator ({ data, last_page }).
function pagedGet(items, calls = []) {
  return async (url, { params }) => {
    calls.push({ url, params });
    const perPage = params.per_page;
    const start = (params.page - 1) * perPage;
    return {
      data: {
        data: items.slice(start, start + perPage),
        last_page: Math.max(1, Math.ceil(items.length / perPage)),
      },
    };
  };
}

const range = (n) => Array.from({ length: n }, (_, i) => ({ id: i + 1 }));

test('collects every page in order', async () => {
  const calls = [];
  const all = await fetchAllPages(pagedGet(range(250), calls), '/api/procedures');

  expect(all.map((p) => p.id)).toEqual(range(250).map((p) => p.id));
  expect(calls.map((c) => c.params.page)).toEqual([1, 2, 3]);
  expect(calls.every((c) => c.url === '/api/procedures' && c.params.per_page === 100)).toBe(true);
});

test('stops after one request when everything fits on the first page', async () => {
  const calls = [];
  const all = await fetchAllPages(pagedGet(range(3), calls), '/api/procedures');

  expect(all).toHaveLength(3);
  expect(calls).toHaveLength(1);
});

test('returns an empty list for an empty result', async () => {
  expect(await fetchAllPages(pagedGet([]), '/api/procedures')).toEqual([]);
});

test('passes extra query params on every page', async () => {
  const calls = [];
  await fetchAllPages(pagedGet(range(150), calls), '/api/procedures', { sort_by: 'title' });

  expect(calls.map((c) => c.params.sort_by)).toEqual(['title', 'title']);
});
