<script setup lang="ts">
/* eslint-disable vue/no-mutating-props */
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import RequestButton from '@/shared/components/RequestButton.vue';
import { BellRing } from 'lucide-vue-next';
import { computed } from 'vue';
import { type ProjectAppErrorAlertRules, type ProjectAppErrorNotificationUser } from './project-shared';

type AppErrorNotificationSettingsForm = {
    project_name: string;
    selected_user_ids: number[];
    users: ProjectAppErrorNotificationUser[];
    settings: ProjectAppErrorAlertRules;
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
const cooldownInvalid = computed(
    () => !Number.isInteger(form.settings.quiet_hours) || form.settings.quiet_hours < 1 || form.settings.quiet_hours > 168,
);
const timezoneOptions = computed(() => {
    const zones = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : [];
    return [...new Set(['UTC', form.settings.daily_summary_timezone, ...zones])]
        .sort()
        .map((zone) => ({ value: zone, label: zone.replaceAll('_', ' ') }));
});

function updateOpen(value: boolean) {
    if (!value && loading) return;
    emit('update:open', value);
    if (!value) emit('cancel');
}

function toggleUser(userId: number, selected: boolean) {
    form.selected_user_ids = selected ? [...new Set([...form.selected_user_ids, userId])] : form.selected_user_ids.filter((id) => id !== userId);
}
</script>

<template>
    <Dialog :open="open" @update:open="updateOpen">
        <DialogContent
            class="grid max-h-[calc(100dvh-2rem)] grid-rows-[auto_minmax(0,1fr)_auto] gap-0 p-0 sm:max-w-xl"
            data-testid="project-error-alert-settings"
        >
            <DialogHeader class="border-b px-5 py-5 pr-12 sm:px-6">
                <DialogTitle>Error alerts</DialogTitle>
                <DialogDescription>{{ form.project_name }} · all environments</DialogDescription>
            </DialogHeader>

            <div class="shift-scrollbar min-h-0 overflow-y-auto px-5 py-5 sm:px-6" :aria-busy="loading">
                <p v-if="loading && !loaded" class="text-muted-foreground text-sm" role="status">Loading alert settings...</p>
                <div v-else-if="loaded" class="space-y-6">
                    <section class="space-y-4" aria-label="Notification rules">
                        <div>
                            <h3 class="text-sm font-medium">New and reopened errors</h3>
                            <p class="text-muted-foreground mt-1 text-sm">
                                Alert immediately when an error is first reported or a completed error returns.
                            </p>
                        </div>
                        <div class="space-y-3 border-t pt-4">
                            <label class="flex min-h-11 items-center justify-between gap-4 text-sm font-medium" for="error-recurrence-enabled">
                                Repeat alerts for open errors
                                <Checkbox
                                    id="error-recurrence-enabled"
                                    :disabled="loading"
                                    :model-value="form.settings.recurrence_enabled"
                                    data-testid="project-error-recurrence-enabled"
                                    @update:model-value="form.settings.recurrence_enabled = Boolean($event)"
                                />
                            </label>
                            <p class="text-muted-foreground text-sm">
                                Alert on the next occurrence after the cooldown ends. Repeated events do not restart the cooldown.
                            </p>
                            <div class="flex flex-wrap items-center gap-3">
                                <label class="text-sm" for="error-quiet-hours">Alert cooldown</label>
                                <Input
                                    id="error-quiet-hours"
                                    class="w-20"
                                    type="number"
                                    min="1"
                                    max="168"
                                    step="1"
                                    :disabled="loading || !form.settings.recurrence_enabled"
                                    :model-value="form.settings.quiet_hours"
                                    :aria-invalid="cooldownInvalid"
                                    aria-describedby="error-quiet-help"
                                    data-testid="project-error-quiet-hours"
                                    @update:model-value="form.settings.quiet_hours = Number($event)"
                                />
                                <span class="text-sm">{{ form.settings.quiet_hours === 1 ? 'hour' : 'hours' }}</span>
                            </div>
                            <p id="error-quiet-help" :class="cooldownInvalid ? 'text-destructive' : 'text-muted-foreground'" class="text-xs">
                                {{
                                    cooldownInvalid
                                        ? 'Choose a whole number between 1 and 168 hours.'
                                        : 'Default: 1 hour. Measured from the last alert for each error.'
                                }}
                            </p>
                        </div>
                        <div class="space-y-3 border-t pt-4">
                            <label class="flex min-h-11 items-center justify-between gap-4 text-sm font-medium" for="error-summary-enabled">
                                Daily summary
                                <Checkbox
                                    id="error-summary-enabled"
                                    :disabled="loading"
                                    :model-value="form.settings.daily_summary_enabled"
                                    data-testid="project-error-summary-enabled"
                                    @update:model-value="form.settings.daily_summary_enabled = Boolean($event)"
                                />
                            </label>
                            <p class="text-muted-foreground text-sm">One email with daily activity for unresolved errors. No email on quiet days.</p>
                            <div class="grid gap-3 sm:grid-cols-[8rem_minmax(0,1fr)]">
                                <div class="space-y-2">
                                    <label for="error-summary-time" class="text-sm">Send at</label>
                                    <Input
                                        id="error-summary-time"
                                        type="time"
                                        :disabled="loading || !form.settings.daily_summary_enabled"
                                        v-model="form.settings.daily_summary_time"
                                        data-testid="project-error-summary-time"
                                    />
                                </div>
                                <div class="min-w-0 space-y-2">
                                    <span id="error-summary-timezone-label" class="text-sm">Time zone</span>
                                    <Select
                                        :model-value="form.settings.daily_summary_timezone"
                                        :options="timezoneOptions"
                                        searchable
                                        :disabled="loading || !form.settings.daily_summary_enabled"
                                        aria-label="Daily summary time zone"
                                        search-placeholder="Search time zones..."
                                        test-id="project-error-summary-timezone"
                                        @update:model-value="form.settings.daily_summary_timezone = String($event)"
                                    />
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="space-y-3 border-t pt-4" aria-label="Alert recipients">
                        <h3 class="text-sm font-medium">Recipients</h3>
                        <p class="text-muted-foreground text-sm">Selected people receive immediate alerts and the daily summary.</p>
                        <div v-if="form.users.length" class="space-y-2">
                            <label
                                v-for="user in form.users"
                                :key="user.id"
                                class="hover:bg-muted/40 flex min-h-11 items-start gap-3 rounded-lg border p-3 text-sm transition-colors"
                                :data-testid="`project-app-error-notification-user-${user.id}`"
                            >
                                <Checkbox
                                    :disabled="loading"
                                    :model-value="form.selected_user_ids.includes(user.id)"
                                    :data-testid="`project-app-error-notification-user-${user.id}-checkbox`"
                                    @update:model-value="toggleUser(user.id, Boolean($event))"
                                />
                                <span class="grid min-w-0 gap-1">
                                    <span class="font-medium break-words">{{ user.name || user.email }}</span>
                                    <span v-if="user.name" class="text-muted-foreground break-all">{{ user.email }}</span>
                                </span>
                            </label>
                        </div>
                        <p v-else class="text-muted-foreground text-sm" data-testid="project-app-error-notifications-empty">
                            No eligible users found.
                        </p>
                        <p class="text-muted-foreground text-xs" data-testid="project-app-error-notifications-count">
                            {{ selectedCount }} selected{{ selectedCount === 0 ? ' · no alerts will be sent' : '' }}
                        </p>
                    </section>
                </div>
                <p v-if="error" class="text-destructive mt-4 text-sm" role="alert">{{ error }}</p>
            </div>

            <DialogFooter class="border-t px-5 py-4 sm:px-6">
                <Button type="button" variant="outline" :disabled="loading" @click="updateOpen(false)">Cancel</Button>
                <RequestButton
                    type="button"
                    :disabled="!loaded || cooldownInvalid"
                    :loading="loading"
                    loading-label="Saving..."
                    data-testid="save-app-error-notifications"
                    @click="emit('save')"
                >
                    <BellRing class="mr-2 h-4 w-4" /> Save
                </RequestButton>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
