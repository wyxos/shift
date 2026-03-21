<script setup lang="ts">
/* eslint-disable vue/no-mutating-props */
import { AlertDialog, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type EditForm = {
    id: number | null;
    name: string;
    errors: Record<string, string>;
    processing: boolean;
};

const { open, form } = defineProps<{
    open: boolean;
    form: EditForm;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    cancel: [];
    submit: [];
}>();
</script>

<template>
    <AlertDialog :open="open" @update:open="emit('update:open', $event)">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>Edit Organisation</AlertDialogTitle>
                <AlertDialogDescription>Update organisation information.</AlertDialogDescription>
            </AlertDialogHeader>

            <div class="space-y-4">
                <div class="space-y-2">
                    <Label>Name</Label>
                    <Input v-model="form.name" data-testid="edit-organisation-name" placeholder="Organisation name" />
                </div>

                <div v-for="(error, key) in form.errors" :key="key" class="text-destructive text-sm">{{ error }}</div>
            </div>

            <AlertDialogFooter>
                <AlertDialogCancel @click="emit('update:open', false); emit('cancel')">Cancel</AlertDialogCancel>
                <Button data-testid="submit-edit-organisation" :disabled="form.processing" @click="emit('submit')">Save</Button>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
