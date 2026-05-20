<script setup lang="ts">
/* eslint-disable vue/no-mutating-props */
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

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
                <Button type="button" variant="outline" @click="updateOpen(false)">Cancel</Button>
                <Button data-testid="submit-create-organisation" :disabled="form.processing" @click="emit('submit')">Create</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
