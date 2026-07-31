import { HttpResponse, http } from 'msw';

export const handlers = [http.get('/sanctum/csrf-cookie', () => HttpResponse.json({}))];
