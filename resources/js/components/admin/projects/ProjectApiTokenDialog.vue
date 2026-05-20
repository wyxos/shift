<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { KeyRound } from 'lucide-vue-next';

type ApiTokenForm = {
    project_name: string;
    token: string;
};

const { open, form, loading, error } = defineProps<{
    open: boolean;
    form: ApiTokenForm;
    loading: boolean;
    error: string | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    cancel: [];
    generate: [];
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
                <DialogTitle>Project API Token</DialogTitle>
                <DialogDescription>Manage the API token for {{ form.project_name }}</DialogDescription>
            </DialogHeader>

            <div class="space-y-4">
                <div v-if="form.token" class="bg-muted rounded-lg p-4" data-testid="project-token-value">
                    <p class="text-sm font-medium">Current API token</p>
                    <p class="mt-2 text-sm break-all">{{ form.token }}</p>
                </div>
                <p v-else class="text-muted-foreground text-sm">No API token has been generated for this project yet.</p>

                <p v-if="error" class="text-sm text-red-500">{{ error }}</p>

                <Button type="button" :disabled="loading" data-testid="generate-project-token" @click="emit('generate')">
                    <KeyRound class="mr-2 h-4 w-4" />
                    {{ form.token ? 'Regenerate Token' : 'Generate Token' }}
                </Button>

                <p class="text-muted-foreground text-sm">Regenerating a token invalidates any existing integrations using the previous token.</p>
            </div>

            <DialogFooter>
                <Button type="button" variant="outline" @click="updateOpen(false)">Close</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
