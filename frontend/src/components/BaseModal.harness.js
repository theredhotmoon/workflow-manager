// Test page for BaseModal.test.js (served by a throwaway Vite server, never part of the app):
// an outer dialog with a form-ish body that can open a second, stacked alert dialog (or a
// non-closable one), grow an extra control, or go busy; and openers that vanish when used.
import { createApp, h, ref } from 'vue';
import BaseModal from './BaseModal.vue';

const App = {
  setup() {
    const outer = ref(false);
    const inner = ref(false);
    const sticky = ref(false);
    const extra = ref(false);
    const busy = ref(false);
    // Which vanishing opener opened the "vanish" dialog ('' = closed); each one hides itself.
    const vanish = ref('');
    const vanished = ref({ default: false, custom: false });
    const openVanish = (which) => {
      vanish.value = which;
      vanished.value = { ...vanished.value, [which]: true };
    };
    const checkbox = (id, label, model) =>
      h('label', [
        h('input', {
          id,
          type: 'checkbox',
          checked: model.value,
          onChange: (e) => (model.value = e.target.checked),
        }),
        label,
      ]);

    return () =>
      h('main', [
        h('button', { id: 'before' }, 'Before'),
        h('button', { id: 'open-outer', onClick: () => (outer.value = true) }, 'Open outer'),
        h('button', { id: 'open-sticky', onClick: () => (sticky.value = true) }, 'Open sticky'),
        !vanished.value.default &&
          h(
            'button',
            { key: 'vanish-default', id: 'vanish-default', onClick: () => openVanish('default') },
            'Vanish',
          ),
        !vanished.value.custom &&
          h(
            'button',
            { key: 'vanish-custom', id: 'vanish-custom', onClick: () => openVanish('custom') },
            'Vanish 2',
          ),
        outer.value &&
          h(
            BaseModal,
            {
              panelClass: 'panel',
              described: true,
              backdropClass: 'backdrop',
              busy: busy.value,
              onClose: () => (outer.value = false),
            },
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
                h(
                  'button',
                  { id: 'open-sticky-over', onClick: () => (sticky.value = true) },
                  'Open sticky on top',
                ),
                checkbox('outer-extra-toggle', 'Show extra', extra),
                checkbox('outer-busy', 'Busy', busy),
                h('button', { id: 'outer-last' }, 'Outer last'),
                extra.value && h('button', { id: 'outer-extra' }, 'Extra'),
              ],
            },
          ),
        inner.value &&
          h(
            BaseModal,
            {
              panelClass: 'panel',
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
              panelClass: 'panel',
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
        vanish.value &&
          h(
            BaseModal,
            {
              panelClass: 'panel',
              label: 'Vanish dialog',
              returnFocus: vanish.value === 'custom' ? '#after' : '',
              onClose: () => (vanish.value = ''),
            },
            { default: () => [h('button', { id: 'vanish-close' }, 'Close')] },
          ),
        h('button', { id: 'after' }, 'After'),
      ]);
  },
};

createApp(App).mount('#app');
