<script setup lang="ts">
/* eslint-disable vue/no-mutating-props */
import AccessUserPicker from '@/components/admin/AccessUserPicker.vue';
import {
    accessStatusBadgeClass,
    accessStatusLabel,
    accessUserDisplayName,
    type AccessUserCandidate,
    type ManagedAccessUser,
} from '@/components/admin/access-users';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Trash2 } from 'lucide-vue-next';

type ManageUsersForm = {
    organisation_name: string;
    users: ManagedAccessUser[];
};

type AccessForm = {
    email: string;
    name: string;
    errors: Record<string, string>;
    processing: boolean;
};

const { open, form, accessForm, accessUsers, accessDisabled, loading, error } = defineProps<{
    accessDisabled: boolean;
    accessForm: AccessForm;
    accessUsers: AccessUserCandidate[];
    error: string | null;
    form: ManageUsersForm;
    loading: boolean;
    open: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    cancel: [];
    'remove-access': [organisationUser: ManagedAccessUser];
    submitAccess: [];
}>();
</script>

<template>
    <AlertDialog :open="open" @update:open="emit('update:open', $event)">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>Manage Organisation Access</AlertDialogTitle>
                <AlertDialogDescription>Users with access to {{ form.organisation_name }}</AlertDialogDescription>
            </AlertDialogHeader>

            <AccessUserPicker
                :candidates="accessUsers"
                :disabled="accessDisabled"
                :email="accessForm.email"
                :errors="accessForm.errors"
                :name="accessForm.name"
                :processing="accessForm.processing"
                submit-label="Add"
                test-id-prefix="organisation-access"
                @submit="emit('submitAccess')"
                @update:email="accessForm.email = $event"
                @update:name="accessForm.name = $event"
            />

            <div class="max-h-96 space-y-4 overflow-y-auto pr-1">
                <p v-if="loading" class="text-muted-foreground text-sm">Loading organisation users…</p>
                <p v-else-if="error" class="text-sm text-red-500">{{ error }}</p>
                <p v-else-if="form.users.length === 0" class="text-muted-foreground text-sm">No users have access to this organisation.</p>
                <div v-for="user in form.users" v-else :key="user.id" class="flex items-start justify-between gap-4 rounded-lg border p-3">
                    <div class="space-y-1">
                        <div class="font-medium">{{ accessUserDisplayName(user) }}</div>
                        <Badge :class="accessStatusBadgeClass(user)" variant="secondary">{{ accessStatusLabel(user) }}</Badge>
                    </div>
                    <Button
                        size="icon"
                        title="Remove access"
                        variant="destructive"
                        :data-testid="`organisation-remove-access-${user.id}`"
                        @click="emit('remove-access', user)"
                    >
                        <Trash2 class="h-4 w-4" />
                        <span class="sr-only">Remove access</span>
                    </Button>
                </div>
            </div>

            <AlertDialogFooter>
                <AlertDialogCancel
                    @click="
                        emit('update:open', false);
                        emit('cancel');
                    "
                    >Close</AlertDialogCancel
                >
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
