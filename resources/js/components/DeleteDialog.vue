<script setup lang="ts">
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
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
</script>

<template>
    <!-- Delete Modal -->
    <AlertDialog v-model:open="open">
        <AlertDialogTrigger as-child>
            <div></div>
        </AlertDialogTrigger>
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>
                    <slot name="title"> Delete Record </slot>
                </AlertDialogTitle>
                <AlertDialogDescription>
                    <slot name="description"> Are you sure you want to delete this record? This action cannot be undone. </slot>
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel @click="emits('cancel')">
                    <slot name="cancel"> Cancel </slot>
                </AlertDialogCancel>
                <AlertDialogAction @click="emits('confirm')" class="bg-red-500">
                    <slot name="confirm"> Delete </slot>
                </AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
