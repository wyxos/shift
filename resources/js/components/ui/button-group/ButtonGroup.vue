<script setup lang="ts">
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';

type Option = {
    value: string;
    label: string;
    selectedClass?: string;
    unselectedClass?: string;
    testId?: string;
};

const props = withDefaults(
    defineProps<{
        modelValue?: string;
        options: Option[];
        disabled?: boolean;
        columns?: 2 | 3 | 4;
        class?: string;
        testIdPrefix?: string;
        ariaLabel?: string;
    }>(),
    {
        columns: 3,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

function optionButtonClass(option: Option): string {
    const selected = props.modelValue === option.value;
    if (selected) return option.selectedClass ?? '';
    return option.unselectedClass ?? '';
}

function optionButtonVariant(option: Option): 'default' | 'outline' {
    if (option.selectedClass || option.unselectedClass) {
        return 'outline';
    }
    return props.modelValue === option.value ? 'default' : 'outline';
}

function optionTabIndex(option: Option, index: number): number {
    if (props.modelValue === option.value) return 0;
    if (!props.options.some((candidate) => candidate.value === props.modelValue) && index === 0) return 0;

    return -1;
}

function handleKeydown(event: KeyboardEvent, index: number): void {
    const direction = ['ArrowRight', 'ArrowDown'].includes(event.key) ? 1 : ['ArrowLeft', 'ArrowUp'].includes(event.key) ? -1 : 0;
    let nextIndex = index;

    if (direction !== 0) {
        nextIndex = (index + direction + props.options.length) % props.options.length;
    } else if (event.key === 'Home') {
        nextIndex = 0;
    } else if (event.key === 'End') {
        nextIndex = props.options.length - 1;
    } else {
        return;
    }

    event.preventDefault();
    const nextOption = props.options[nextIndex];
    if (!nextOption) return;

    emit('update:modelValue', nextOption.value);
    const buttons = (event.currentTarget as HTMLElement).parentElement?.querySelectorAll<HTMLElement>('[role="radio"]');
    buttons?.[nextIndex]?.focus();
}
</script>

<template>
    <div role="radiogroup" :aria-label="ariaLabel" :class="cn('flex flex-wrap items-center gap-2', props.class)">
        <Button
            v-for="(option, index) in options"
            :key="option.value"
            role="radio"
            type="button"
            size="sm"
            :disabled="disabled"
            :aria-checked="modelValue === option.value"
            :tabindex="optionTabIndex(option, index)"
            :variant="optionButtonVariant(option)"
            :class="cn('w-auto flex-none', optionButtonClass(option))"
            :data-testid="testIdPrefix ? `${testIdPrefix}-${option.testId ?? option.value}` : undefined"
            @click="emit('update:modelValue', option.value)"
            @keydown="handleKeydown($event, index)"
        >
            {{ option.label }}
        </Button>
    </div>
</template>
