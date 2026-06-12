<script setup lang="ts">
import DeleteDialog from '@/components/DeleteDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Select, type SelectOption } from '@/components/ui/select';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import ActionIconButton from '@/shared/components/ActionIconButton.vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { Pencil, Trash2, UserPlus } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

type OrganisationTeamUser = {
    id: string;
    organisationUserId?: number | null;
    name: string;
    email: string;
    status: 'owner' | 'registered' | 'pending';
    statusLabel: string;
    role?: string | null;
    roleLabel?: string | null;
    canManageRole?: boolean | null;
    can_manage_role?: boolean | null;
    projectIds?: number[];
    projectAccessCount?: number;
    createdAt?: string | null;
    verifiedAt?: string | null;
    lastLoginAt?: string | null;
};

type OrganisationProject = {
    id: number;
    name: string;
};

type OrganisationRoleOption = SelectOption;

const props = defineProps<{
    organisation: {
        id: number;
        name: string;
        projects: OrganisationProject[];
        teamUsers: OrganisationTeamUser[];
        roleOptions?: OrganisationRoleOption[];
        role_options?: OrganisationRoleOption[];
        canManageRoles?: boolean | null;
        can_manage_roles?: boolean | null;
        canManageTeamRoles?: boolean | null;
        can_manage_team_roles?: boolean | null;
        capabilities?: {
            canManageRoles?: boolean | null;
            can_manage_roles?: boolean | null;
            canManageTeamRoles?: boolean | null;
            can_manage_team_roles?: boolean | null;
        };
    };
}>();

const emit = defineEmits<{
    invite: [];
}>();

const editingUser = ref<OrganisationTeamUser | null>(null);
const selectedProjectIds = ref<number[]>([]);
const selectedRole = ref<string>('');
const saving = ref(false);
const error = ref<string | null>(null);
const projectIdsByTeamUser = ref<Record<string, number[]>>({});
const removingUser = ref<OrganisationTeamUser | null>(null);

const sheetOpen = computed(() => Boolean(editingUser.value));
const removeDialogOpen = computed(() => Boolean(removingUser.value));
const dateFormatter = new Intl.DateTimeFormat(undefined, {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
});
const roleOptions = computed(() => props.organisation.roleOptions ?? props.organisation.role_options ?? []);
const canManageTeamRoles = computed(
    () =>
        props.organisation.canManageTeamRoles === true ||
        props.organisation.can_manage_team_roles === true ||
        props.organisation.canManageRoles === true ||
        props.organisation.can_manage_roles === true ||
        props.organisation.capabilities?.canManageTeamRoles === true ||
        props.organisation.capabilities?.can_manage_team_roles === true ||
        props.organisation.capabilities?.canManageRoles === true ||
        props.organisation.capabilities?.can_manage_roles === true,
);
const canEditSelectedUserRole = computed(() => {
    if (!editingUser.value?.organisationUserId || editingUser.value.status === 'owner') {
        return false;
    }

    if (!canManageTeamRoles.value || roleOptions.value.length === 0) {
        return false;
    }

    return editingUser.value.canManageRole !== false && editingUser.value.can_manage_role !== false;
});

watch(
    () => props.organisation.teamUsers,
    (teamUsers) => {
        projectIdsByTeamUser.value = Object.fromEntries(teamUsers.map((teamUser) => [teamUser.id, [...(teamUser.projectIds ?? [])]]));
    },
    { immediate: true, deep: true },
);

function statusBadgeClass(status: OrganisationTeamUser['status']) {
    if (status === 'owner') {
        return 'bg-emerald-100 text-emerald-900 hover:bg-emerald-100 dark:bg-emerald-500/15 dark:text-emerald-200';
    }

    if (status === 'pending') {
        return 'border-transparent bg-amber-100 text-amber-900 hover:bg-amber-100 dark:bg-amber-500/15 dark:text-amber-200';
    }

    return '';
}

function formatDate(value: string | null | undefined, fallback: string) {
    if (!value) {
        return fallback;
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return fallback;
    }

    return dateFormatter.format(date);
}

function formatProjectCount(teamUser: OrganisationTeamUser) {
    const count = teamUser.projectAccessCount ?? teamUser.projectIds?.length ?? 0;

    return `${count} ${count === 1 ? 'project' : 'projects'}`;
}

function roleLabelForValue(value: string | null | undefined) {
    if (!value) {
        return 'Role not assigned';
    }

    return (
        roleOptions.value.find((option) => option.value === value)?.label ??
        value
            .split('_')
            .filter(Boolean)
            .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
            .join(' ')
    );
}

