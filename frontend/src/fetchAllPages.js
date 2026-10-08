// Loads every page of a Laravel-paginated list endpoint ({ data, last_page }) and returns the
// concatenated items. The API caps per_page at 100, so screens that need the whole list (e.g. all
// procedures) must page through it.
//
//   const procedures = await fetchAllPages((url, config) => api.get(url, config), '/api/procedures');
export async function fetchAllPages(get, url, params = {}) {
  const all = [];
  let page = 1;
  let lastPage = 1;
  do {
    const res = await get(url, { params: { ...params, per_page: 100, page } });
    all.push(...res.data.data);
    lastPage = res.data.last_page;
    page++;
  } while (page <= lastPage);
  return all;
}
