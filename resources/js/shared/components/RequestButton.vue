<script setup lang="ts">
import type { ButtonVariants } from '@/components/ui/button';
import { Button } from '@/components/ui/button';
import { LoaderCircle } from 'lucide-vue-next';
import type { HTMLAttributes } from 'vue';

const props = withDefaults(
    defineProps<{
        class?: HTMLAttributes['class'];
        disabled?: boolean;
        loading?: boolean;
        loadingLabel?: string;
        size?: ButtonVariants['size'];
        type?: 'button' | 'submit' | 'reset';
        variant?: ButtonVariants['variant'];
    }>(),
    {
        class: undefined,
        disabled: false,
        loading: false,
        loadingLabel: undefined,
        size: undefined,
        type: 'button',
        variant: undefined,
    },
);
</script>

<template>
    <Button
        :aria-busy="loading ? 'true' : undefined"
        :class="props.class"
        :disabled="disabled || loading"
        :size="size"
        :type="type"
        :variant="variant"
    >
        <LoaderCircle v-if="loading" class="mr-2 h-4 w-4 animate-spin" aria-hidden="true" />
        <template v-if="loading && loadingLabel">{{ loadingLabel }}</template>
        <slot v-else />
    </Button>
</template>
