import { expect, test } from 'vitest';
import { MAX_CONFIRM_ROUNDS, putDayShiftCount } from './dayShiftCount.js';

const PAYLOAD = { date: '2094-03-10', count: 1, shift_group_id: 7 };

function staffed409(ids) {
  const err = new Error('Request failed with status code 409');
  err.response = {
    status: 409,
    data: {
      code: 'staffed_shifts_removal',
      message: 'Removing these slots would delete staffed shifts.',
      shifts: ids.map((id) => ({
        id,
        date: '2094-03-10',
        shift_number: id,
        assigned_users: ['A'],
      })),
    },
  };
  return err;
}

/** A fake PUT that answers with the given outcomes in order (an Error is thrown). */
function fakePut(outcomes) {
  const calls = [];
  const put = async (body) => {
    calls.push(body);
    const next = outcomes.shift();
    if (next instanceof Error) throw next;
    return next;
  };
  return { put, calls };
}

function fakeConfirm(answers) {
  const shown = [];
  const confirm = async (opts) => {
    shown.push(opts);
    return answers.shift() ?? true;
  };
  return { confirm, shown };
}

test('saves directly when nothing staffed is in the way', async () => {
  const { put, calls } = fakePut([{ data: { shifts: [] } }]);
  const { confirm, shown } = fakeConfirm([]);

  const result = await putDayShiftCount({ put, confirm, payload: PAYLOAD });

  expect(result.status).toBe('saved');
  expect(shown).toHaveLength(0);
  expect(calls).toEqual([PAYLOAD]);
});

test('asks again with the new list when the staffed shifts changed before the confirm landed', async () => {
  const { put, calls } = fakePut([staffed409([3]), staffed409([2, 3]), { data: { shifts: [] } }]);
  const { confirm, shown } = fakeConfirm([true, true]);

  const result = await putDayShiftCount({ put, confirm, payload: PAYLOAD });

  expect(result.status).toBe('saved');
  expect(shown).toHaveLength(2);
  expect(shown[0].title).toBe('Remove a staffed shift?');
  expect(shown[1].title).toBe('Remove 2 staffed shifts?');
  expect(calls[1]).toEqual({ ...PAYLOAD, confirm_remove_staffed: true, expected_remove_ids: [3] });
  expect(calls[2]).toEqual({
    ...PAYLOAD,
    confirm_remove_staffed: true,
    expected_remove_ids: [2, 3],
  });
});

test('stops when the manager cancels any round', async () => {
  const { put, calls } = fakePut([staffed409([3]), staffed409([2, 3])]);
  const { confirm } = fakeConfirm([true, false]);

  const result = await putDayShiftCount({ put, confirm, payload: PAYLOAD });

  expect(result.status).toBe('cancelled');
  expect(calls).toHaveLength(2);
});

test(`gives up after ${MAX_CONFIRM_ROUNDS} dialogs with a message for the toast`, async () => {
  const outcomes = Array.from({ length: MAX_CONFIRM_ROUNDS + 1 }, (_, i) => staffed409([i + 1]));
  const { put, calls } = fakePut(outcomes);
  const { confirm, shown } = fakeConfirm([]);

  const result = await putDayShiftCount({ put, confirm, payload: PAYLOAD });

  expect(MAX_CONFIRM_ROUNDS).toBe(3);
  expect(result.status).toBe('gave_up');
  expect(result.message).toContain('kept changing');
  expect(shown).toHaveLength(MAX_CONFIRM_ROUNDS);
  expect(calls).toHaveLength(MAX_CONFIRM_ROUNDS + 1);
});

test('passes other errors through', async () => {
  const boom = new Error('500');
  boom.response = { status: 500, data: { message: 'Server error' } };
  const { put } = fakePut([boom]);
  const { confirm } = fakeConfirm([]);

  await expect(putDayShiftCount({ put, confirm, payload: PAYLOAD })).rejects.toBe(boom);
});
