<script setup lang="ts">
/* eslint-disable vue/no-mutating-props */
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { MessageSquare } from 'lucide-vue-next';

type WidgetSettingsForm = {
    project_name: string;
    external_widget_enabled: boolean;
    external_widget_guest_submissions_enabled: boolean;
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
</script>

<template>
    <Dialog :open="open" @update:open="updateOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Widget Settings</DialogTitle>
                <DialogDescription>Control external intake for {{ form.project_name }}</DialogDescription>
            </DialogHeader>

            <div class="space-y-3">
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
