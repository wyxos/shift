<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ref, watch } from 'vue';

const props = defineProps({
    isOpen: {
        type: Boolean,
        required: true,
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
            </DialogHeader>
            <DialogFooter>
                <Button type="button" variant="outline" @click="updateOpen(false)">
                    <slot name="cancel"> Cancel </slot>
                </Button>
                <Button type="button" variant="destructive" class="bg-red-500" @click="emits('confirm')">
                    <slot name="confirm"> Delete </slot>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
