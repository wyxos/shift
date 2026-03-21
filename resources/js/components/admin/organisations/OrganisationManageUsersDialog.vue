<script setup lang="ts">
import { AlertDialog, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Trash2 } from 'lucide-vue-next';

type OrganisationUser = {
    id: number;
    user_name: string;
    user_email: string;
};

type ManageUsersForm = {
    organisation_name: string;
    users: OrganisationUser[];
    errors: Record<string, string>;
};

const { open, form } = defineProps<{
    open: boolean;
    form: ManageUsersForm;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    cancel: [];
    'remove-access': [organisationUser: OrganisationUser];
}>();
</script>

<template>
    <AlertDialog :open="open" @update:open="emit('update:open', $event)">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>Manage Organisation Access</AlertDialogTitle>
                <AlertDialogDescription>Users with access to {{ form.organisation_name }}</AlertDialogDescription>
            </AlertDialogHeader>

            <div class="flex max-h-96 flex-col gap-3 overflow-y-auto">
                <div v-for="(error, key) in form.errors" :key="key" class="text-destructive text-sm">{{ error }}</div>

                <div v-if="form.users.length === 0" class="text-muted-foreground py-6 text-center text-sm">
                    No users have access to this organisation.
                </div>

                <div v-for="user in form.users" v-else :key="user.id" class="flex items-center justify-between rounded-lg border px-3 py-3">
                    <div class="min-w-0">
                        <div class="truncate font-medium">{{ user.user_name }}</div>
                        <div class="text-muted-foreground truncate text-sm">{{ user.user_email }}</div>
                    </div>
                    <Button size="sm" variant="destructive" :data-testid="`organisation-remove-access-${user.id}`" @click="emit('remove-access', user)">
                        <Trash2 class="mr-2 h-4 w-4" />
                        Remove
                    </Button>
                </div>
            </div>

            <AlertDialogFooter>
                <AlertDialogCancel @click="emit('update:open', false); emit('cancel')">Close</AlertDialogCancel>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
