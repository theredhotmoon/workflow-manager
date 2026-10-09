import { expect, test } from '@playwright/test';
import { handleModalKeydown, modalLayerCount, pushModalLayer } from './useFocusTrap.js';

const key = (k) => ({ key: k, preventDefault() {} });

test.describe('modal layer stack', () => {
  test('only the top layer gets a key; closing it hands keys back to the one below', () => {
    const calls = [];
    const releaseEditor = pushModalLayer((e) => calls.push(`editor:${e.key}`));
    const releaseConfirm = pushModalLayer((e) => calls.push(`confirm:${e.key}`));
    expect(modalLayerCount()).toBe(2);

    handleModalKeydown(key('Escape'));
    expect(calls).toEqual(['confirm:Escape']);

    releaseConfirm();
    handleModalKeydown(key('Escape'));
    expect(calls).toEqual(['confirm:Escape', 'editor:Escape']);

    releaseEditor();
    expect(modalLayerCount()).toBe(0);
    handleModalKeydown(key('Escape'));
    expect(calls).toHaveLength(2);
  });

  test('a layer closed out of order leaves the newer one on top', () => {
    const calls = [];
    const releaseA = pushModalLayer(() => calls.push('a'));
    const releaseB = pushModalLayer(() => calls.push('b'));

    releaseA();
    handleModalKeydown(key('Escape'));
    expect(calls).toEqual(['b']);

    releaseB();
    expect(modalLayerCount()).toBe(0);
  });

  test('releasing a layer twice is harmless', () => {
    const calls = [];
    const releaseA = pushModalLayer(() => calls.push('a'));
    const releaseB = pushModalLayer(() => calls.push('b'));

    releaseB();
    releaseB();
    expect(modalLayerCount()).toBe(1);
    handleModalKeydown(key('Escape'));
    expect(calls).toEqual(['a']);

    releaseA();
  });

  test('a layer that opens another while handling a key does not pass that key on', () => {
    const calls = [];
    let releaseInner = null;
    const releaseOuter = pushModalLayer(() => {
      calls.push('outer');
      releaseInner = pushModalLayer(() => calls.push('inner'));
    });

    handleModalKeydown(key('Escape'));
    expect(calls).toEqual(['outer']);
    handleModalKeydown(key('Escape'));
    expect(calls).toEqual(['outer', 'inner']);

    releaseInner();
    releaseOuter();
  });
});
