<script setup lang="ts">
/* eslint-disable vue/no-mutating-props */
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import RequestButton from '@/shared/components/RequestButton.vue';

type CreateForm = {
    name: string;
    errors: Record<string, string>;
    processing: boolean;
};

const { open, form } = defineProps<{
    open: boolean;
    form: CreateForm;
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
                <DialogTitle>Create Organisation</DialogTitle>
                <DialogDescription>Add a new organisation.</DialogDescription>
            </DialogHeader>

            <div class="space-y-4">
                <div class="space-y-2">
                    <Label>Name</Label>
                    <Input v-model="form.name" data-testid="create-organisation-name" placeholder="Organisation name" />
                </div>

                <div v-for="(error, key) in form.errors" :key="key" class="text-destructive text-sm">{{ error }}</div>
            </div>

            <DialogFooter>
                <Button type="button" variant="outline" :disabled="form.processing" @click="updateOpen(false)">Cancel</Button>
                <RequestButton
                    data-testid="submit-create-organisation"
                    :loading="form.processing"
                    loading-label="Creating..."
                    @click="emit('submit')"
                >
                    Create
                </RequestButton>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
