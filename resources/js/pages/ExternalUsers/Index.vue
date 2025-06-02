<script lang="ts" setup>
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { OTable, OTableColumn } from '@oruga-ui/oruga-next';
import debounce from 'lodash/debounce';
import { onMounted, ref, watch } from 'vue';
import { Input } from '@/components/ui/input';

const props = defineProps({
    externalUsers: {
        type: Object,
        required: true,
    },
});

// Create a reactive copy of the externalUsers data
const localExternalUsers = ref({ ...props.externalUsers });

// Update local externalUsers when props change
watch(
    () => props.externalUsers,
    (newExternalUsers) => {
        localExternalUsers.value = { ...newExternalUsers };
    },
    { deep: true },
);

// Initialize local externalUsers on component mount
onMounted(() => {
    localExternalUsers.value = { ...props.externalUsers };
});

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'External Users',
        href: '/external-users',
    },
];

const search = ref('');
const title = `External Users` + (search.value ? ` - ${search.value}` : '');

function onPageChange(page: number) {
    // Update the current page in local externalUsers
    localExternalUsers.value.current_page = page;

    // Use router to navigate to the new page
    router.get(
        '/external-users',
        { page, search: search.value },
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
}

// Watch for changes in search input
watch(search, (value) =>
    debounce(() => {
        router.get(
            '/external-users',
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
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <div class="flex flex-wrap gap-4">
                <Input v-model="search" class="mb-4 rounded border p-2" placeholder="Search..." type="text" />
            </div>

            <o-table
                :current-page="localExternalUsers.current_page"
                :data="localExternalUsers.data"
                :paginated="true"
                :per-page="localExternalUsers.per_page"
                :total="localExternalUsers.total"
                backend-pagination
                @page-change="onPageChange"
            >
                <o-table-column v-slot="{ row }" field="name" label="Name">
                    {{ row.name }}
                </o-table-column>

                <o-table-column v-slot="{ row }" field="email" label="Email">
                    {{ row.email }}
                </o-table-column>

                <o-table-column v-slot="{ row }" field="environment" label="Environment">
                    <span class="rounded bg-gray-100 px-2 py-1 text-xs font-medium text-gray-800">
                        {{ row.environment }}
                    </span>
                </o-table-column>

                <o-table-column v-slot="{ row }" field="project" label="Project">
                    <span v-if="row.project" class="rounded bg-blue-100 px-2 py-1 text-xs font-medium text-blue-800">
                        {{ row.project.name }}
                    </span>
                    <span v-else class="text-gray-500">
                        No project assigned
                    </span>
                </o-table-column>

                <o-table-column v-slot="{ row }" label="Actions">
                    <div class="flex justify-end gap-2">
                        <Button variant="outline" @click="router.visit(`/external-users/${row.id}/edit`)">
                            <i class="fas fa-edit"></i>
                        </Button>
                    </div>
                </o-table-column>

                <template #empty>
                    <div class="flex h-full items-center justify-center">
                        <p class="text-gray-500">No external users found.</p>
                    </div>
                </template>
            </o-table>
        </div>
    </AppLayout>
</template>
