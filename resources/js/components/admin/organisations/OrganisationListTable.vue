<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Building2, FolderKanban, Pencil, Trash2, UserPlus, Users } from 'lucide-vue-next';

type OrganisationRow = {
    id: number;
    name: string;
    created_at?: string | null;
    organisation_users_count?: number | null;
    projects_count?: number | null;
};

const { organisations } = defineProps<{
    organisations: OrganisationRow[];
}>();

const emit = defineEmits<{
    'open-edit': [organisation: OrganisationRow];
    'open-delete': [organisation: OrganisationRow];
    'open-invite': [organisation: OrganisationRow];
    'open-manage-users': [organisation: OrganisationRow];
}>();

function formatDate(value?: string | null) {
    if (!value) return 'Unknown';
    return new Date(value).toLocaleDateString();
}

function usersLabel(count?: number | null) {
    const total = Number(count ?? 0);
    return `${total} user${total === 1 ? '' : 's'}`;
}

function projectsLabel(count?: number | null) {
    const total = Number(count ?? 0);
    return `${total} project${total === 1 ? '' : 's'}`;
}
</script>

<template>
    <Table>
        <TableHeader>
            <TableRow>
                <TableHead>Organisation</TableHead>
                <TableHead>Access</TableHead>
                <TableHead>Created</TableHead>
                <TableHead class="text-right">Actions</TableHead>
            </TableRow>
        </TableHeader>
        <TableBody>
            <TableEmpty v-if="organisations.length === 0" :colspan="4">No organisations found.</TableEmpty>

            <TableRow v-for="organisation in organisations" v-else :key="organisation.id" :data-testid="`organisation-row-${organisation.id}`">
                <TableCell>
                    <div class="flex items-start gap-3">
                        <div class="bg-primary/10 text-primary flex h-9 w-9 items-center justify-center rounded-lg">
                            <Building2 class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <div class="truncate font-medium">{{ organisation.name }}</div>
                            <div class="text-muted-foreground text-xs">Created {{ formatDate(organisation.created_at) }}</div>
                        </div>
                    </div>
                </TableCell>
                <TableCell>
                    <div class="flex flex-wrap gap-2">
                        <Badge variant="secondary">{{ usersLabel(organisation.organisation_users_count) }}</Badge>
                        <Badge variant="outline" class="gap-1">
                            <FolderKanban class="h-3 w-3" />
                            {{ projectsLabel(organisation.projects_count) }}
                        </Badge>
                    </div>
                </TableCell>
                <TableCell class="text-muted-foreground">{{ formatDate(organisation.created_at) }}</TableCell>
                <TableCell>
                    <div class="flex flex-wrap justify-end gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            :data-testid="`organisation-invite-${organisation.id}`"
                            title="Invite user"
                            @click="emit('open-invite', organisation)"
                        >
                            <UserPlus class="h-4 w-4" />
                            <span class="sr-only">Invite user</span>
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            :data-testid="`organisation-manage-${organisation.id}`"
                            title="Manage users"
                            @click="emit('open-manage-users', organisation)"
                        >
                            <Users class="h-4 w-4" />
                            <span class="sr-only">Manage users</span>
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            :data-testid="`organisation-edit-${organisation.id}`"
                            title="Edit organisation"
                            @click="emit('open-edit', organisation)"
                        >
                            <Pencil class="h-4 w-4" />
                            <span class="sr-only">Edit organisation</span>
                        </Button>
                        <Button
                            size="sm"
                            variant="destructive"
                            :data-testid="`organisation-delete-${organisation.id}`"
                            title="Delete organisation"
                            @click="emit('open-delete', organisation)"
                        >
                            <Trash2 class="h-4 w-4" />
                            <span class="sr-only">Delete organisation</span>
                        </Button>
                    </div>
                </TableCell>
            </TableRow>
        </TableBody>
    </Table>
</template>
