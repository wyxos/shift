<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import ActionIconButton from '@/shared/components/ActionIconButton.vue';
import { Bot, KeyRound, ListTodo, MessageSquare, Pencil, Trash2, Users, UserSearch } from 'lucide-vue-next';
import { type ProjectRow, projectClientLabel, projectOrganisationLabel } from './project-shared';

const { projects, showOrganisationColumn = true } = defineProps<{
    projects: ProjectRow[];
    showOrganisationColumn?: boolean;
}>();

const emit = defineEmits<{
    'open-edit': [project: ProjectRow];
    'open-delete': [project: ProjectRow];
    'open-external-users': [project: ProjectRow];
    'open-manage-users': [project: ProjectRow];
    'open-api-token': [project: ProjectRow];
    'open-tasks': [project: ProjectRow];
    'open-widget-settings': [project: ProjectRow];
    'open-mcp-settings': [project: ProjectRow];
}>();

function canManageProjectAccess(project: ProjectRow) {
    return project.can_manage_project_access ?? project.isOwner === true;
}

function canManageTechnicalSettings(project: ProjectRow) {
    return project.can_manage_technical_settings ?? project.isOwner === true;
}

function canDeleteProject(project: ProjectRow) {
    return project.can_delete_project ?? project.isOwner === true;
}

function canEditProject(project: ProjectRow) {
    return canManageProjectAccess(project);
}

function hasWidgetEnabled(project: ProjectRow) {
    return Boolean(project.external_widget_enabled || project.environments?.some((environment) => environment.external_widget_enabled));
}

function hasGuestSubmissionsEnabled(project: ProjectRow) {
    return Boolean(
        project.external_widget_guest_submissions_enabled ||
            project.environments?.some((environment) => environment.external_widget_guest_submissions_enabled),
    );
}
</script>

<template>
    <Table>
        <TableHeader>
            <TableRow>
                <TableHead>Project</TableHead>
                <TableHead v-if="showOrganisationColumn">Organisation</TableHead>
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
                            <span v-if="projectClientLabel(project)" class="text-muted-foreground text-xs">
                                {{ projectClientLabel(project) }}
                            </span>
                            <div v-if="hasWidgetEnabled(project) || project.mcp_enabled" class="flex flex-wrap gap-1">
                                <Badge v-if="hasWidgetEnabled(project)" :data-testid="`project-widget-enabled-${project.id}`" variant="secondary">
                                    Widget
                                </Badge>
                                <Badge
                                    v-if="hasGuestSubmissionsEnabled(project)"
                                    :data-testid="`project-widget-guests-${project.id}`"
                                    class="bg-emerald-100 text-emerald-900 hover:bg-emerald-100"
                                    variant="secondary"
                                >
                                    Guests
                                </Badge>
                                <Badge v-if="project.mcp_enabled" :data-testid="`project-mcp-enabled-${project.id}`" variant="secondary">MCP</Badge>
                            </div>
                        </div>
                    </TableCell>
                    <TableCell v-if="showOrganisationColumn">
                        <Badge :data-testid="`project-scope-${project.id}`" variant="secondary">
                            {{ projectOrganisationLabel(project) }}
                        </Badge>
                    </TableCell>
                    <TableCell>
                        <Badge
                            :data-testid="`project-access-${project.id}`"
                            :class="
                                project.isOwner ? 'bg-emerald-100 text-emerald-900 hover:bg-emerald-100' : 'bg-sky-100 text-sky-900 hover:bg-sky-100'
                            "
                            variant="secondary"
                        >
                            {{ project.isOwner ? 'Owner' : 'Shared' }}
                        </Badge>
                    </TableCell>
                    <TableCell>
                        <div class="flex flex-wrap justify-end gap-2">
                            <ActionIconButton
                                label="View project tasks"
                                title="Tasks"
                                :data-testid="`project-tasks-${project.id}`"
                                @click="emit('open-tasks', project)"
                            >
                                <ListTodo class="h-4 w-4" />
                            </ActionIconButton>
                            <ActionIconButton
                                label="View external users"
                                title="External users"
                                :data-testid="`project-external-users-${project.id}`"
                                @click="emit('open-external-users', project)"
                            >
                                <UserSearch class="h-4 w-4" />
                            </ActionIconButton>
                            <ActionIconButton
                                v-if="canManageProjectAccess(project)"
                                label="Manage project access"
                                title="Manage access"
                                :data-testid="`project-manage-${project.id}`"
                                @click="emit('open-manage-users', project)"
                            >
                                <Users class="h-4 w-4" />
                            </ActionIconButton>
                            <ActionIconButton
                                v-if="canManageTechnicalSettings(project)"
                                label="Manage API token"
                                title="API token"
                                :data-testid="`project-token-${project.id}`"
                                @click="emit('open-api-token', project)"
                            >
                                <KeyRound class="h-4 w-4" />
                            </ActionIconButton>
                            <ActionIconButton
                                v-if="canManageTechnicalSettings(project)"
                                label="Manage widget settings"
                                title="Widget"
                                :data-testid="`project-widget-${project.id}`"
                                @click="emit('open-widget-settings', project)"
                            >
                                <MessageSquare class="h-4 w-4" />
                            </ActionIconButton>
                            <ActionIconButton
                                v-if="canManageTechnicalSettings(project)"
                                label="Manage MCP settings"
                                title="MCP"
                                :data-testid="`project-mcp-${project.id}`"
                                @click="emit('open-mcp-settings', project)"
                            >
                                <Bot class="h-4 w-4" />
                            </ActionIconButton>
                            <ActionIconButton
                                v-if="canEditProject(project)"
                                label="Edit project"
                                title="Edit"
                                :data-testid="`project-edit-${project.id}`"
                                @click="emit('open-edit', project)"
                            >
                                <Pencil class="h-4 w-4" />
                            </ActionIconButton>
                            <ActionIconButton
                                v-if="canDeleteProject(project)"
                                label="Delete project"
                                title="Delete"
                                variant="destructive"
                                :data-testid="`project-delete-${project.id}`"
                                @click="emit('open-delete', project)"
                            >
                                <Trash2 class="h-4 w-4" />
                            </ActionIconButton>
                        </div>
                    </TableCell>
                </TableRow>
            </template>
            <TableEmpty v-else :colspan="showOrganisationColumn ? 4 : 3">No projects found.</TableEmpty>
        </TableBody>
    </Table>
</template>
