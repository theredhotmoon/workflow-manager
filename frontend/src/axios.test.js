import { expect, test } from 'vitest';
import api from './axios.js';

test('the API client sends the session cookie and asks for JSON, same-origin', () => {
  expect(api.defaults.baseURL).toBe('/');
  expect(api.defaults.withCredentials).toBe(true);
  expect(api.defaults.headers['X-Requested-With']).toBe('XMLHttpRequest');
  expect(api.defaults.headers.Accept).toBe('application/json');
});
