// Test page for BaseModal.test.js (served by a throwaway Vite server, never part of the app):
// an outer dialog with a form-ish body that can open a second, stacked alert dialog.
import { createApp, h, ref } from 'vue';
import BaseModal from './BaseModal.vue';

const App = {
  setup() {
    const outer = ref(false);
    const inner = ref(false);
    const sticky = ref(false);

    return () =>
      h('div', [
        h('button', { id: 'before' }, 'Before'),
        h('button', { id: 'open-outer', onClick: () => (outer.value = true) }, 'Open outer'),
        h('button', { id: 'open-sticky', onClick: () => (sticky.value = true) }, 'Open sticky'),
        outer.value &&
          h(
            BaseModal,
            { described: true, backdropClass: 'backdrop', onClose: () => (outer.value = false) },
            {
              default: ({ titleId, descriptionId }) => [
                h('h2', { id: titleId }, 'Outer dialog'),
                h('p', { id: descriptionId }, 'Outer description'),
                h('input', { id: 'outer-first', 'aria-label': 'Outer first' }),
                h('button', { id: 'hidden-button', style: 'display:none' }, 'Hidden'),
                h(
                  'button',
                  { id: 'open-inner', onClick: () => (inner.value = true) },
                  'Open inner',
                ),
                h('button', { id: 'outer-last' }, 'Outer last'),
              ],
            },
          ),
        inner.value &&
          h(
            BaseModal,
            {
              role: 'alertdialog',
              initialFocus: '#inner-ok',
              onClose: () => (inner.value = false),
            },
            {
              default: ({ titleId }) => [
                h('h2', { id: titleId }, 'Inner dialog'),
                h('button', { id: 'inner-cancel', onClick: () => (inner.value = false) }, 'Cancel'),
                h('button', { id: 'inner-ok' }, 'OK'),
              ],
            },
          ),
        sticky.value &&
          h(
            BaseModal,
            {
              closeOnBackdrop: false,
              closeOnEscape: false,
              label: 'Sticky dialog',
              onClose: () => (sticky.value = false),
            },
            {
              default: () => [
                h('button', { id: 'sticky-close', onClick: () => (sticky.value = false) }, 'Done'),
              ],
            },
          ),
        h('button', { id: 'after' }, 'After'),
      ]);
  },
};

createApp(App).mount('#app');