function roleLabel(teamUser: OrganisationTeamUser) {
    return teamUser.roleLabel?.trim() || roleLabelForValue(teamUser.role);
}

function openAccessSheet(teamUser: OrganisationTeamUser) {
    editingUser.value = teamUser;
    selectedProjectIds.value = [...(projectIdsByTeamUser.value[teamUser.id] ?? teamUser.projectIds ?? [])];
    selectedRole.value = teamUser.role ?? '';
    error.value = null;
}

function closeAccessSheet() {
    editingUser.value = null;
    selectedProjectIds.value = [];
    selectedRole.value = '';
    saving.value = false;
    error.value = null;
}

function openRemoveConfirmation(teamUser: OrganisationTeamUser) {
    if (!teamUser.organisationUserId) return;

    removingUser.value = teamUser;
}

function closeRemoveConfirmation() {
    removingUser.value = null;
}

function hasProject(projectId: number) {
    return selectedProjectIds.value.includes(projectId);
}

function setProject(projectId: number, enabled: boolean) {
    if (enabled && !selectedProjectIds.value.includes(projectId)) {
        selectedProjectIds.value = [...selectedProjectIds.value, projectId];
        return;
    }

    if (!enabled) {
        selectedProjectIds.value = selectedProjectIds.value.filter((selectedProjectId) => selectedProjectId !== projectId);
    }
}

async function saveProjectAccess() {
    if (!editingUser.value?.organisationUserId) return;

    saving.value = true;
    error.value = null;

    try {
        const payload: { project_ids: number[]; role?: string | null } = {
            project_ids: selectedProjectIds.value,
        };

        if (canEditSelectedUserRole.value) {
            payload.role = selectedRole.value || null;
        }

        const response = await axios.patch(
            `/organisations/${props.organisation.id}/users/${editingUser.value.organisationUserId}/projects`,
            payload,
            {
                headers: {
                    Accept: 'application/json',
                },
            },
        );

        projectIdsByTeamUser.value = {
            ...projectIdsByTeamUser.value,
            [editingUser.value.id]: response.data.project_ids ?? selectedProjectIds.value,
        };
        closeAccessSheet();
    } catch (saveError) {
        console.error('Error saving organisation member project access:', saveError);
        error.value = 'Unable to save project access right now.';
    } finally {
        saving.value = false;
    }
}

function confirmRemoveOrganisationAccess() {
    if (!removingUser.value?.organisationUserId) return;

    const organisationUserId = removingUser.value.organisationUserId;

    router.delete(`/organisations/${props.organisation.id}/users/${organisationUserId}`, {
        preserveScroll: true,
        onSuccess: () => {
            if (editingUser.value?.organisationUserId === organisationUserId) {
                closeAccessSheet();
            }

            closeRemoveConfirmation();
        },
    });
}
</script>

