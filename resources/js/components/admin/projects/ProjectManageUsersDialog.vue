<script setup lang="ts">
/* eslint-disable vue/no-mutating-props */
import AccessUserPicker from '@/components/admin/AccessUserPicker.vue';
import { accessStatusBadgeClass, accessStatusLabel, accessUserDisplayName, type AccessUserCandidate } from '@/components/admin/access-users';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Trash2 } from 'lucide-vue-next';
import { type ProjectAccessUser } from './project-shared';

type ManageUsersForm = {
    project_name: string;
    users: ProjectAccessUser[];
};

type AccessForm = {
    email: string;
    name: string;
    errors: Record<string, string>;
    processing: boolean;
};

const { open, form, loading, error, accessForm, accessUsers, accessDisabled } = defineProps<{
    accessDisabled: boolean;
    accessForm: AccessForm;
    accessUsers: AccessUserCandidate[];
    open: boolean;
    form: ManageUsersForm;
    loading: boolean;
    error: string | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    cancel: [];
    'remove-access': [projectUser: ProjectAccessUser];
    submitAccess: [];
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
                <DialogTitle>Manage Project Access</DialogTitle>
                <DialogDescription>Users with access to {{ form.project_name }}</DialogDescription>
            </DialogHeader>

            <AccessUserPicker
                :candidates="accessUsers"
                :disabled="accessDisabled"
                :email="accessForm.email"
                :errors="accessForm.errors"
                :name="accessForm.name"
                :processing="accessForm.processing"
                submit-label="Add"
                test-id-prefix="project-access"
                @submit="emit('submitAccess')"
                @update:email="accessForm.email = $event"
                @update:name="accessForm.name = $event"
            />

            <div class="max-h-96 space-y-4 overflow-y-auto pr-1">
                <p v-if="loading" class="text-muted-foreground text-sm">Loading project users…</p>
                <p v-else-if="error" class="text-sm text-red-500">{{ error }}</p>
                <p v-else-if="form.users.length === 0" class="text-muted-foreground text-sm">No users have access to this project.</p>
                <div
                    v-else
                    v-for="projectUser in form.users"
                    :key="projectUser.id"
                    class="flex items-start justify-between gap-4 rounded-lg border p-3"
                >
                    <div class="space-y-1">
                        <div class="font-medium">{{ accessUserDisplayName(projectUser) }}</div>
                        <Badge :class="accessStatusBadgeClass(projectUser)" variant="secondary">{{ accessStatusLabel(projectUser) }}</Badge>
                    </div>
                    <Button
                        type="button"
                        variant="destructive"
                        size="icon"
                        title="Remove access"
                        :data-testid="`project-remove-access-${projectUser.id}`"
                        @click="emit('remove-access', projectUser)"
                    >
                        <Trash2 class="h-4 w-4" />
                        <span class="sr-only">Remove access</span>
                    </Button>
                </div>
            </div>

            <DialogFooter>
                <Button type="button" variant="ghost" @click="updateOpen(false)">Close</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
