import { expect, test } from '@playwright/test';
import { describeApiError, fieldError } from './apiErrors.js';

const fail = (status, data) => ({ response: { status, data } });

test.describe('describeApiError', () => {
  test('a 422 gives the first message per field and the server summary', () => {
    const e = describeApiError(
      fail(422, {
        message: 'The title field is required. (and 1 more error)',
        errors: { title: ['The title field is required.', 'Second'], 'tag_ids.0': ['Bad tag.'] },
      }),
    );
    expect(e).toEqual({
      silent: false,
      status: 422,
      message: 'The title field is required. (and 1 more error)',
      fieldErrors: { title: 'The title field is required.', 'tag_ids.0': 'Bad tag.' },
    });
  });

  test('a 422 without a message falls back to a summary', () => {
    expect(describeApiError(fail(422, { errors: { name: ['Taken.'] } })).message).toBe(
      'Please fix the highlighted fields.',
    );
  });

  test('a 5xx gives the generic message with the status, not the server text', () => {
    expect(describeApiError(fail(500, { message: 'Server Error' })).message).toBe(
      'Could not save, try again (500).',
    );
    expect(describeApiError(fail(503, {}), 'Could not delete, try again').message).toBe(
      'Could not delete, try again (503).',
    );
  });

  test('other statuses use the server message, else the generic one', () => {
    expect(describeApiError(fail(403, { message: 'This action is unauthorized.' })).message).toBe(
      'This action is unauthorized.',
    );
    expect(describeApiError(fail(404, {})).message).toBe('Could not save, try again (404).');
  });

  test('a network failure has no status', () => {
    expect(describeApiError(new Error('Network Error'))).toEqual({
      silent: false,
      status: null,
      message: 'Could not save, try again (no connection).',
      fieldErrors: {},
    });
  });

  test('401 and 419 are silent: the session-expiry handler takes the user to login', () => {
    expect(describeApiError(fail(401, { message: 'Unauthenticated.' })).silent).toBe(true);
    expect(describeApiError(fail(419, { message: 'CSRF token mismatch.' })).silent).toBe(true);
  });
});

test.describe('fieldError', () => {
  test('finds a field or its array items', () => {
    const errors = { title: 'Required.', 'tag_ids.1': 'Bad tag.' };
    expect(fieldError(errors, 'title')).toBe('Required.');
    expect(fieldError(errors, 'tag_ids')).toBe('Bad tag.');
    expect(fieldError(errors, 'content')).toBe('');
    expect(fieldError(errors, 'tag')).toBe('');
  });
});
