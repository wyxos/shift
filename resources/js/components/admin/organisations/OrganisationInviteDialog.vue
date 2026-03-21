<script setup lang="ts">
/* eslint-disable vue/no-mutating-props */
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/components/ui/alert-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type InviteForm = {
    organisation_name: string;
    email: string;
    name: string;
    errors: Record<string, string>;
    processing: boolean;
};

const { open, form } = defineProps<{
    open: boolean;
    form: InviteForm;
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
                <AlertDialogTitle>Invite User to Organisation</AlertDialogTitle>
                <AlertDialogDescription>Invite a user to join {{ form.organisation_name }}</AlertDialogDescription>
            </AlertDialogHeader>

            <div class="space-y-4">
                <div class="space-y-2">
                    <Label>Email</Label>
                    <Input v-model="form.email" data-testid="invite-organisation-email" type="email" placeholder="user@example.com" />
                    <div v-if="form.errors.email" class="text-destructive text-sm">{{ form.errors.email }}</div>
                </div>

                <div class="space-y-2">
                    <Label>Name</Label>
                    <Input v-model="form.name" data-testid="invite-organisation-name" placeholder="User name" />
                    <div v-if="form.errors.name" class="text-destructive text-sm">{{ form.errors.name }}</div>
                </div>

                <div v-for="(error, key) in form.errors" :key="key" class="text-destructive text-sm">{{ error }}</div>
            </div>

            <AlertDialogFooter>
                <AlertDialogCancel @click="emit('update:open', false); emit('cancel')">Cancel</AlertDialogCancel>
                <AlertDialogAction data-testid="submit-invite-organisation" :disabled="form.processing" @click="emit('submit')">Invite</AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
