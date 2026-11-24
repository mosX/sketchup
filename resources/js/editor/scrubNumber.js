const pixelsPerStep = 4;
const bindings = new WeakMap();

const numericBound = (attribute, fallback) => attribute === '' ? fallback : Number(attribute);

export const vScrubNumber = {
    mounted(input) {
        const document = input.ownerDocument;
        const listeners = new AbortController();
        const options = { signal: listeners.signal };
        let gesture;

        const notifyValue = () => {
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('scrub-value'));
        };

        const finish = (commit) => {
            const completed = gesture;
            gesture = null;
            document.removeEventListener('keydown', onKeyDown, true);
            input.removeAttribute('data-scrubbing');

            if (input.hasPointerCapture(completed.pointerId)) {
                input.releasePointerCapture(completed.pointerId);
            }

            if (!completed.dragging) {
                if (commit) {
                    input.focus();
                    input.select();
                }
                return;
            }

            if (input.value === completed.initialValue) return;

            if (commit) {
                input.dispatchEvent(new Event('change', { bubbles: true }));
            } else {
                input.value = completed.initialValue;
                notifyValue();
            }
        };

        function onKeyDown(event) {
            if (event.key !== 'Escape') return;

            event.preventDefault();
            event.stopImmediatePropagation();
            finish(false);
        }

        input.addEventListener('pointerdown', (event) => {
            if (gesture || input.disabled || input.readOnly || event.button !== 0 || event.pointerType !== 'mouse') return;

            event.preventDefault();
            input.setPointerCapture(event.pointerId);
            gesture = {
                pointerId: event.pointerId,
                lastX: event.clientX,
                initialValue: input.value,
                dragging: false,
            };
            document.addEventListener('keydown', onKeyDown, true);
        }, options);

        input.addEventListener('pointermove', (event) => {
            if (gesture?.pointerId !== event.pointerId) return;

            const steps = Math.trunc((event.clientX - gesture.lastX) / pixelsPerStep);
            if (!steps) return;

            if (!gesture.dragging) {
                document.activeElement?.blur();
                gesture.initialValue = input.value;
                if (!input.dispatchEvent(new Event('scrub-start', { cancelable: true }))) {
                    finish(false);
                    return;
                }
                gesture.dragging = true;
                input.setAttribute('data-scrubbing', '');
            }

            gesture.lastX += steps * pixelsPerStep;
            const step = Number(input.step) || 1;
            const current = input.valueAsNumber || 0;
            const next = current + steps * step * (event.shiftKey ? 10 : 1);
            const min = numericBound(input.min, -Infinity);
            const max = numericBound(input.max, Infinity);
            const value = String(Math.min(max, Math.max(min, Number(next.toFixed(6)))));

            if (input.value !== value) {
                input.value = value;
                notifyValue();
            }
        }, options);

        input.addEventListener('pointerup', (event) => {
            if (gesture?.pointerId === event.pointerId) finish(true);
        }, options);

        const cancel = (event) => {
            if (gesture?.pointerId === event.pointerId) finish(false);
        };
        input.addEventListener('pointercancel', cancel, options);
        input.addEventListener('lostpointercapture', cancel, options);

        input.setAttribute('data-scrub-number', '');
        input.title ||= 'Потяните влево или вправо · Shift — быстрее · Клик — ввод числа';
        bindings.set(input, () => {
            document.removeEventListener('keydown', onKeyDown, true);
            listeners.abort();
        });
    },

    beforeUnmount(input) {
        bindings.get(input)?.();
        bindings.delete(input);
    },
};
