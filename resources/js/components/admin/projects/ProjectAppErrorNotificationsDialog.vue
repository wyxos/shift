<script setup lang="ts">
/* eslint-disable vue/no-mutating-props */
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import RequestButton from '@/shared/components/RequestButton.vue';
import { BellRing } from 'lucide-vue-next';
import { computed } from 'vue';
import { type ProjectAppErrorNotificationUser } from './project-shared';

type AppErrorNotificationSettingsForm = {
    project_name: string;
    selected_user_ids: number[];
    users: ProjectAppErrorNotificationUser[];
};

const {
    open,
    form,
    loading,
    loaded = false,
    error,
} = defineProps<{
    open: boolean;
    form: AppErrorNotificationSettingsForm;
    loading: boolean;
    loaded?: boolean;
    error: string | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    cancel: [];
    save: [];
}>();

const selectedCount = computed(() => form.selected_user_ids.length);

function updateOpen(value: boolean) {
    if (!value && loading) return;

    emit('update:open', value);

    if (!value) {
        emit('cancel');
    }
}

function isSelected(userId: number) {
    return form.selected_user_ids.includes(userId);
}

function toggleUser(userId: number, selected: boolean) {
    if (selected) {
        form.selected_user_ids = [...new Set([...form.selected_user_ids, userId])];

        return;
    }

    form.selected_user_ids = form.selected_user_ids.filter((id) => id !== userId);
}
</script>

<template>
    <Dialog :open="open" @update:open="updateOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>App Error Notifications</DialogTitle>
                <DialogDescription>Choose recipients for {{ form.project_name }}</DialogDescription>
            </DialogHeader>

            <div class="space-y-3">
                <p v-if="loading && !form.users.length" class="text-muted-foreground text-sm">Loading users...</p>

                <div v-else-if="form.users.length" class="max-h-96 space-y-2 overflow-y-auto pr-1">
                    <label
                        v-for="user in form.users"
                        :key="user.id"
                        class="hover:bg-muted/40 flex items-start gap-3 rounded-lg border p-3 text-sm"
                        :data-testid="`project-app-error-notification-user-${user.id}`"
                    >
                        <Checkbox
                            :disabled="loading"
                            :model-value="isSelected(user.id)"
                            :data-testid="`project-app-error-notification-user-${user.id}-checkbox`"
                            @update:model-value="toggleUser(user.id, Boolean($event))"
                        />
                        <span class="grid min-w-0 gap-1">
                            <span class="truncate font-medium">{{ user.name || user.email }}</span>
                            <span v-if="user.name" class="text-muted-foreground truncate">{{ user.email }}</span>
                        </span>
                    </label>
                </div>

                <div v-else class="text-muted-foreground rounded-lg border p-3 text-sm" data-testid="project-app-error-notifications-empty">
                    No eligible users found.
                </div>

                <p class="text-muted-foreground text-xs" data-testid="project-app-error-notifications-count">{{ selectedCount }} selected</p>

                <p v-if="error" class="text-sm text-red-500">{{ error }}</p>
            </div>

            <DialogFooter>
                <Button type="button" variant="outline" :disabled="loading" @click="updateOpen(false)">Cancel</Button>
                <RequestButton
                    type="button"
                    :disabled="!loaded"
                    :loading="loading"
                    loading-label="Saving..."
                    data-testid="save-app-error-notifications"
                    @click="emit('save')"
                >
                    <BellRing class="mr-2 h-4 w-4" />
                    Save
                </RequestButton>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
