<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { FolderKanban, KeyRound, Pencil, Trash2, UserPlus, Users } from 'lucide-vue-next';
import { type ProjectRow, projectScopeLabel, projectScopeVariant } from './project-shared';

const { projects } = defineProps<{
    projects: ProjectRow[];
}>();

const emit = defineEmits<{
    'open-edit': [project: ProjectRow];
    'open-delete': [project: ProjectRow];
    'open-grant-access': [project: ProjectRow];
    'open-manage-users': [project: ProjectRow];
    'open-api-token': [project: ProjectRow];
}>();
</script>

<template>
    <Table>
        <TableHeader>
            <TableRow>
                <TableHead>Project</TableHead>
                <TableHead>Scope</TableHead>
                <TableHead>Access</TableHead>
                <TableHead class="text-right">Actions</TableHead>
            </TableRow>
        </TableHeader>
        <TableBody>
            <template v-if="projects.length">
                <TableRow v-for="project in projects" :key="project.id" :data-testid="`project-row-${project.id}`">
                    <TableCell>
                        <div class="flex flex-col gap-1">
                            <span class="font-medium">{{ project.name }}</span>
                            <span class="text-muted-foreground inline-flex items-center gap-1 text-xs">
                                <FolderKanban class="h-3.5 w-3.5" />
                                Project #{{ project.id }}
                            </span>
                        </div>
                    </TableCell>
                    <TableCell>
                        <Badge :data-testid="`project-scope-${project.id}`" :variant="projectScopeVariant(project)" class="gap-1">
                            <KeyRound class="h-3.5 w-3.5" />
                            {{ projectScopeLabel(project) }}
                        </Badge>
                    </TableCell>
                    <TableCell>
                        <Badge
                            :data-testid="`project-access-${project.id}`"
                            :class="
                                project.isOwner
                                    ? 'bg-emerald-100 text-emerald-900 hover:bg-emerald-100'
                                    : 'bg-sky-100 text-sky-900 hover:bg-sky-100'
                            "
                            variant="secondary"
                        >
                            {{ project.isOwner ? 'Owner access' : 'Shared access' }}
                        </Badge>
                    </TableCell>
                    <TableCell>
                        <div class="flex flex-wrap justify-end gap-2">
                            <template v-if="project.isOwner">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :data-testid="`project-grant-${project.id}`"
                                    @click="emit('open-grant-access', project)"
                                >
                                    <UserPlus class="h-4 w-4 sm:mr-2" />
                                    <span class="hidden sm:inline">Grant</span>
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :data-testid="`project-manage-${project.id}`"
                                    @click="emit('open-manage-users', project)"
                                >
                                    <Users class="h-4 w-4 sm:mr-2" />
                                    <span class="hidden sm:inline">Manage</span>
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :data-testid="`project-token-${project.id}`"
                                    @click="emit('open-api-token', project)"
                                >
                                    <KeyRound class="h-4 w-4 sm:mr-2" />
                                    <span class="hidden sm:inline">Token</span>
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :data-testid="`project-edit-${project.id}`"
                                    @click="emit('open-edit', project)"
                                >
                                    <Pencil class="h-4 w-4 sm:mr-2" />
                                    <span class="hidden sm:inline">Edit</span>
                                </Button>
                                <Button
                                    type="button"
                                    variant="destructive"
                                    size="sm"
                                    :data-testid="`project-delete-${project.id}`"
                                    @click="emit('open-delete', project)"
                                >
                                    <Trash2 class="h-4 w-4 sm:mr-2" />
                                    <span class="hidden sm:inline">Delete</span>
                                </Button>
                            </template>
                            <span v-else class="text-muted-foreground text-sm">View and collaborate only</span>
                        </div>
                    </TableCell>
                </TableRow>
            </template>
            <TableEmpty v-else :colspan="4">No projects found.</TableEmpty>
        </TableBody>
    </Table>
</template>
