<script setup lang="ts">
/* eslint-disable vue/no-mutating-props */
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import RequestButton from '@/shared/components/RequestButton.vue';

type EditForm = {
    id: number | null;
    name: string;
    errors: Record<string, string>;
    processing: boolean;
};

const { open, form, otherErrors, disabled } = defineProps<{
    open: boolean;
    form: EditForm;
    otherErrors: Record<string, string>;
    disabled: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    cancel: [];
    submit: [];
}>();

function updateOpen(value: boolean) {
    if (!value && form.processing) return;

    emit('update:open', value);

    if (!value) {
        emit('cancel');
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="updateOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Edit Project</DialogTitle>
                <DialogDescription>Update the project name.</DialogDescription>
            </DialogHeader>

            <div class="space-y-4">
                <div class="space-y-2">
                    <Label for="edit-project-name">Project name</Label>
                    <Input id="edit-project-name" v-model="form.name" data-testid="edit-project-name" placeholder="Portal refresh" />
                    <p v-if="form.errors.name" class="text-sm text-red-500">{{ form.errors.name }}</p>
                </div>

                <p v-for="(error, key) in otherErrors" :key="key" class="text-sm text-red-500">{{ error }}</p>
            </div>

            <DialogFooter>
                <Button type="button" variant="outline" :disabled="form.processing" @click="updateOpen(false)">Cancel</Button>
                <RequestButton
                    type="button"
                    :disabled="disabled"
                    :loading="form.processing"
                    loading-label="Saving..."
                    data-testid="edit-project-submit"
                    @click="emit('submit')"
                >
                    Save
                </RequestButton>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