<template>
    <section class="flex flex-col gap-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <h1 class="text-lg font-semibold">Team</h1>
                <p class="text-muted-foreground truncate text-sm">{{ organisation.name }}</p>
            </div>
            <Button size="sm" data-testid="organisation-team-invite" @click="emit('invite')">
                <UserPlus class="mr-2 h-4 w-4" />
                Invite
            </Button>
        </div>

        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>User</TableHead>
                    <TableHead>Last logged in</TableHead>
                    <TableHead>Created on</TableHead>
                    <TableHead>Verified on</TableHead>
                    <TableHead>Project access</TableHead>
                    <TableHead>Role</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead class="text-right">Actions</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableEmpty v-if="organisation.teamUsers.length === 0" :colspan="8">No users have access to this organisation.</TableEmpty>

                <TableRow v-for="teamUser in organisation.teamUsers" v-else :key="teamUser.id" :data-testid="`organisation-team-user-${teamUser.id}`">
                    <TableCell class="min-w-[15rem] whitespace-normal">
                        <div class="min-w-0">
                            <div class="truncate font-medium">
                                {{ teamUser.name }}
                                <span class="text-muted-foreground font-normal">({{ teamUser.email }})</span>
                            </div>
                        </div>
                    </TableCell>
                    <TableCell class="text-muted-foreground" :data-testid="`organisation-team-last-login-${teamUser.id}`">
                        {{ formatDate(teamUser.lastLoginAt, 'Never') }}
                    </TableCell>
                    <TableCell class="text-muted-foreground" :data-testid="`organisation-team-created-${teamUser.id}`">
                        {{ formatDate(teamUser.createdAt, 'Unknown') }}
                    </TableCell>
                    <TableCell class="text-muted-foreground" :data-testid="`organisation-team-verified-${teamUser.id}`">
                        {{ formatDate(teamUser.verifiedAt, 'Unverified') }}
                    </TableCell>
                    <TableCell class="text-muted-foreground" :data-testid="`organisation-team-project-count-${teamUser.id}`">
                        {{ formatProjectCount(teamUser) }}
                    </TableCell>
                    <TableCell>
                        <Badge variant="outline" :data-testid="`organisation-team-role-${teamUser.id}`">{{ roleLabel(teamUser) }}</Badge>
                    </TableCell>
                    <TableCell>
                        <Badge :class="statusBadgeClass(teamUser.status)" variant="secondary">{{ teamUser.statusLabel }}</Badge>
                    </TableCell>
                    <TableCell>
                        <div class="flex justify-end gap-2">
                            <ActionIconButton
                                v-if="teamUser.organisationUserId && teamUser.status !== 'owner'"
                                label="Edit project access"
                                title="Edit access"
                                :data-testid="`organisation-team-edit-${teamUser.organisationUserId}`"
                                @click="openAccessSheet(teamUser)"
                            >
                                <Pencil class="h-4 w-4" />
                            </ActionIconButton>
                            <ActionIconButton
                                v-if="teamUser.organisationUserId && teamUser.status !== 'owner'"
                                label="Remove organisation access"
                                title="Remove from organisation"
                                variant="destructive"
                                :data-testid="`organisation-team-remove-${teamUser.organisationUserId}`"
                                @click="openRemoveConfirmation(teamUser)"
                            >
                                <Trash2 class="h-4 w-4" />
                            </ActionIconButton>
                        </div>
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </section>

    <Sheet :open="sheetOpen" @update:open="(open) => !open && closeAccessSheet()">
        <SheetContent class="p-0" side="right">
            <SheetHeader class="border-b px-6 py-5">
                <SheetTitle>Edit access</SheetTitle>
                <SheetDescription v-if="editingUser"> {{ editingUser.name }} ({{ editingUser.email }}) </SheetDescription>
            </SheetHeader>

            <div class="flex flex-1 flex-col gap-4 overflow-y-auto px-6 py-5">
                <div class="flex flex-col gap-3">
                    <div>
                        <h2 class="text-sm font-medium">Project access</h2>
                        <p class="text-muted-foreground text-sm">Choose the organisation projects this user can access.</p>
                    </div>

                    <p v-if="organisation.projects.length === 0" class="text-muted-foreground rounded-lg border p-3 text-sm">
                        No projects are available for this organisation.
                    </p>

                    <div v-else class="flex flex-col gap-2">
                        <label
                            v-for="project in organisation.projects"
                            :key="project.id"
                            class="hover:bg-muted/40 flex items-center gap-3 rounded-lg border p-3 text-sm"
                            :data-testid="`organisation-team-project-${project.id}`"
                        >
                            <Checkbox
                                :model-value="hasProject(project.id)"
                                :data-testid="`organisation-team-project-checkbox-${project.id}`"
                                @update:model-value="setProject(project.id, Boolean($event))"
                            />
                            <span class="min-w-0 truncate">{{ project.name }}</span>
                        </label>
                    </div>
                </div>

                <div v-if="editingUser" class="flex flex-col gap-3">
                    <div>
                        <h2 class="text-sm font-medium">Role</h2>
                        <p class="text-muted-foreground text-sm">Set this member's organisation role when your access allows it.</p>
                    </div>

                    <Select
                        v-if="canEditSelectedUserRole"
                        v-model="selectedRole"
                        :disabled="saving"
                        empty-label="No roles found."
                        :options="roleOptions"
                        placeholder="Select a role"
                        test-id="organisation-team-role"
                    />
                    <div v-else class="rounded-lg border p-3 text-sm" data-testid="organisation-team-role-readonly">
                        <span class="font-medium">{{ roleLabel(editingUser) }}</span>
                    </div>
                </div>

                <p v-if="error" class="text-destructive text-sm">{{ error }}</p>
            </div>

            <SheetFooter class="border-t sm:flex-row sm:justify-end">
                <Button type="button" variant="outline" :disabled="saving" @click="closeAccessSheet">Cancel</Button>
                <Button type="button" :disabled="saving" data-testid="organisation-team-save-projects" @click="saveProjectAccess">
                    {{ saving ? 'Saving...' : 'Save changes' }}
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>

    <DeleteDialog :is-open="removeDialogOpen" @cancel="closeRemoveConfirmation" @confirm="confirmRemoveOrganisationAccess">
        <template #title>Remove organisation access</template>
        <template #description>
            Remove {{ removingUser?.name }} from {{ organisation.name }}? They will also lose access to projects inside this organisation.
        </template>
        <template #cancel>Cancel</template>
        <template #confirm>Remove access</template>
    </DeleteDialog>
</template>
