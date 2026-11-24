<template>
    <button
        class="inspector-action"
        type="button"
        :disabled="disabled"
        :aria-label="label"
        :aria-pressed="pressed"
        :title="hint || label"
    >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path :d="icons[icon]" />
        </svg>
        <span v-if="caption">{{ caption }}</span>
    </button>
</template>

<script setup>
defineProps({
    icon: { type: String, required: true },
    label: { type: String, required: true },
    caption: { type: String, default: '' },
    hint: { type: String, default: '' },
    disabled: Boolean,
    pressed: { type: Boolean, default: undefined },
});

const icons = {
    edit: 'M4 20l4.5-1 11-11a2.1 2.1 0 0 0-3-3l-11 11L4 20zM14.5 7.5l3 3',
    copy: 'M8 8h12v12H8zM16 8V4H4v12h4',
    mirror: 'M12 3v18M9 6v12H3L9 6zM15 6v12h6L15 6z',
    ghost: 'M12 3a9 9 0 1 1 0 18 9 9 0 0 1 0-18zM12 3v18M12 8l4-4M12 14l7-7M12 20l8-8',
    eye: 'M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6zM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z',
    eyeOff: 'M3 3l18 18M10 6.2a11 11 0 0 1 2-.2c6.5 0 10 6 10 6a15 15 0 0 1-3.2 3.5M6.2 7.7A16 16 0 0 0 2 12s3.5 6 10 6a11 11 0 0 0 4-.7',
    lock: 'M5 11h14v9H5zM8 11V8a4 4 0 0 1 8 0v3M12 15v2',
    isolate: 'M9 4H4v5m11-5h5v5m0 6v5h-5M4 15v5h5M9 9h6v6H9z',
    enter: 'M5 4h14v16H5M3 12h11m-4-4 4 4-4 4',
};
</script>
