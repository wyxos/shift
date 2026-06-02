<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type OrganisationIdentity = {
    id: number;
    name: string;
};

type SettingsForm = {
    id: number | null;
    processing: boolean;
    errors: Record<string, string>;
};

defineProps<{
    organisation: OrganisationIdentity;
    form: SettingsForm;
    name: string;
    saveDisabled: boolean;
}>();

defineEmits<{
    delete: [organisation: OrganisationIdentity];
    save: [];
    'update:name': [value: string | number];
}>();
</script>

<template>
    <section class="flex flex-col gap-8">
        <div class="max-w-xl">
            <div class="flex flex-col gap-4">
                <div class="flex flex-col gap-1">
                    <h1 class="text-lg font-semibold">Settings</h1>
                    <p class="text-muted-foreground text-sm">{{ organisation.name }}</p>
                </div>

                <div class="flex flex-col gap-2">
                    <Label for="settings-organisation-name">Name</Label>
                    <Input
                        id="settings-organisation-name"
                        data-testid="settings-organisation-name"
                        :model-value="name"
                        placeholder="Organisation name"
                        @update:model-value="$emit('update:name', $event)"
                    />
                </div>

                <div v-for="(error, key) in form.errors" :key="key" class="text-destructive text-sm">{{ error }}</div>

                <Button data-testid="settings-save-organisation" :disabled="saveDisabled" @click="$emit('save')"> Save changes </Button>
            </div>
        </div>

        <div class="border-destructive/30 border-t pt-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-col gap-1">
                    <h2 class="text-destructive font-semibold">Delete organisation</h2>
                    <p class="text-muted-foreground text-sm">This will permanently remove the organisation.</p>
                </div>
                <Button data-testid="settings-delete-organisation" variant="destructive" @click="$emit('delete', organisation)">
                    Delete organisation
                </Button>
            </div>
        </div>
    </section>
</template>
