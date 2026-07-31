// frontend/src/composables/useFocusTrap.test.js

import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, ref } from 'vue';
import { useFocusTrap } from './useFocusTrap.js';

function mountHost(onEscape) {
  const Host = defineComponent({
    setup() {
      const container = ref(null);
      const isOpen = ref(false);
      useFocusTrap(container, isOpen, { onEscape });
      return { container, isOpen };
    },
    template: `
      <div>
        <button id="outside">Outside trigger</button>
        <div ref="container">
          <button id="first">First</button>
          <input id="middle" />
          <button id="last">Last</button>
        </div>
      </div>
    `,
  });

  return mount(Host, { attachTo: document.body });
}

function dispatchKey(key, shiftKey = false) {
  document.activeElement.dispatchEvent(
    new KeyboardEvent('keydown', { key, shiftKey, bubbles: true, cancelable: true }),
  );
}

describe('useFocusTrap', () => {
  let wrapper;

  afterEach(() => {
    wrapper?.unmount();
  });

  it('moves focus to the first focusable element when opened', async () => {
    wrapper = mountHost();
    wrapper.find('#outside').element.focus();

    wrapper.vm.isOpen = true;
    await flushPromises();

    expect(document.activeElement.id).toBe('first');
  });

  it('wraps Tab from the last element back to the first', async () => {
    wrapper = mountHost();
    wrapper.vm.isOpen = true;
    await flushPromises();

    wrapper.find('#last').element.focus();
    dispatchKey('Tab');

    expect(document.activeElement.id).toBe('first');
  });

  it('wraps Shift+Tab from the first element back to the last', async () => {
    wrapper = mountHost();
    wrapper.vm.isOpen = true;
    await flushPromises();

    wrapper.find('#first').element.focus();
    dispatchKey('Tab', true);

    expect(document.activeElement.id).toBe('last');
  });

  it('invokes onEscape when Escape is pressed', async () => {
    const onEscape = vi.fn();
    wrapper = mountHost(onEscape);
    wrapper.vm.isOpen = true;
    await flushPromises();

    dispatchKey('Escape');

    expect(onEscape).toHaveBeenCalledOnce();
  });

  it('restores focus to the previously-focused element when closed', async () => {
    wrapper = mountHost();
    const outside = wrapper.find('#outside').element;
    outside.focus();

    wrapper.vm.isOpen = true;
    await flushPromises();
    expect(document.activeElement.id).toBe('first');

    wrapper.vm.isOpen = false;
    await flushPromises();

    expect(document.activeElement).toBe(outside);
  });
});
