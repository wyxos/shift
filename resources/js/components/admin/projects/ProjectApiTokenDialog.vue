<script setup lang="ts">
import { AlertDialog, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
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
</script>

<template>
    <AlertDialog :open="open" @update:open="emit('update:open', $event)">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>Project API Token</AlertDialogTitle>
                <AlertDialogDescription>Manage the API token for {{ form.project_name }}</AlertDialogDescription>
            </AlertDialogHeader>

            <div class="space-y-4">
                <div v-if="form.token" class="bg-muted rounded-lg p-4" data-testid="project-token-value">
                    <p class="text-sm font-medium">Current API token</p>
                    <p class="mt-2 break-all text-sm">{{ form.token }}</p>
                </div>
                <p v-else class="text-muted-foreground text-sm">No API token has been generated for this project yet.</p>

                <p v-if="error" class="text-sm text-red-500">{{ error }}</p>

                <Button type="button" :disabled="loading" data-testid="generate-project-token" @click="emit('generate')">
                    <KeyRound class="mr-2 h-4 w-4" />
                    {{ form.token ? 'Regenerate Token' : 'Generate Token' }}
                </Button>

                <p class="text-muted-foreground text-sm">Regenerating a token invalidates any existing integrations using the previous token.</p>
            </div>

            <AlertDialogFooter>
                <AlertDialogCancel type="button" @click="emit('update:open', false); emit('cancel')">Close</AlertDialogCancel>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
