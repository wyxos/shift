<script setup lang="ts">
import { computed, type HTMLAttributes } from 'vue';
import { LoaderCircle } from 'lucide-vue-next';
import { Button } from '../../components/ui/button';
import type { ButtonVariants } from '../../components/ui/button';
import { cn } from '../../lib/utils';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    label: string;
    title?: string;
    variant?: ButtonVariants['variant'];
    class?: HTMLAttributes['class'];
    loading?: boolean;
    disabled?: boolean;
    asChild?: boolean;
    type?: 'button' | 'submit' | 'reset';
}

const props = withDefaults(defineProps<Props>(), {
    title: undefined,
    variant: 'outline',
    class: undefined,
    loading: false,
    disabled: false,
    asChild: false,
    type: 'button',
});

const buttonTitle = computed(() => props.title ?? props.label);
const buttonType = computed(() => (props.asChild ? undefined : props.type));
const toneClass = computed(() => {
    if (props.variant === 'destructive') {
        return 'border-destructive/20 bg-destructive/10 text-destructive shadow-none hover:bg-destructive/15 hover:text-destructive dark:border-destructive/30 dark:bg-destructive/20 dark:text-red-100 dark:hover:bg-destructive/25';
    }

    return 'border-border/70 bg-background/80 text-muted-foreground shadow-none hover:bg-accent/80 hover:text-foreground dark:border-border/80 dark:bg-background/70';
});
</script>

<template>
    <Button
        :variant="variant === 'destructive' ? 'destructive' : 'outline'"
        size="icon"
        :as-child="asChild"
        :type="buttonType"
        :title="buttonTitle"
        :aria-label="label"
        :aria-busy="loading || undefined"
        :disabled="disabled || loading"
        :class="cn('size-8 shrink-0 rounded-md border backdrop-blur-sm transition-colors', toneClass, props.class)"
        v-bind="$attrs"
    >
        <LoaderCircle v-if="loading" class="size-4 animate-spin" />
        <slot v-else />
        <span class="sr-only">{{ label }}</span>
    </Button>
</template>
