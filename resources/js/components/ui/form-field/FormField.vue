<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { computed, type HTMLAttributes } from 'vue';

const props = defineProps<{
    class?: HTMLAttributes['class'];
    error?: string;
    id: string;
    label: string;
}>();

const errorId = computed(() => `${props.id}-error`);
const controlAttrs = computed(() => ({
    id: props.id,
    'aria-describedby': props.error ? errorId.value : undefined,
    'aria-invalid': Boolean(props.error),
}));
</script>

<template>
    <div :class="cn('grid gap-2', props.class)" data-slot="form-field">
        <div class="flex items-center justify-between gap-3">
            <Label :for="id">
                <slot name="label">{{ label }}</slot>
            </Label>
            <slot name="label-action" />
        </div>
        <slot :control-attrs="controlAttrs" />
        <InputError :id="errorId" :message="error" />
    </div>
</template>
