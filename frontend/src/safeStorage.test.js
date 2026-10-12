import { expect, test } from '@playwright/test';
import { readStorage, removeStorage, writeStorage } from './safeStorage.js';

function memoryStorage() {
  const data = new Map();
  return {
    getItem: (k) => (data.has(k) ? data.get(k) : null),
    setItem: (k, v) => data.set(k, String(v)),
    removeItem: (k) => data.delete(k),
  };
}

function throwingStorage() {
  const fail = () => {
    throw new Error('SecurityError: storage is disabled');
  };
  return { getItem: fail, setItem: fail, removeItem: fail };
}

test.describe('safeStorage (L30)', () => {
  let original;
  test.beforeEach(() => {
    original = Object.getOwnPropertyDescriptor(globalThis, 'localStorage');
  });
  test.afterEach(() => {
    if (original) Object.defineProperty(globalThis, 'localStorage', original);
    else delete globalThis.localStorage;
  });

  function install(value) {
    Object.defineProperty(globalThis, 'localStorage', {
      value,
      configurable: true,
      writable: true,
    });
  }

  test('reads, writes and removes through working storage', () => {
    install(memoryStorage());
    expect(readStorage('k', 'dflt')).toBe('dflt');
    expect(writeStorage('k', 'list')).toBe(true);
    expect(readStorage('k', 'dflt')).toBe('list');
    removeStorage('k');
    expect(readStorage('k', 'dflt')).toBe('dflt');
  });

  test('falls back instead of throwing when storage throws', () => {
    install(throwingStorage());
    expect(readStorage('k', 'tiles')).toBe('tiles');
    expect(writeStorage('k', 'list')).toBe(false);
    expect(() => removeStorage('k')).not.toThrow();
  });

  test('falls back when even reading the localStorage property throws', () => {
    Object.defineProperty(globalThis, 'localStorage', {
      configurable: true,
      get() {
        throw new Error('SecurityError: access denied');
      },
    });
    expect(readStorage('k', 'tiles')).toBe('tiles');
    expect(writeStorage('k', 'list')).toBe(false);
    expect(() => removeStorage('k')).not.toThrow();
  });

  test('falls back when there is no storage at all', () => {
    install(undefined);
    expect(readStorage('k', 'tiles')).toBe('tiles');
    expect(writeStorage('k', 'list')).toBe(false);
  });
});
