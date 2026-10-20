// @vitest-environment jsdom
// The focus trap against a jsdom document: focus moves, the Tab/Escape handling and where focus
// goes back to. Real-browser behaviour (actual Tab key presses, layout) is covered by
// components/BaseModal.browser.test.js; useFocusTrap.test.js covers the layer stack alone.
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { createApp, h, nextTick, ref } from 'vue';
import { createFocusTrap, modalLayerCount, useFocusTrap } from './useFocusTrap.js';

const originalGetClientRects = Element.prototype.getClientRects;

function dialog(html, { modal = true } = {}) {
  const el = document.createElement('div');
  el.setAttribute('role', 'dialog');
  if (modal) el.setAttribute('aria-modal', 'true');
  el.tabIndex = -1;
  el.innerHTML = html;
  document.body.appendChild(el);
  return el;
}

const key = (k, extra = {}) => ({ key: k, preventDefault: vi.fn(), ...extra });

beforeEach(() => {
  document.body.innerHTML = '<main><p>Page</p><button id="opener">Open</button></main>';
  // jsdom has no layout: treat what is in the document and not `hidden` as rendered.
  Element.prototype.getClientRects = function getClientRects() {
    return this.isConnected && !this.closest('[hidden]') ? [{}] : [];
  };
});

afterEach(() => {
  Element.prototype.getClientRects = originalGetClientRects;
  expect(modalLayerCount()).toBe(0);
});

describe('createFocusTrap: opening and closing', () => {
  test('activate moves focus to the first focusable control; deactivate gives it back to the opener', async () => {
    const opener = document.getElementById('opener');
    opener.focus();
    const el = dialog(
      '<p>Text</p><button id="first">First</button><button id="last">Last</button>',
    );
    const trap = createFocusTrap(ref(el));

    await trap.activate();
    expect(modalLayerCount()).toBe(1);
    expect(document.activeElement.id).toBe('first');

    trap.deactivate();
    expect(modalLayerCount()).toBe(0);
    expect(document.activeElement).toBe(opener);
    trap.deactivate(); // a second close is harmless
  });

  test('initialFocus wins; with nothing focusable the dialog itself takes focus; focus already inside is left alone', async () => {
    const el = dialog('<input id="a"><input id="b">');
    const chosen = createFocusTrap(ref(el), { initialFocus: () => el.querySelector('#b') });
    await chosen.activate();
    expect(document.activeElement.id).toBe('b');
    chosen.deactivate();

    const empty = dialog('<p>Nothing to focus</p>');
    const bare = createFocusTrap(ref(empty));
    await bare.activate();
    expect(document.activeElement).toBe(empty);
    bare.deactivate();

    el.querySelector('#a').focus();
    const inside = createFocusTrap(ref(el));
    await inside.activate();
    expect(document.activeElement.id).toBe('a');
    inside.deactivate();
  });

  test('disabled and hidden controls are skipped', async () => {
    const el = dialog(
      '<button disabled>No</button><div hidden><button id="hidden">No</button></div>' +
        '<a href="#x" id="link">Yes</a><button id="btn">Yes</button>',
    );
    const trap = createFocusTrap(ref(el));
    await trap.activate();
    expect(document.activeElement.id).toBe('link');
    trap.deactivate();
  });
});

