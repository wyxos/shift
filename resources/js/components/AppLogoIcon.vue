<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<template>
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40" :class="className" v-bind="$attrs">
        <defs>
            <linearGradient id="shift-blue" x1="0" y1="0" x2="40" y2="0" gradientUnits="userSpaceOnUse">
                <stop offset="0" stop-color="#0B1D4D" />
                <stop offset="0.5" stop-color="#123B8C" />
                <stop offset="1" stop-color="#1B4DB5" />
            </linearGradient>
        </defs>
        <g fill="url(#shift-blue)" transform="translate(0 5) scale(0.25)">
            <path d="M32 24h86l-10 12H22z" />
            <path d="M40 42h64l-8 6H32z" />
            <path d="M24 54h98l-12 12H12z" />
            <path d="M44 72h68l-8 6H36z" />
            <path d="M14 84h110l-14 12H0z" />
        </g>
    </svg>
</template>
