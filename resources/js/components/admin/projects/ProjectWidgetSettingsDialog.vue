<script setup lang="ts">
/* eslint-disable vue/no-mutating-props */
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { MessageSquare } from 'lucide-vue-next';

type WidgetEnvironmentSettings = {
    id: number;
    key: string;
    label: string;
    url: string;
    external_widget_enabled?: boolean;
    external_widget_guest_submissions_enabled?: boolean;
};

type WidgetSettingsForm = {
    project_name: string;
    external_widget_enabled: boolean;
    external_widget_guest_submissions_enabled: boolean;
    environments: WidgetEnvironmentSettings[];
};

const { open, form, loading, error } = defineProps<{
    open: boolean;
    form: WidgetSettingsForm;
    loading: boolean;
    error: string | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    cancel: [];
    save: [];
}>();

function updateOpen(value: boolean) {
    emit('update:open', value);

    if (!value) {
        emit('cancel');
    }
}

function updateWidgetEnabled(value: boolean) {
    form.external_widget_enabled = value;

    if (!value) {
        form.external_widget_guest_submissions_enabled = false;
    }
}

function updateEnvironmentWidgetEnabled(environment: WidgetEnvironmentSettings, value: boolean) {
    environment.external_widget_enabled = value;

    if (!value) {
        environment.external_widget_guest_submissions_enabled = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="updateOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Widget Settings</DialogTitle>
                <DialogDescription>Control external intake for {{ form.project_name }}</DialogDescription>
            </DialogHeader>

            <div class="space-y-4">
                <label class="hover:bg-muted/40 flex items-start gap-3 rounded-lg border p-3 text-sm">
                    <Checkbox
                        :model-value="form.external_widget_enabled"
                        data-testid="project-widget-enabled"
                        @update:model-value="updateWidgetEnabled(Boolean($event))"
                    />
                    <span class="grid gap-1">
                        <span class="font-medium">Embedded widget</span>
                        <span class="text-muted-foreground">Available outside the SHIFT dashboard.</span>
                    </span>
                </label>

                <label
                    class="hover:bg-muted/40 flex items-start gap-3 rounded-lg border p-3 text-sm"
                    :class="!form.external_widget_enabled ? 'opacity-60' : ''"
                >
                    <Checkbox
                        :disabled="!form.external_widget_enabled"
                        :model-value="form.external_widget_guest_submissions_enabled"
                        data-testid="project-widget-guest-submissions"
                        @update:model-value="form.external_widget_guest_submissions_enabled = Boolean($event)"
                    />
                    <span class="grid gap-1">
                        <span class="font-medium">Guest submissions</span>
                        <span class="text-muted-foreground">Allow anonymous and manually identified reports.</span>
                    </span>
                </label>

                <div v-if="form.environments.length" class="space-y-2">
                    <div>
                        <h3 class="text-sm font-medium">Environment overrides</h3>
                        <p class="text-muted-foreground text-xs">Tune widget availability for registered consuming app environments.</p>
                    </div>

                    <div
                        v-for="environment in form.environments"
                        :key="environment.id"
                        class="space-y-2 rounded-lg border p-3"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">{{ environment.label }}</p>
                            <p class="text-muted-foreground truncate text-xs">{{ environment.url }}</p>
                        </div>

                        <label class="hover:bg-muted/40 flex items-start gap-3 rounded-md p-2 text-sm">
                            <Checkbox
                                :model-value="environment.external_widget_enabled"
                                :data-testid="`project-widget-environment-${environment.id}-enabled`"
                                @update:model-value="updateEnvironmentWidgetEnabled(environment, Boolean($event))"
                            />
                            <span class="grid gap-1">
                                <span class="font-medium">Embedded widget</span>
                                <span class="text-muted-foreground">Use this environment-specific setting instead of the project default.</span>
                            </span>
                        </label>

                        <label
                            class="hover:bg-muted/40 flex items-start gap-3 rounded-md p-2 text-sm"
                            :class="!environment.external_widget_enabled ? 'opacity-60' : ''"
                        >
                            <Checkbox
                                :disabled="!environment.external_widget_enabled"
                                :model-value="environment.external_widget_guest_submissions_enabled"
                                :data-testid="`project-widget-environment-${environment.id}-guest-submissions`"
                                @update:model-value="environment.external_widget_guest_submissions_enabled = Boolean($event)"
                            />
                            <span class="grid gap-1">
                                <span class="font-medium">Guest submissions</span>
                                <span class="text-muted-foreground">Allow anonymous and manually identified reports for this environment.</span>
                            </span>
                        </label>
                    </div>
                </div>

                <p v-if="error" class="text-sm text-red-500">{{ error }}</p>
            </div>

            <DialogFooter>
                <Button type="button" variant="outline" :disabled="loading" @click="updateOpen(false)">Cancel</Button>
                <Button type="button" :disabled="loading" data-testid="save-widget-settings" @click="emit('save')">
                    <MessageSquare class="mr-2 h-4 w-4" />
                    Save
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
