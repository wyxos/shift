<script setup lang="ts">
/* eslint-disable vue/no-mutating-props */
import { AlertDialog, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type GrantAccessForm = {
    project_id: number | null;
    project_name: string;
    email: string;
    name: string;
    errors: Record<string, string>;
    processing: boolean;
};

const { open, form, disabled } = defineProps<{
    open: boolean;
    form: GrantAccessForm;
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
                <AlertDialogTitle>Grant Project Access</AlertDialogTitle>
                <AlertDialogDescription>Grant a user access to {{ form.project_name }}</AlertDialogDescription>
            </AlertDialogHeader>

            <div class="space-y-4">
                <div class="space-y-2">
                    <Label for="grant-project-email">Email</Label>
                    <Input id="grant-project-email" v-model="form.email" data-testid="grant-project-email" placeholder="user@example.com" />
                </div>
                <div class="space-y-2">
                    <Label for="grant-project-name">Name</Label>
                    <Input id="grant-project-name" v-model="form.name" data-testid="grant-project-name" placeholder="Pat Doe" />
                </div>

                <p v-for="(error, key) in form.errors" :key="key" class="text-sm text-red-500">{{ error }}</p>
            </div>

            <AlertDialogFooter>
                <AlertDialogCancel type="button" @click="emit('update:open', false); emit('cancel')">Cancel</AlertDialogCancel>
                <Button type="button" :disabled="disabled" data-testid="grant-project-submit" @click="emit('submit')">Grant Access</Button>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
