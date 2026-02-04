<script lang="ts" setup>
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { OTable, OTableColumn } from '@oruga-ui/oruga-next';
import debounce from 'lodash/debounce';
import { onMounted, ref, watch } from 'vue';

const props = defineProps({
    users: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        required: true,
    },
});

const localUsers = ref({ ...props.users });

watch(
    () => props.users,
    (newUsers) => {
        localUsers.value = { ...newUsers };
    },
    { deep: true },
);

onMounted(() => {
    localUsers.value = { ...props.users };
});

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Users',
        href: '/users',
    },
];

const search = ref(props.filters.search || '');
const title = `Users` + (search.value ? ` - ${search.value}` : '');

function onPageChange(page: number) {
    localUsers.value.current_page = page;

    router.get(
        '/users',
        { page, search: search.value },
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
}

watch(search, (value) =>
    debounce(() => {
        router.get(
            '/users',
            {
                search: value,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }, 300)(),
);

function formatDate(value?: string) {
    if (!value) return '-';
    return new Date(value).toLocaleDateString();
}
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <div class="flex flex-wrap gap-4">
                <Input v-model="search" class="mb-4 rounded border p-2" placeholder="Search..." type="text" />
            </div>

            <o-table
                :current-page="localUsers.current_page"
                :data="localUsers.data"
                :paginated="true"
                :per-page="localUsers.per_page"
                :total="localUsers.total"
                backend-pagination
                @page-change="onPageChange"
            >
                <o-table-column v-slot="{ row }" field="name" label="Name">
                    {{ row.name }}
                </o-table-column>

                <o-table-column v-slot="{ row }" field="email" label="Email">
                    {{ row.email }}
                </o-table-column>

                <o-table-column v-slot="{ row }" field="email_verified_at" label="Verified">
                    <span v-if="row.email_verified_at" class="rounded bg-green-100 px-2 py-1 text-xs font-medium text-green-800">
                        Verified
                    </span>
                    <span v-else class="rounded bg-amber-100 px-2 py-1 text-xs font-medium text-amber-800">
                        Unverified
                    </span>
                </o-table-column>

                <o-table-column v-slot="{ row }" field="created_at" label="Created">
                    {{ formatDate(row.created_at) }}
                </o-table-column>
            </o-table>
        </div>
    </AppLayout>
</template>
