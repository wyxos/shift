<script setup lang="ts" generic="T extends string | string[] = string">
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
        modelValue?: T;
        options: Option[];
        multiple?: boolean;
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
    'update:modelValue': [value: T];
}>();

function isSelected(option: Option): boolean {
    return Array.isArray(props.modelValue) ? props.modelValue.includes(option.value) : props.modelValue === option.value;
}

function selectOption(option: Option): void {
    if (!props.multiple) {
        emit('update:modelValue', option.value as T);
        return;
    }

    const selectedValues = Array.isArray(props.modelValue) ? props.modelValue : [];
    emit(
        'update:modelValue',
        (isSelected(option) ? selectedValues.filter((value) => value !== option.value) : [...selectedValues, option.value]) as T,
    );
}

function optionButtonClass(option: Option): string {
    const selected = isSelected(option);
    if (selected) return option.selectedClass ?? '';
    return option.unselectedClass ?? '';
}

function optionButtonVariant(option: Option): 'default' | 'outline' {
    if (option.selectedClass || option.unselectedClass) {
        return 'outline';
    }
    return isSelected(option) ? 'default' : 'outline';
}

function optionTabIndex(option: Option, index: number): number {
    if (props.multiple) return 0;
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

    if (!props.multiple) {
        emit('update:modelValue', nextOption.value as T);
    }
    const buttons = (event.currentTarget as HTMLElement).parentElement?.querySelectorAll<HTMLElement>('button');
    buttons?.[nextIndex]?.focus();
}
</script>

<template>
    <div :role="multiple ? 'group' : 'radiogroup'" :aria-label="ariaLabel" :class="cn('flex flex-wrap items-center gap-2', props.class)">
        <Button
            v-for="(option, index) in options"
            :key="option.value"
            :role="multiple ? undefined : 'radio'"
            type="button"
            size="sm"
            :disabled="disabled"
            :aria-checked="multiple ? undefined : isSelected(option)"
            :aria-pressed="multiple ? isSelected(option) : undefined"
            :tabindex="optionTabIndex(option, index)"
            :variant="optionButtonVariant(option)"
            :class="cn('w-auto flex-none', optionButtonClass(option))"
            :data-testid="testIdPrefix ? `${testIdPrefix}-${option.testId ?? option.value}` : undefined"
            @click="selectOption(option)"
            @keydown="handleKeydown($event, index)"
        >
            {{ option.label }}
        </Button>
    </div>
</template>
