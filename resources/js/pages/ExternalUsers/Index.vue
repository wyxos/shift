<script lang="ts" setup>
import AdminListShell from '@/components/admin/AdminListShell.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { Pencil } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

type ExternalUserRow = {
    id: number;
    name: string;
    email?: string | null;
    environment?: string | null;
    project?: {
        id: number;
        name: string;
    } | null;
};

type ExternalUsersPage = {
    data: ExternalUserRow[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

type Filters = {
    search?: string | null;
    sort_by?: string | null;
};

type SortBy = 'newest' | 'oldest' | 'name';

const props = defineProps<{
    externalUsers: ExternalUsersPage;
    filters: Filters;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'External Users',
        href: '/external-users',
    },
];

const defaultSortBy: SortBy = 'newest';
const filtersOpen = ref(false);
const sortOptions = [
    { value: 'newest', label: 'Newest' },
    { value: 'oldest', label: 'Oldest' },
    { value: 'name', label: 'Name' },
] satisfies { value: SortBy; label: string }[];

function normalizeSortBy(value: string | null | undefined): SortBy {
    if (value === 'oldest' || value === 'name') {
        return value;
    }

    return defaultSortBy;
}

const appliedSearchTerm = ref(typeof props.filters.search === 'string' ? props.filters.search : '');
const appliedSortBy = ref<SortBy>(normalizeSortBy(props.filters.sort_by));
const draftSearchTerm = ref(appliedSearchTerm.value);
const draftSortBy = ref<SortBy>(appliedSortBy.value);

watch(
    () => props.filters,
    (next) => {
        appliedSearchTerm.value = typeof next.search === 'string' ? next.search : '';
        appliedSortBy.value = normalizeSortBy(next.sort_by);
        draftSearchTerm.value = appliedSearchTerm.value;
        draftSortBy.value = appliedSortBy.value;
    },
    { deep: true },
);

const activeFilterCount = computed(() => {
    let count = 0;

    if (appliedSearchTerm.value.trim()) count += 1;
    if (appliedSortBy.value !== defaultSortBy) count += 1;

    return count;
});

function queryParams(page = 1) {
    return {
        search: appliedSearchTerm.value.trim() || undefined,
        sort_by: appliedSortBy.value !== defaultSortBy ? appliedSortBy.value : undefined,
        page,
    };
}

function applyFilters() {
    appliedSearchTerm.value = draftSearchTerm.value;
    appliedSortBy.value = draftSortBy.value;
    filtersOpen.value = false;

    router.get('/external-users', queryParams(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function resetFilters() {
    draftSearchTerm.value = '';
    draftSortBy.value = defaultSortBy;
    appliedSearchTerm.value = '';
    appliedSortBy.value = defaultSortBy;
    filtersOpen.value = false;

    router.get('/external-users', queryParams(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function goToPage(page: number) {
    router.get('/external-users', queryParams(page), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function editExternalUser(externalUserId: number) {
    router.visit(`/external-users/${externalUserId}/edit`);
}

function environmentLabel(environment?: string | null) {
    return environment?.trim() || 'Unknown';
}
</script>

<template>
    <Head title="External Users" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <AdminListShell
                v-model:filtersOpen="filtersOpen"
                :active-filter-count="activeFilterCount"
                description="External contacts grouped by project and environment."
                filter-description="Search and sort the external users list."
                items-label="external users"
                :page="props.externalUsers"
                title="External Users"
                @page-change="goToPage"
            >
                <template #filters>
                    <div class="space-y-2">
                        <label class="text-muted-foreground text-sm leading-none font-medium">Search</label>
                        <Input v-model="draftSearchTerm" data-testid="filter-search" placeholder="Search by name, email, or environment" />
                    </div>

                    <div class="space-y-2">
                        <label class="text-muted-foreground text-sm leading-none font-medium">Sort By</label>
                        <ButtonGroup
                            v-model="draftSortBy"
                            aria-label="Sort external users"
                            :columns="3"
                            :options="sortOptions"
                            test-id-prefix="sort-by"
                        />
                    </div>
                </template>

                <template #filter-actions>
                    <Button data-testid="filters-reset" variant="ghost" @click="resetFilters">Reset</Button>
                    <Button data-testid="filters-apply" variant="default" @click="applyFilters">Apply</Button>
                </template>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Name</TableHead>
                            <TableHead>Email</TableHead>
                            <TableHead>Environment</TableHead>
                            <TableHead>Project</TableHead>
                            <TableHead class="text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <template v-if="props.externalUsers.data.length">
                            <TableRow
                                v-for="externalUser in props.externalUsers.data"
                                :key="externalUser.id"
                                :data-testid="`external-user-row-${externalUser.id}`"
                            >
                                <TableCell class="font-medium">{{ externalUser.name }}</TableCell>
                                <TableCell>{{ externalUser.email || 'No email' }}</TableCell>
                                <TableCell>
                                    <Badge variant="secondary" :data-testid="`external-user-environment-${externalUser.id}`">
                                        {{ environmentLabel(externalUser.environment) }}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    <Badge v-if="externalUser.project" class="bg-sky-100 text-sky-900 hover:bg-sky-100" variant="secondary">
                                        {{ externalUser.project.name }}
                                    </Badge>
                                    <span v-else class="text-muted-foreground text-sm">No project assigned</span>
                                </TableCell>
                                <TableCell>
                                    <div class="flex justify-end">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            :data-testid="`external-user-edit-${externalUser.id}`"
                                            title="Edit external user"
                                            @click="editExternalUser(externalUser.id)"
                                        >
                                            <Pencil class="h-4 w-4" />
                                            <span class="sr-only">Edit external user</span>
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </template>
                        <TableEmpty v-else :colspan="5">No external users found.</TableEmpty>
                    </TableBody>
                </Table>
            </AdminListShell>
        </div>
    </AppLayout>
</template>
