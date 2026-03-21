<script setup lang="ts">
/* eslint-disable vue/no-mutating-props */
import { AlertDialog, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
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
</script>

<template>
    <AlertDialog :open="open" @update:open="emit('update:open', $event)">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>Create Organisation</AlertDialogTitle>
                <AlertDialogDescription>Add a new organisation.</AlertDialogDescription>
            </AlertDialogHeader>

            <div class="space-y-4">
                <div class="space-y-2">
                    <Label>Name</Label>
                    <Input v-model="form.name" data-testid="create-organisation-name" placeholder="Organisation name" />
                </div>

                <div v-for="(error, key) in form.errors" :key="key" class="text-destructive text-sm">{{ error }}</div>
            </div>

            <AlertDialogFooter>
                <AlertDialogCancel @click="emit('update:open', false); emit('cancel')">Cancel</AlertDialogCancel>
                <Button data-testid="submit-create-organisation" :disabled="form.processing" @click="emit('submit')">Create</Button>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
