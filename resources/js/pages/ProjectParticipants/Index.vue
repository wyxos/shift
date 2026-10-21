<script lang="ts" setup>
import AdminListShell from '@/components/admin/AdminListShell.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { Input } from '@/components/ui/input';
import { Select, type SelectOption } from '@/components/ui/select';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import FilterResetButton from '@/shared/components/FilterResetButton.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { Pencil } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import LinkedAccountsManager from './LinkedAccountsManager.vue';

type LinkedAccount = {
    id: number | string;
    label?: string | null;
    name?: string | null;
    email?: string | null;
    provider?: string | null;
    environment?: string | null;
    url?: string | null;
    unlink_url?: string | null;
    unlinkUrl?: string | null;
    can_unlink?: boolean | null;
    canUnlink?: boolean | null;
    links?: { unlink?: string | null };
};
type ExternalIdentityRow = {
    id: number;
    name: string;
    email?: string | null;
    environment?: string | null;
    role?: string | null;
    role_label?: string | null;
    can_manage_role?: boolean | null;
    canManageRole?: boolean | null;
    linked_accounts?: LinkedAccount[];
    linkedAccounts?: LinkedAccount[];
    linkable_accounts?: LinkedAccount[];
    linkableAccounts?: LinkedAccount[];
    links?: { link_accounts?: string | null; linkAccounts?: string | null };
    project?: { id: number; name: string } | null;
};
type ExternalIdentitiesPage = {
    data: ExternalIdentityRow[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
};
type Filters = {
    search?: string | null;
    sort_by?: string | null;
    project_id?: number | string | null;
    organisation_id?: number | string | null;
};
type SortBy = 'newest' | 'oldest' | 'name';
type EditErrors = Partial<Record<'name' | 'email' | 'role', string>>;
const props = defineProps<{
    externalIdentities: ExternalIdentitiesPage;
    filters: Filters;
    projects?: { id: number; name: string }[];
    roles?: SelectOption[];
    roleOptions?: SelectOption[];
    role_options?: SelectOption[];
    canManageExternalRoles?: boolean | null;
    can_manage_external_roles?: boolean | null;
    canManageLinkedAccounts?: boolean | null;
    can_manage_linked_accounts?: boolean | null;
}>();
const defaultSortBy: SortBy = 'newest';
const filtersOpen = ref(false);
const jsonHeaders = { headers: { Accept: 'application/json' } };
const sortOptions = [
    { value: 'newest', label: 'Newest' },
    { value: 'oldest', label: 'Oldest' },
    { value: 'name', label: 'Name' },
] satisfies { value: SortBy; label: string }[];
function normalizeSortBy(value: string | null | undefined): SortBy {
    return value === 'oldest' || value === 'name' ? value : defaultSortBy;
}
function normalizeProjectId(value: number | string | null | undefined) {
    return value === null || value === undefined || value === '' ? '' : String(value);
}
const appliedSearchTerm = ref(typeof props.filters.search === 'string' ? props.filters.search : '');
const appliedProjectId = ref(normalizeProjectId(props.filters.project_id));
const appliedSortBy = ref<SortBy>(normalizeSortBy(props.filters.sort_by));
const draftSearchTerm = ref(appliedSearchTerm.value);
const draftProjectId = ref(appliedProjectId.value);
const draftSortBy = ref<SortBy>(appliedSortBy.value);
const editingExternalIdentity = ref<ExternalIdentityRow | null>(null);
const editSaving = ref(false);
const editError = ref<string | null>(null);
const editErrors = ref<EditErrors>({});
const editForm = ref({
    name: '',
    email: '',
    role: '',
});
const indexPath = computed(() =>
    props.filters.organisation_id ? `/organisation/${props.filters.organisation_id}/project-participants` : '/dashboard',
);
const breadcrumbs = computed<BreadcrumbItem[]>(() => [{ title: 'Project participants', href: indexPath.value }]);
const projectFilterOptions = computed<SelectOption[]>(() => [
    { value: '', label: 'All projects' },
    ...(props.projects ?? []).map((project) => ({ value: String(project.id), label: project.name })),
]);
const roleOptions = computed<SelectOption[]>(() => props.roleOptions ?? props.role_options ?? props.roles ?? []);
const editSheetOpen = computed(() => Boolean(editingExternalIdentity.value));
const editDisabled = computed(() => editSaving.value || !editForm.value.name.trim());
const canManageExternalRoles = computed(() => props.canManageExternalRoles === true || props.can_manage_external_roles === true);
const canManageLinkedAccounts = computed(() => props.canManageLinkedAccounts === true || props.can_manage_linked_accounts === true);
const canEditExternalIdentityRole = computed(() => {
    if (!editingExternalIdentity.value || !canManageExternalRoles.value || roleOptions.value.length === 0) {
        return false;
    }

    return editingExternalIdentity.value.canManageRole !== false && editingExternalIdentity.value.can_manage_role !== false;
});
watch(
    () => props.filters,
    (next) => {
        appliedSearchTerm.value = typeof next.search === 'string' ? next.search : '';
        appliedProjectId.value = normalizeProjectId(next.project_id);
        appliedSortBy.value = normalizeSortBy(next.sort_by);
        draftSearchTerm.value = appliedSearchTerm.value;
        draftProjectId.value = appliedProjectId.value;
        draftSortBy.value = appliedSortBy.value;
    },
    { deep: true },
);
const activeFilterCount = computed(() => {
    let count = 0;
    if (appliedSearchTerm.value.trim()) count += 1;
    if (appliedProjectId.value) count += 1;
    if (appliedSortBy.value !== defaultSortBy) count += 1;
    return count;
});
const canResetFilters = computed(
    () =>
        activeFilterCount.value > 0 || Boolean(draftSearchTerm.value.trim()) || Boolean(draftProjectId.value) || draftSortBy.value !== defaultSortBy,
);
function queryParams(page = 1) {
    return {
        search: appliedSearchTerm.value.trim() || undefined,
        project_id: appliedProjectId.value || undefined,
        sort_by: appliedSortBy.value !== defaultSortBy ? appliedSortBy.value : undefined,
        page,
    };
}
function visitIndex(page = 1) {
    router.get(indexPath.value, queryParams(page), { preserveState: true, preserveScroll: true, replace: true });
}
function applyFilters() {
    appliedSearchTerm.value = draftSearchTerm.value;
    appliedProjectId.value = draftProjectId.value;
    appliedSortBy.value = draftSortBy.value;
    filtersOpen.value = false;
    visitIndex();
}
function resetFilters() {
    draftSearchTerm.value = '';
    draftProjectId.value = '';
    draftSortBy.value = defaultSortBy;
    appliedSearchTerm.value = '';
    appliedProjectId.value = '';
    appliedSortBy.value = defaultSortBy;
    filtersOpen.value = false;
    visitIndex();
}
function openEditSheet(externalIdentity: ExternalIdentityRow) {
    editingExternalIdentity.value = externalIdentity;
    editForm.value = {
        name: externalIdentity.name,
        email: externalIdentity.email ?? '',
        role: externalIdentity.role ?? '',
    };
    editErrors.value = {};
    editError.value = null;
}
function closeEditSheet() {
    editingExternalIdentity.value = null;
    editForm.value = {
        name: '',
        email: '',
        role: '',
    };
    editErrors.value = {};
    editError.value = null;
}
function normalizeValidationErrors(errors: Record<string, string[] | string> | undefined) {
    if (!errors) return {};
    return Object.fromEntries(Object.entries(errors).map(([key, value]) => [key, Array.isArray(value) ? (value[0] ?? '') : value])) as EditErrors;
}
function reloadProjectParticipants() {
    router.reload({ only: ['externalIdentities'] });
}
function handleLinkedAccountsChanged(externalIdentity: ExternalIdentityRow) {
    if (editingExternalIdentity.value?.id === externalIdentity.id) {
        editingExternalIdentity.value = externalIdentity;
    }

    reloadProjectParticipants();
}
async function saveExternalIdentity() {
    if (!editingExternalIdentity.value || editDisabled.value) return;
    editSaving.value = true;
    editErrors.value = {};
    editError.value = null;
    try {
        const payload: { name: string; email: string | null; role?: string | null } = {
            name: editForm.value.name.trim(),
            email: editForm.value.email.trim() || null,
        };
        if (canEditExternalIdentityRole.value) {
            payload.role = editForm.value.role || null;
        }
        await axios.put(`/project-participants/${editingExternalIdentity.value.id}`, payload, jsonHeaders);
        closeEditSheet();
        reloadProjectParticipants();
    } catch (error) {
        const response = (error as { response?: { status?: number; data?: { errors?: Record<string, string[] | string>; message?: string } } })
            .response;
        if (response?.status === 422) {
            editErrors.value = normalizeValidationErrors(response.data?.errors);
            editError.value = response.data?.message ?? null;
        } else {
            editError.value = 'Unable to save this application account right now.';
        }
    } finally {
        editSaving.value = false;
    }
}
function environmentLabel(environment?: string | null) {
    return environment?.trim() || 'Unknown';
}
function roleLabel(externalIdentity: ExternalIdentityRow) {
    const label = externalIdentity.role_label?.trim();
    if (label && !(externalIdentity.role === 'owner' && label.toLowerCase() === 'owner')) {
        return label;
    }
    const optionLabel = roleOptions.value.find((option) => option.value === externalIdentity.role)?.label;
    if (optionLabel && !(externalIdentity.role === 'owner' && optionLabel.toLowerCase() === 'owner')) {
        return optionLabel;
    }
    if (externalIdentity.role === 'owner') {
        return 'Client Owner';
    }
    return label || optionLabel || 'User';
}
</script>

<template>
    <Head title="Project participants" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <AdminListShell
                v-model:filtersOpen="filtersOpen"
                :active-filter-count="activeFilterCount"
                description="Each application account keeps its own role and profile. Link accounts to share ticket visibility within a project."
                filter-description="Search and sort application accounts."
                items-label="application accounts"
                :page="props.externalIdentities"
                title="Project participants"
                @page-change="visitIndex"
            >
                <template #filters>
                    <div class="space-y-2">
                        <label class="text-muted-foreground text-sm leading-none font-medium">Search</label>
                        <Input v-model="draftSearchTerm" data-testid="filter-search" placeholder="Search by name, email, or environment" />
                    </div>

                    <div v-if="projectFilterOptions.length > 1" class="space-y-2">
                        <label class="text-muted-foreground text-sm leading-none font-medium">Project</label>
                        <Select
                            v-model="draftProjectId"
                            :options="projectFilterOptions"
                            placeholder="All projects"
                            search-placeholder="Search projects..."
                            empty-label="No projects found."
                            searchable
                            test-id="filter-project"
                        />
                    </div>

                    <div class="space-y-2">
                        <label class="text-muted-foreground text-sm leading-none font-medium">Sort By</label>
                        <ButtonGroup
                            v-model="draftSortBy"
                            aria-label="Sort application accounts"
                            :columns="3"
                            :options="sortOptions"
                            test-id-prefix="sort-by"
                        />
                    </div>
                </template>

                <template #filter-actions>
                    <FilterResetButton :visible="canResetFilters" @click="resetFilters" />
                    <Button class="ml-auto" data-testid="filters-apply" variant="default" @click="applyFilters">Apply</Button>
                </template>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Application account</TableHead>
                            <TableHead>Project and environment</TableHead>
                            <TableHead>Role</TableHead>
                            <TableHead class="text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <template v-if="props.externalIdentities.data.length">
                            <TableRow
                                v-for="externalIdentity in props.externalIdentities.data"
                                :key="externalIdentity.id"
                                data-list-row
                                :data-testid="`external-identity-row-${externalIdentity.id}`"
                            >
                                <TableCell class="min-w-[14rem] whitespace-normal" :data-testid="`external-identity-profile-${externalIdentity.id}`">
                                    <div class="min-w-0">
                                        <div class="truncate font-medium">{{ externalIdentity.name }}</div>
                                        <div class="text-muted-foreground truncate text-sm">{{ externalIdentity.email || 'No email' }}</div>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <div
                                        class="flex flex-col items-start gap-1"
                                        :data-testid="`external-identity-project-environment-${externalIdentity.id}`"
                                    >
                                        <Badge v-if="externalIdentity.project" class="bg-sky-100 text-sky-900 hover:bg-sky-100" variant="secondary">
                                            {{ externalIdentity.project.name }}
                                        </Badge>
                                        <span v-else class="text-muted-foreground text-sm">No project assigned</span>
                                        <Badge variant="secondary">
                                            {{ environmentLabel(externalIdentity.environment) }}
                                        </Badge>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <Badge variant="outline" :data-testid="`external-identity-role-${externalIdentity.id}`">
                                        {{ roleLabel(externalIdentity) }}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    <div class="flex justify-end">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            data-row-action
                                            :data-testid="`external-identity-edit-${externalIdentity.id}`"
                                            title="Edit application account"
                                            @click="openEditSheet(externalIdentity)"
                                        >
                                            <Pencil class="h-4 w-4" />
                                            <span class="sr-only">Edit application account</span>
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </template>
                        <TableEmpty v-else :colspan="4">No application accounts found.</TableEmpty>
                    </TableBody>
                </Table>
            </AdminListShell>
        </div>

        <Sheet :open="editSheetOpen" @update:open="(open) => !open && closeEditSheet()">
            <SheetContent class="p-0" side="right">
                <SheetHeader class="border-b px-6 py-5">
                    <SheetTitle>Edit application account</SheetTitle>
                    <SheetDescription v-if="editingExternalIdentity">
                        {{ editingExternalIdentity.name }}
                        <span v-if="editingExternalIdentity.email">({{ editingExternalIdentity.email }})</span>
                    </SheetDescription>
                </SheetHeader>

                <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="saveExternalIdentity">
                    <div class="flex flex-1 flex-col gap-4 overflow-y-auto px-6 py-5">
                        <div class="space-y-2">
                            <label class="text-muted-foreground text-sm leading-none font-medium" for="external-identity-edit-name">Name</label>
                            <Input
                                id="external-identity-edit-name"
                                v-model="editForm.name"
                                data-testid="external-identity-edit-name"
                                :disabled="editSaving"
                                required
                            />
                            <p v-if="editErrors.name" class="text-destructive text-sm">{{ editErrors.name }}</p>
                        </div>

                        <div class="space-y-2">
                            <label class="text-muted-foreground text-sm leading-none font-medium" for="external-identity-edit-email">Email</label>
                            <Input
                                id="external-identity-edit-email"
                                v-model="editForm.email"
                                data-testid="external-identity-edit-email"
                                :disabled="editSaving"
                                type="email"
                            />
                            <p v-if="editErrors.email" class="text-destructive text-sm">{{ editErrors.email }}</p>
                        </div>

                        <div class="space-y-2">
                            <label class="text-muted-foreground text-sm leading-none font-medium">Project</label>
                            <div class="rounded-lg border p-3 text-sm" data-testid="external-identity-project-context">
                                <div class="font-medium">{{ editingExternalIdentity?.project?.name ?? 'No project assigned' }}</div>
                                <div class="text-muted-foreground mt-1">{{ environmentLabel(editingExternalIdentity?.environment) }}</div>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <label class="text-muted-foreground text-sm leading-none font-medium">Role</label>
                            <Select
                                v-if="canEditExternalIdentityRole"
                                v-model="editForm.role"
                                :disabled="editSaving"
                                empty-label="No roles found."
                                :options="roleOptions"
                                placeholder="Select a role"
                                test-id="external-identity-edit-role"
                            />
                            <div v-else class="rounded-lg border p-3 text-sm" data-testid="external-identity-role-readonly">
                                <span class="font-medium">{{ editingExternalIdentity ? roleLabel(editingExternalIdentity) : 'User' }}</span>
                            </div>
                            <p v-if="editErrors.role" class="text-destructive text-sm">{{ editErrors.role }}</p>
                        </div>

                        <LinkedAccountsManager
                            v-if="editingExternalIdentity"
                            :can-manage-linked-accounts="canManageLinkedAccounts"
                            :external-identity="editingExternalIdentity"
                            @changed="handleLinkedAccountsChanged"
                        />

                        <p v-if="editError" class="text-destructive text-sm">{{ editError }}</p>
                    </div>

                    <SheetFooter class="border-t sm:flex-row sm:justify-end">
                        <Button type="button" variant="outline" :disabled="editSaving" @click="closeEditSheet">Cancel</Button>
                        <Button type="submit" :disabled="editDisabled" data-testid="external-identity-edit-save">
                            {{ editSaving ? 'Saving...' : 'Save changes' }}
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    </AppLayout>
</template>
