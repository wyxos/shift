<script setup lang="ts">
/* eslint-disable vue/no-mutating-props */
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = {
    id: number;
    name: string;
};

type CreateForm = {
    name: string;
    client_id: number | null;
    organisation_id: number | null;
    errors: Record<string, string>;
    processing: boolean;
};

const { open, form, clients, organisations, otherErrors, disabled } = defineProps<{
    open: boolean;
    form: CreateForm;
    clients: Option[];
    organisations: Option[];
    otherErrors: Record<string, string>;
    disabled: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    cancel: [];
    submit: [];
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
                <DialogTitle>Create Project</DialogTitle>
                <DialogDescription>Create a project and attach it to either a client or an organisation.</DialogDescription>
            </DialogHeader>

            <div class="space-y-4">
                <div class="space-y-2">
                    <Label for="create-project-name">Project name</Label>
                    <Input id="create-project-name" v-model="form.name" data-testid="create-project-name" placeholder="Portal refresh" />
                    <p v-if="form.errors.name" class="text-sm text-red-500">{{ form.errors.name }}</p>
                </div>

                <div class="space-y-2">
                    <Label for="create-project-client">Client</Label>
                    <select
                        id="create-project-client"
                        v-model="form.client_id"
                        data-testid="create-project-client"
                        :disabled="form.organisation_id !== null"
                        class="border-input bg-background ring-offset-background focus-visible:ring-ring flex h-10 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <option :value="null">No client</option>
                        <option v-for="client in clients" :key="client.id" :value="client.id">{{ client.name }}</option>
                    </select>
                    <p v-if="form.errors.client_id" class="text-sm text-red-500">{{ form.errors.client_id }}</p>
                </div>

                <div class="space-y-2">
                    <Label for="create-project-organisation">Organisation</Label>
                    <select
                        id="create-project-organisation"
                        v-model="form.organisation_id"
                        data-testid="create-project-organisation"
                        :disabled="form.client_id !== null"
                        class="border-input bg-background ring-offset-background focus-visible:ring-ring flex h-10 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <option :value="null">No organisation</option>
                        <option v-for="organisation in organisations" :key="organisation.id" :value="organisation.id">{{ organisation.name }}</option>
                    </select>
                    <p v-if="form.errors.organisation_id" class="text-sm text-red-500">{{ form.errors.organisation_id }}</p>
                </div>

                <p class="text-muted-foreground text-sm">Choose one parent or leave both empty for a standalone project.</p>
                <p v-for="(error, key) in otherErrors" :key="key" class="text-sm text-red-500">{{ error }}</p>
            </div>

            <DialogFooter>
                <Button type="button" variant="outline" @click="updateOpen(false)">Cancel</Button>
                <Button type="button" :disabled="disabled" data-testid="create-project-submit" @click="emit('submit')">Create</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
