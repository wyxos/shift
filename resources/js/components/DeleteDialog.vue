<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import RequestButton from '@shared/components/RequestButton.vue';
import { ref, watch } from 'vue';

const props = defineProps({
    isOpen: {
        type: Boolean,
        required: true,
    },
    loading: {
        type: Boolean,
        default: false,
    },
    loadingLabel: {
        type: String,
        default: 'Deleting...',
    },
    error: {
        type: String,
        default: null,
    },
});

const emits = defineEmits(['cancel', 'confirm']);

// Create a local copy
const open = ref(props.isOpen);

// Watch the prop and sync it
watch(
    () => props.isOpen,
    (newVal) => {
        open.value = newVal;
    },
);

function updateOpen(value: boolean) {
    if (props.loading && !value) return;

    open.value = value;

    if (!value) {
        emits('cancel');
    }
}
</script>

<template>
    <!-- Delete Modal -->
    <Dialog :open="open" @update:open="updateOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>
                    <slot name="title"> Delete Record </slot>
                </DialogTitle>
                <DialogDescription>
                    <slot name="description"> Are you sure you want to delete this record? This action cannot be undone. </slot>
                </DialogDescription>
                <p v-if="error" class="text-destructive text-sm" data-testid="delete-dialog-error">{{ error }}</p>
            </DialogHeader>
            <DialogFooter>
                <Button type="button" variant="outline" :disabled="loading" @click="updateOpen(false)">
                    <slot name="cancel"> Cancel </slot>
                </Button>
                <RequestButton type="button" variant="destructive" :loading="loading" :loading-label="loadingLabel" @click="emits('confirm')">
                    <slot name="confirm"> Delete </slot>
                </RequestButton>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
