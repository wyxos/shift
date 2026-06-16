<script setup lang="ts">
import {
    AlertDialog,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import type { ButtonVariants } from '@/components/ui/button';
import { Button } from '@/components/ui/button';
import RequestButton from '@shared/components/RequestButton.vue';

const props = withDefaults(
    defineProps<{
        cancelLabel?: string;
        confirmLabel?: string;
        confirmTestId?: string;
        confirmVariant?: ButtonVariants['variant'];
        error?: string | null;
        loading?: boolean;
        loadingLabel?: string;
        open: boolean;
        title: string;
    }>(),
    {
        cancelLabel: 'Cancel',
        confirmLabel: 'Confirm',
        confirmTestId: undefined,
        confirmVariant: 'default',
        error: null,
        loading: false,
        loadingLabel: undefined,
    },
);

const emit = defineEmits<{
    'update:open': [open: boolean];
    cancel: [];
    confirm: [];
}>();

function updateOpen(open: boolean) {
    if (props.loading && !open) return;

    emit('update:open', open);
    if (!open) emit('cancel');
}

function cancel() {
    if (props.loading) return;

    emit('update:open', false);
    emit('cancel');
}
</script>

<template>
    <AlertDialog :open="open" @update:open="updateOpen">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>{{ title }}</AlertDialogTitle>
                <AlertDialogDescription>
                    <slot name="description">
                        <slot />
                    </slot>
                </AlertDialogDescription>
                <p v-if="error" class="text-destructive text-sm" data-testid="confirm-request-error">{{ error }}</p>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <Button type="button" variant="outline" :disabled="loading" @click="cancel">{{ cancelLabel }}</Button>
                <RequestButton
                    :data-testid="confirmTestId"
                    :loading="loading"
                    :loading-label="loadingLabel"
                    :variant="confirmVariant"
                    type="button"
                    @click="emit('confirm')"
                >
                    {{ confirmLabel }}
                </RequestButton>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
