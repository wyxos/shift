<script setup lang="ts">
import SharedShiftEditor from '@shared/components/ShiftEditor.vue';
import { computed, ref } from 'vue';

defineOptions({ inheritAttrs: false });

const innerRef = ref<InstanceType<typeof SharedShiftEditor> | null>(null);
const editor = computed(() => innerRef.value?.editor ?? null);
const reset = () => innerRef.value?.reset?.();
const confirmMentionAddition = (candidate: any) => innerRef.value?.confirmMentionAddition?.(candidate);

const emit = defineEmits<{
    (e: 'send', payload: any): void;
    (e: 'update:modelValue', value: string): void;
    (e: 'uploading', value: boolean): void;
    (e: 'cancel'): void;
    (e: 'mention-query', value: string): void;
    (e: 'mention-add-request', candidate: any): void;
    (e: 'slash-command', command: string): void;
}>();

defineExpose({ confirmMentionAddition, editor, reset });
</script>

<template>
    <SharedShiftEditor
        ref="innerRef"
        v-bind="$attrs"
        @send="emit('send', $event)"
        @cancel="emit('cancel')"
        @mention-add-request="emit('mention-add-request', $event)"
        @mention-query="emit('mention-query', $event)"
        @slash-command="emit('slash-command', $event)"
        @update:modelValue="emit('update:modelValue', $event)"
        @uploading="emit('uploading', $event)"
    >
        <template #before-send>
            <slot name="before-send" />
        </template>
    </SharedShiftEditor>
</template>
