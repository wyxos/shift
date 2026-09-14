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
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 132 132" :class="className" v-bind="$attrs" aria-hidden="true">
        <g transform="translate(8 23)">
            <path
                fill="#6798FF"
                d="M46 0H116L94 25H68Q65 25 63 27L48 42H12Q7 42 10 38L42 3Q44 0 46 0Z M0 86L34 59H49L65 43H104Q109 43 106 47L76 82Q72 86 68 86Z"
            />
        </g>
    </svg>
</template>
