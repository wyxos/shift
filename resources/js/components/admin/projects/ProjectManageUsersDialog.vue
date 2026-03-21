<script setup lang="ts">
import { AlertDialog, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Trash2 } from 'lucide-vue-next';
import { accessStatusLabel, type ProjectAccessUser } from './project-shared';

type ManageUsersForm = {
    project_name: string;
    users: ProjectAccessUser[];
};

const { open, form, loading, error } = defineProps<{
    open: boolean;
    form: ManageUsersForm;
    loading: boolean;
    error: string | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    cancel: [];
    'remove-access': [projectUser: ProjectAccessUser];
}>();
</script>

<template>
    <AlertDialog :open="open" @update:open="emit('update:open', $event)">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>Manage Project Access</AlertDialogTitle>
                <AlertDialogDescription>Users with access to {{ form.project_name }}</AlertDialogDescription>
            </AlertDialogHeader>

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
                        <div class="font-medium">{{ projectUser.user_name || 'Unknown user' }}</div>
                        <div class="text-muted-foreground text-sm">{{ projectUser.user_email || 'No email' }}</div>
                        <Badge variant="secondary">{{ accessStatusLabel(projectUser) }}</Badge>
                    </div>
                    <Button
                        type="button"
                        variant="destructive"
                        size="sm"
                        :data-testid="`project-remove-access-${projectUser.id}`"
                        @click="emit('remove-access', projectUser)"
                    >
                        <Trash2 class="mr-2 h-4 w-4" />
                        Remove
                    </Button>
                </div>
            </div>

            <AlertDialogFooter>
                <AlertDialogCancel type="button" @click="emit('update:open', false); emit('cancel')">Close</AlertDialogCancel>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
