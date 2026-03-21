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
import { computed, ref, watch } from 'vue';

type UserRow = {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string | null;
    created_at?: string | null;
};

type UsersPage = {
    data: UserRow[];
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
    users: UsersPage;
    filters: Filters;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Users',
        href: '/users',
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

    router.get('/users', queryParams(), {
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

    router.get('/users', queryParams(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function goToPage(page: number) {
    router.get('/users', queryParams(page), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function formatDate(value?: string | null) {
    if (!value) return '-';
    return new Date(value).toLocaleDateString();
}
</script>

<template>
    <Head title="Users" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <AdminListShell
                v-model:filtersOpen="filtersOpen"
                :active-filter-count="activeFilterCount"
                description="Portal users with their verification status and join date."
                filter-description="Search and sort the users list."
                items-label="users"
                :page="props.users"
                title="Users"
                @page-change="goToPage"
            >
                <template #filters>
                    <div class="space-y-2">
                        <label class="text-muted-foreground text-sm leading-none font-medium">Search</label>
                        <Input v-model="draftSearchTerm" data-testid="filter-search" placeholder="Search by name or email" />
                    </div>

                    <div class="space-y-2">
                        <label class="text-muted-foreground text-sm leading-none font-medium">Sort By</label>
                        <ButtonGroup v-model="draftSortBy" aria-label="Sort users" :columns="3" :options="sortOptions" test-id-prefix="sort-by" />
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
                            <TableHead>Verified</TableHead>
                            <TableHead>Created</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <template v-if="props.users.data.length">
                            <TableRow v-for="user in props.users.data" :key="user.id" :data-testid="`user-row-${user.id}`">
                                <TableCell class="font-medium">{{ user.name }}</TableCell>
                                <TableCell>{{ user.email }}</TableCell>
                                <TableCell>
                                    <Badge
                                        :data-testid="`user-verification-${user.id}`"
                                        :class="
                                            user.email_verified_at
                                                ? 'bg-emerald-100 text-emerald-900 hover:bg-emerald-100'
                                                : 'bg-amber-100 text-amber-900 hover:bg-amber-100'
                                        "
                                        variant="secondary"
                                    >
                                        {{ user.email_verified_at ? 'Verified' : 'Unverified' }}
                                    </Badge>
                                </TableCell>
                                <TableCell>{{ formatDate(user.created_at) }}</TableCell>
                            </TableRow>
                        </template>
                        <TableEmpty v-else :colspan="4">No users found.</TableEmpty>
                    </TableBody>
                </Table>
            </AdminListShell>
        </div>
    </AppLayout>
</template>
