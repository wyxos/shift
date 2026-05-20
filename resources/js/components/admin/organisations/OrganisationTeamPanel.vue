<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { Pencil, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

type OrganisationTeamUser = {
    id: string;
    organisationUserId?: number | null;
    name: string;
    email: string;
    status: 'owner' | 'registered' | 'pending';
    statusLabel: string;
    projectIds?: number[];
};

type OrganisationProject = {
    id: number;
    name: string;
};

const props = defineProps<{
    organisation: {
        id: number;
        name: string;
        projects: OrganisationProject[];
        teamUsers: OrganisationTeamUser[];
    };
}>();

const editingUser = ref<OrganisationTeamUser | null>(null);
const selectedProjectIds = ref<number[]>([]);
const saving = ref(false);
const error = ref<string | null>(null);
const projectIdsByTeamUser = ref<Record<string, number[]>>({});

const sheetOpen = computed(() => Boolean(editingUser.value));

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

function openAccessSheet(teamUser: OrganisationTeamUser) {
    editingUser.value = teamUser;
    selectedProjectIds.value = [...(projectIdsByTeamUser.value[teamUser.id] ?? teamUser.projectIds ?? [])];
    error.value = null;
}

function closeAccessSheet() {
    editingUser.value = null;
    selectedProjectIds.value = [];
    saving.value = false;
    error.value = null;
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
        const response = await axios.patch(
            `/organisations/${props.organisation.id}/users/${editingUser.value.organisationUserId}/projects`,
            {
                project_ids: selectedProjectIds.value,
            },
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

function removeOrganisationAccess() {
    if (!editingUser.value?.organisationUserId) return;

    const organisationUserId = editingUser.value.organisationUserId;

    router.delete(`/organisations/${props.organisation.id}/users/${organisationUserId}`, {
        preserveScroll: true,
        onSuccess: () => closeAccessSheet(),
    });
}
</script>

<template>
    <section class="bg-card rounded-xl border p-4">
        <div class="mb-4 flex flex-col gap-1">
            <h1 class="text-lg font-semibold">Team</h1>
            <p class="text-muted-foreground text-sm">{{ organisation.name }}</p>
        </div>

        <div class="overflow-hidden rounded-lg border">
            <div v-if="organisation.teamUsers.length === 0" class="text-muted-foreground p-4 text-sm">No users have access to this organisation.</div>
            <div
                v-for="teamUser in organisation.teamUsers"
                v-else
                :key="teamUser.id"
                class="flex flex-col gap-3 border-b p-3 last:border-b-0 sm:flex-row sm:items-center sm:justify-between"
                :data-testid="`organisation-team-user-${teamUser.id}`"
            >
                <div class="min-w-0">
                    <div class="truncate font-medium">
                        {{ teamUser.name }}
                        <span class="text-muted-foreground font-normal">({{ teamUser.email }})</span>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Badge :class="statusBadgeClass(teamUser.status)" variant="secondary">{{ teamUser.statusLabel }}</Badge>
                    <Button
                        v-if="teamUser.organisationUserId && teamUser.status !== 'owner'"
                        size="sm"
                        variant="outline"
                        :data-testid="`organisation-team-edit-${teamUser.organisationUserId}`"
                        @click="openAccessSheet(teamUser)"
                    >
                        <Pencil class="h-4 w-4 sm:mr-2" />
                        <span class="hidden sm:inline">Edit</span>
                        <span class="sr-only sm:hidden">Edit access</span>
                    </Button>
                </div>
            </div>
        </div>
    </section>

    <Sheet :open="sheetOpen" @update:open="(open) => !open && closeAccessSheet()">
        <SheetContent class="p-0" side="right">
            <SheetHeader class="border-b px-6 py-5">
                <SheetTitle>Edit access</SheetTitle>
                <SheetDescription v-if="editingUser"> {{ editingUser.name }} ({{ editingUser.email }}) </SheetDescription>
            </SheetHeader>

            <div class="flex-1 space-y-4 overflow-y-auto px-6 py-5">
                <div class="space-y-3">
                    <div>
                        <h2 class="text-sm font-medium">Project access</h2>
                        <p class="text-muted-foreground text-sm">Choose the organisation projects this user can access.</p>
                    </div>

                    <p v-if="organisation.projects.length === 0" class="text-muted-foreground rounded-lg border p-3 text-sm">
                        No projects are available for this organisation.
                    </p>

                    <div v-else class="space-y-2">
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

                <p v-if="error" class="text-destructive text-sm">{{ error }}</p>

                <div class="border-destructive/20 bg-destructive/5 rounded-lg border p-3">
                    <Button
                        type="button"
                        variant="destructive"
                        :disabled="saving"
                        data-testid="organisation-team-remove-access"
                        @click="removeOrganisationAccess"
                    >
                        <Trash2 class="mr-2 h-4 w-4" />
                        Remove from organisation
                    </Button>
                </div>
            </div>

            <SheetFooter class="border-t sm:flex-row sm:justify-end">
                <Button type="button" variant="outline" :disabled="saving" @click="closeAccessSheet">Cancel</Button>
                <Button type="button" :disabled="saving" data-testid="organisation-team-save-projects" @click="saveProjectAccess">
                    {{ saving ? 'Saving...' : 'Save changes' }}
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
