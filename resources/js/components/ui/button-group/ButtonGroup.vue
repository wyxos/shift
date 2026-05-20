<script setup lang="ts">
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';

type Option = {
    value: string;
    label: string;
    selectedClass?: string;
    unselectedClass?: string;
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
</script>

<template>
    <div role="radiogroup" :aria-label="ariaLabel" :class="cn('flex flex-wrap items-center gap-2', props.class)">
        <Button
            v-for="option in options"
            :key="option.value"
            role="radio"
            type="button"
            size="sm"
            :disabled="disabled"
            :aria-checked="modelValue === option.value"
            :variant="optionButtonVariant(option)"
            :class="cn('w-auto flex-none', optionButtonClass(option))"
            :data-testid="testIdPrefix ? `${testIdPrefix}-${option.value}` : undefined"
            @click="emit('update:modelValue', option.value)"
        >
            {{ option.label }}
        </Button>
    </div>
</template>