describe('createFocusTrap: keys', () => {
  test('Tab wraps from the last control to the first, Shift+Tab from the first to the last', async () => {
    const el = dialog(
      '<button id="first">First</button><button id="mid">Mid</button><button id="last">Last</button>',
    );
    const trap = createFocusTrap(ref(el));
    await trap.activate();

    el.querySelector('#last').focus();
    let event = key('Tab');
    trap.handleKeydown(event);
    expect(event.preventDefault).toHaveBeenCalled();
    expect(document.activeElement.id).toBe('first');

    event = key('Tab', { shiftKey: true });
    trap.handleKeydown(event);
    expect(event.preventDefault).toHaveBeenCalled();
    expect(document.activeElement.id).toBe('last');

    // In the middle, the browser's own Tab order is left alone.
    el.querySelector('#mid').focus();
    event = key('Tab');
    trap.handleKeydown(event);
    expect(event.preventDefault).not.toHaveBeenCalled();
    expect(document.activeElement.id).toBe('mid');
    trap.deactivate();
  });

  test('from outside the dialog, or from the dialog element itself, Tab lands inside', async () => {
    const el = dialog('<button id="first">First</button><button id="last">Last</button>');
    const trap = createFocusTrap(ref(el));
    await trap.activate();

    document.getElementById('opener').focus();
    trap.handleKeydown(key('Tab'));
    expect(document.activeElement.id).toBe('first');

    document.getElementById('opener').focus();
    trap.handleKeydown(key('Tab', { shiftKey: true }));
    expect(document.activeElement.id).toBe('last');

    el.focus();
    trap.handleKeydown(key('Tab', { shiftKey: true }));
    expect(document.activeElement.id).toBe('last');
    trap.deactivate();
  });

  test('with nothing focusable, Tab is swallowed and the dialog keeps focus', async () => {
    const el = dialog('<p>Nothing</p>');
    const trap = createFocusTrap(ref(el));
    await trap.activate();
    document.getElementById('opener').focus();

    const event = key('Tab');
    trap.handleKeydown(event);
    expect(event.preventDefault).toHaveBeenCalled();
    expect(document.activeElement).toBe(el);
    trap.deactivate();
  });

  test('Escape is consumed and reported; other keys are ignored', async () => {
    const el = dialog('<button>x</button>');
    const onEscape = vi.fn();
    const trap = createFocusTrap(ref(el), { onEscape });
    await trap.activate();

    const escapeKey = key('Escape');
    trap.handleKeydown(escapeKey);
    expect(escapeKey.preventDefault).toHaveBeenCalled();
    expect(onEscape).toHaveBeenCalledTimes(1);

    const enter = key('Enter');
    trap.handleKeydown(enter);
    expect(enter.preventDefault).not.toHaveBeenCalled();
    trap.deactivate();
  });

  test('the document listener hands a key to the top layer only', async () => {
    const lower = createFocusTrap(ref(dialog('<button id="l">L</button>')), { onEscape: vi.fn() });
    await lower.activate();
    const onEscape = vi.fn();
    const upper = createFocusTrap(ref(dialog('<button id="u">U</button>')), { onEscape });
    await upper.activate();

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect(onEscape).toHaveBeenCalledTimes(1);
    expect(lower.handleKeydown).toBeDefined();
    upper.deactivate();
    lower.deactivate();
  });
});

describe('createFocusTrap: where focus goes back to', () => {
  test('when the opener is gone, returnFocus() decides', async () => {
    const opener = document.getElementById('opener');
    opener.focus();
    const el = dialog('<button>x</button>');
    const standIn = document.createElement('button');
    standIn.id = 'stand-in';
    document.body.appendChild(standIn);
    const trap = createFocusTrap(ref(el), { returnFocus: () => standIn });
    await trap.activate();

    opener.remove();
    trap.deactivate();
    expect(document.activeElement).toBe(standIn);
  });

  test('a dialog opened from another dialog hands focus back to that dialog when its button is gone', async () => {
    const lowerEl = dialog('<button id="menu-item">Open sub</button>');
    const lower = createFocusTrap(ref(lowerEl));
    await lower.activate();
    expect(document.activeElement.id).toBe('menu-item');

    const upperEl = dialog('<button>Sub</button>');
    const upper = createFocusTrap(ref(upperEl));
    await upper.activate();

    lowerEl.querySelector('#menu-item').remove();
    upper.deactivate();
    expect(document.activeElement).toBe(lowerEl);
    lower.deactivate();
  });

  test('with no dialog to go back to, <main> becomes a temporary focus target and forgets it on blur', async () => {
    const opener = document.getElementById('opener');
    opener.focus();
    const el = dialog('<button>x</button>');
    const trap = createFocusTrap(ref(el));
    await trap.activate();

    opener.remove();
    trap.deactivate();
    const main = document.querySelector('main');
    expect(document.activeElement).toBe(main);
    expect(main.getAttribute('tabindex')).toBe('-1');
    expect(main.hasAttribute('data-focus-fallback')).toBe(true);

    main.dispatchEvent(new Event('blur'));
    expect(main.hasAttribute('tabindex')).toBe(false);
    expect(main.hasAttribute('data-focus-fallback')).toBe(false);
  });

  test('focus that was on <body> when the dialog opened also goes to the stand-in, not back to <body>', async () => {
    document.activeElement?.blur?.();
    expect(document.activeElement).toBe(document.body);
    const el = dialog('<button>x</button>');
    const trap = createFocusTrap(ref(el));
    await trap.activate();
    trap.deactivate();
    expect(document.activeElement).toBe(document.querySelector('main'));
  });
});

describe('useFocusTrap', () => {
  test('follows the open flag and releases the layer when the component unmounts', async () => {
    const el = dialog('<button id="inside">x</button>');
    const isOpen = ref(false);
    const host = document.createElement('div');
    document.body.appendChild(host);
    const app = createApp({
      setup() {
        useFocusTrap(ref(el), isOpen, {});
        return () => h('div');
      },
    });
    app.mount(host);

    isOpen.value = true;
    await nextTick();
    await nextTick();
    expect(modalLayerCount()).toBe(1);
    expect(document.activeElement.id).toBe('inside');

    isOpen.value = false;
    await nextTick();
    expect(modalLayerCount()).toBe(0);

    isOpen.value = true;
    await nextTick();
    expect(modalLayerCount()).toBe(1);
    app.unmount();
    expect(modalLayerCount()).toBe(0);
  });
});
