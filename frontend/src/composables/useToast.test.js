import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useToast } from './useToast.js';

describe('useToast', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('shows a toast with the given message and type', () => {
    const { toast, showToast } = useToast();

    showToast('Saved successfully', 'success');

    expect(toast.value).toEqual({ show: true, message: 'Saved successfully', type: 'success' });
  });

  it('defaults to type "success" and auto-dismisses after 4000ms', () => {
    const { toast, showToast } = useToast();

    showToast('Done');
    expect(toast.value.type).toBe('success');

    vi.advanceTimersByTime(3999);
    expect(toast.value.show).toBe(true);

    vi.advanceTimersByTime(1);
    expect(toast.value.show).toBe(false);
  });

  it('auto-dismisses after a custom duration', () => {
    const { toast, showToast } = useToast();

    showToast('Quick', 'warning', 1000);

    vi.advanceTimersByTime(1000);
    expect(toast.value.show).toBe(false);
  });

  it('dismissToast clears immediately and cancels a pending auto-dismiss', () => {
    const { toast, showToast, dismissToast } = useToast();

    showToast('Will be dismissed early');
    dismissToast();

    expect(toast.value.show).toBe(false);

    vi.advanceTimersByTime(5000);
    expect(toast.value.show).toBe(false);
  });
});
