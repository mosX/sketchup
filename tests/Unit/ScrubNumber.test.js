import test from 'node:test';
import assert from 'node:assert/strict';
import { vScrubNumber } from '../../resources/js/editor/scrubNumber.js';

class TestNumberInput extends EventTarget {
    ownerDocument = new EventTarget();
    value = '12';
    min = '-45';
    max = '45';
    step = '0.1';
    capture = null;
    selected = false;

    get valueAsNumber() { return Number(this.value); }
    setAttribute() {}
    removeAttribute() {}
    setPointerCapture(pointerId) { this.capture = pointerId; }
    hasPointerCapture(pointerId) { return this.capture === pointerId; }
    releasePointerCapture() { this.capture = null; }
    focus() {}
    select() { this.selected = true; }

    pointer(type, clientX, shiftKey = false) {
        this.dispatchEvent(Object.assign(new Event(type, { cancelable: true }), {
            pointerId: 1, pointerType: 'mouse', button: 0, clientX, shiftKey,
        }));
    }
}

test('scrubbing previews values and commits once, stepping back immediately from a limit', () => {
    const field = new TestNumberInput();
    const commits = [];
    const previews = [];
    vScrubNumber.mounted(field);
    field.addEventListener('input', () => previews.push(field.value));
    field.addEventListener('change', () => commits.push(field.value));

    field.pointer('pointerdown', 0);
    field.pointer('pointermove', 8);
    field.pointer('pointermove', 200, true);
    field.pointer('pointermove', 196);
    assert.deepEqual(previews, ['12.2', '45', '44.9']);
    assert.deepEqual(commits, []);

    field.pointer('pointerup', 196);
    assert.deepEqual(commits, ['44.9']);
    assert.equal(field.capture, null);
    vScrubNumber.beforeUnmount(field);
});

test('a click selects the number for typing and Escape restores a drag without committing', () => {
    const field = new TestNumberInput();
    let commits = 0;
    vScrubNumber.mounted(field);
    field.addEventListener('change', () => commits++);

    field.pointer('pointerdown', 100);
    field.pointer('pointerup', 101);
    assert.equal(field.selected, true);

    field.pointer('pointerdown', 100);
    field.pointer('pointermove', 80);
    assert.equal(field.value, '11.5');
    field.ownerDocument.dispatchEvent(Object.assign(new Event('keydown', { cancelable: true }), { key: 'Escape' }));
    field.pointer('pointerup', 80);
    assert.equal(field.value, '12');
    assert.equal(field.capture, null);
    assert.equal(commits, 0);
    vScrubNumber.beforeUnmount(field);
});
