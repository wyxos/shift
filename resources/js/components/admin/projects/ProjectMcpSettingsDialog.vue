<script setup lang="ts">
/* eslint-disable vue/no-mutating-props */
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Bot } from 'lucide-vue-next';

type McpSettingsForm = {
    project_name: string;
    mcp_enabled: boolean;
};

const { open, form, loading, error } = defineProps<{
    open: boolean;
    form: McpSettingsForm;
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
</script>

<template>
    <Dialog :open="open" @update:open="updateOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>MCP Settings</DialogTitle>
                <DialogDescription>Control Codex MCP access for {{ form.project_name }}</DialogDescription>
            </DialogHeader>

            <div class="space-y-3">
                <label class="hover:bg-muted/40 flex items-start gap-3 rounded-lg border p-3 text-sm">
                    <Checkbox
                        :model-value="form.mcp_enabled"
                        data-testid="project-mcp-enabled"
                        @update:model-value="form.mcp_enabled = Boolean($event)"
                    />
                    <span class="grid gap-1">
                        <span class="font-medium">MCP access</span>
                        <span class="text-muted-foreground">Expose this project to authenticated MCP tools.</span>
                    </span>
                </label>

                <p v-if="error" class="text-sm text-red-500">{{ error }}</p>
            </div>

            <DialogFooter>
                <Button type="button" variant="outline" :disabled="loading" @click="updateOpen(false)">Cancel</Button>
                <Button type="button" :disabled="loading" data-testid="save-mcp-settings" @click="emit('save')">
                    <Bot class="mr-2 h-4 w-4" />
                    Save
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
