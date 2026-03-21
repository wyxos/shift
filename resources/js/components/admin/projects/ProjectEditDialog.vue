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
</script>

<template>
    <AlertDialog :open="open" @update:open="emit('update:open', $event)">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>Edit Project</AlertDialogTitle>
                <AlertDialogDescription>Update the project name.</AlertDialogDescription>
            </AlertDialogHeader>

            <div class="space-y-4">
                <div class="space-y-2">
                    <Label for="edit-project-name">Project name</Label>
                    <Input id="edit-project-name" v-model="form.name" data-testid="edit-project-name" placeholder="Portal refresh" />
                    <p v-if="form.errors.name" class="text-sm text-red-500">{{ form.errors.name }}</p>
                </div>

                <p v-for="(error, key) in otherErrors" :key="key" class="text-sm text-red-500">{{ error }}</p>
            </div>

            <AlertDialogFooter>
                <AlertDialogCancel type="button" @click="emit('update:open', false); emit('cancel')">Cancel</AlertDialogCancel>
                <Button type="button" :disabled="disabled" data-testid="edit-project-submit" @click="emit('submit')">Save</Button>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
