<script setup lang="ts">
import SharedShiftEditor from '@shared/components/ShiftEditor.vue';
import { computed, ref } from 'vue';

defineOptions({ inheritAttrs: false });

const innerRef = ref<InstanceType<typeof SharedShiftEditor> | null>(null);
const editor = computed(() => innerRef.value?.editor ?? null);
const reset = () => innerRef.value?.reset?.();

const emit = defineEmits<{
    (e: 'send', payload: any): void;
    (e: 'update:modelValue', value: string): void;
    (e: 'uploading', value: boolean): void;
    (e: 'cancel'): void;
}>();

defineExpose({ editor, reset });
</script>

<template>
    <SharedShiftEditor
        ref="innerRef"
        v-bind="$attrs"
        @send="emit('send', $event)"
        @cancel="emit('cancel')"
        @update:modelValue="emit('update:modelValue', $event)"
        @uploading="emit('uploading', $event)"
    />
</template>
