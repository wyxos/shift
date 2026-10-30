<script setup lang="ts">
import { ButtonGroup } from '@/components/ui/button-group';

defineProps<{ modelValue?: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const options = [
    { value: '', label: 'All errors', testId: 'all' },
    { value: 'application', label: 'Application', testId: 'application' },
    { value: 'shift_sdk', label: 'SDK errors', testId: 'sdk' },
];
</script>

<template>
    <ButtonGroup
        :model-value="modelValue ?? ''"
        :options="options"
        aria-label="Error component"
        test-id-prefix="error-component"
        touch-friendly
        @update:model-value="emit('update:modelValue', $event)"
    />
</template>
