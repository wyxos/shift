<script lang="ts" setup>
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { OTable, OTableColumn } from '@oruga-ui/oruga-next';
import debounce from 'lodash/debounce';
import { onMounted, ref, watch } from 'vue';
// Alert dialog components are used in DeleteDialog.vue
import DeleteDialog from '@/components/DeleteDialog.vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';

const props = defineProps({
    tasks: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        required: true,
    },
    projects: {
        type: Array,
        required: true,
    },
});

// Create a reactive copy of the tasks data
const localTasks = ref({ ...props.tasks });

// Update local tasks when props change
watch(
    () => props.tasks,
    (newTasks) => {
        localTasks.value = { ...newTasks };
    },
    { deep: true },
);

// Initialize local tasks on component mount
onMounted(() => {
    localTasks.value = { ...props.tasks };
});

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Tasks',
        href: '/tasks',
    },
];

const search = ref(props.filters.search);
const projectId = ref(props.filters.project_id);
const priority = ref(props.filters.priority);
// Normalize incoming status filter to an array; default to all statuses checked
const statusOptions = [
    { value: 'pending', label: 'Pending', class: 'bg-yellow-100 text-yellow-800' },
    { value: 'in-progress', label: 'In Progress', class: 'bg-blue-100 text-blue-800' },
    { value: 'completed', label: 'Completed', class: 'bg-green-100 text-green-800' },
    { value: 'awaiting-feedback', label: 'Awaiting Feedback', class: 'bg-purple-100 text-purple-800' },
];
const selectedStatuses = ref(
    Array.isArray(props.filters.status)
        ? (props.filters.status.length ? props.filters.status : statusOptions.map(o => o.value))
        : (props.filters.status ? [props.filters.status] : statusOptions.map(o => o.value))
);

const title = `Tasks` + (search.value ? ` - ${search.value}` : '');

function openDeleteModal(task: { id: number; name: string }) {
    deleteForm.id = task.id;
    deleteForm.isActive = true;
}

const deleteForm = useForm<{
    id: number | null;
    isActive: boolean;
}>({
    id: null,
    isActive: false,
});

function confirmDelete() {
    if (deleteForm.id) {
        router.delete(`/tasks/${deleteForm.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                deleteForm.isActive = false;
            },
        });
    }
}

function onPageChange(page: number) {
    // Update the current page in local tasks
    localTasks.value.current_page = page;

    // Use router to navigate to the new page
    router.get(
        '/tasks',
        { page, search: search.value },
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                // Refresh the task list to ensure we have the latest data
                refreshTaskList();
            },
        },
    );
}

function reset() {
    search.value = '';
    projectId.value = '';
    priority.value = '';
    selectedStatuses.value = statusOptions.map(o => o.value);
    router.get(
        '/tasks',
        { search: '', project_id: '', priority: '', status: '' },
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                // Refresh the task list to ensure we have the latest data
                refreshTaskList();
            },
        },
    );
}

// Watch for changes in search input
watch(search, (value) =>
    debounce(() => {
        router.get(
            '/tasks',
            {
                search: value,
                project_id: projectId.value,
                priority: priority.value,
                status: selectedStatuses.value,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onSuccess: () => {
                    // Refresh the task list to ensure we have the latest data
                    refreshTaskList();
                },
            },
        );
    }, 300)(),
);

// Watch for changes in project filter
watch(projectId, (value) =>
    debounce(() => {
        router.get(
            '/tasks',
            {
                search: search.value,
                project_id: value,
                priority: priority.value,
                status: selectedStatuses.value,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onSuccess: () => {
                    refreshTaskList();
                },
            },
        );
    }, 300)(),
);

// Watch for changes in priority filter
watch(priority, (value) =>
    debounce(() => {
        router.get(
            '/tasks',
            {
                search: search.value,
                project_id: projectId.value,
                priority: value,
                status: selectedStatuses.value,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onSuccess: () => {
                    refreshTaskList();
                },
            },
        );
    }, 300)(),
);

// Watch for changes in status checkboxes
watch(selectedStatuses, (values) =>
    debounce(() => {
        router.get(
            '/tasks',
            {
                search: search.value,
                project_id: projectId.value,
                priority: priority.value,
                status: values,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onSuccess: () => {
                    refreshTaskList();
                },
            },
        );
    }, 300)(),
    { deep: true }
);

// Status options

// Priority options
const priorityOptions = [
    { value: 'low', label: 'Low', class: 'bg-gray-100 text-gray-800' },
    { value: 'medium', label: 'Medium', class: 'bg-orange-100 text-orange-800' },
    { value: 'high', label: 'High', class: 'bg-red-100 text-red-800' },
];

// Function to update task status
function updateTaskStatus(task, status) {
    // Call the API endpoint to update status using Inertia
    router.patch(
        `/tasks/${task.id}/toggle-status`,
        {
            status: status,
        },
        {
            preserveScroll: true,
            onSuccess: (response) => {
                // Update the task status in the local data
                const taskIndex = localTasks.value.data.findIndex((t) => t.id === task.id);
                if (taskIndex !== -1) {
                    localTasks.value.data[taskIndex].status = response.props.status;
                }

                // Refresh the task list to ensure changes persist
                router.reload({ only: ['tasks'] });
            },
            onError: (error) => {
                console.error('Error updating task status:', error);
            },
        },
    );
}

// Function to refresh the task list
function refreshTaskList() {
    const currentPage = localTasks.value.current_page || 1;
    const currentSearch = search.value || '';
    const currentProjectId = projectId.value || '';
    const currentPriority = priority.value || '';
    const currentStatuses = selectedStatuses.value && selectedStatuses.value.length ? selectedStatuses.value : '';

    // Use Inertia router to reload the tasks data
    router.reload({
        only: ['tasks'],
        data: {
            page: currentPage,
            search: currentSearch,
            project_id: currentProjectId,
            priority: currentPriority,
            status: currentStatuses,
        },
        preserveScroll: true,
        onError: (error) => {
            console.error('Error refreshing task list:', error);
        },
    });
}

// Function to update task priority
function updateTaskPriority(task, priority) {
    // Call the API endpoint to update priority using Inertia
    router.patch(
        `/tasks/${task.id}/toggle-priority`,
        {
            priority: priority,
        },
        {
            preserveScroll: true,
            onSuccess: (response) => {
                // Update the task priority in the local data
                const taskIndex = localTasks.value.data.findIndex((t) => t.id === task.id);
                if (taskIndex !== -1) {
                    localTasks.value.data[taskIndex].priority = response.props.priority;
                }

                // Refresh the task list to ensure changes persist
                router.reload({ only: ['tasks'] });
            },
            onError: (error) => {
                console.error('Error updating task priority:', error);
            },
        },
    );
}
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <div class="flex flex-wrap gap-4">
                <Input v-model="search" class="mb-4 rounded border p-2" placeholder="Search..." type="text" />

                <!-- Project filter -->
                <select v-model="projectId" class="mb-4 rounded border p-2">
                    <option value="">All Projects</option>
                    <option v-for="project in projects" :key="project.id" :value="project.id">
                        {{ project.name }}
                    </option>
                </select>

                <!-- Priority filter -->
                <select v-model="priority" class="mb-4 rounded border p-2">
                    <option value="">All Priorities</option>
                    <option v-for="option in priorityOptions" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>

                <!-- Status filter checkboxes -->
                <div class="mb-4 flex flex-wrap items-center gap-4">
                    <span class="text-sm text-gray-700">Status:</span>
                    <label v-for="option in statusOptions" :key="option.value" class="flex items-center gap-2">
                        <input type="checkbox" :value="option.value" v-model="selectedStatuses" />
                        <span>{{ option.label }}</span>
                    </label>
                </div>

                <Button @click="reset">Reset</Button>

                <Button @click="router.get('/tasks/create')"><i class="fas fa-plus"></i> Add Task</Button>
            </div>

            <o-table
                :current-page="localTasks.current_page"
                :data="localTasks.data"
                :paginated="true"
                :per-page="localTasks.per_page"
                :total="localTasks.total"
                backend-pagination
                @page-change="onPageChange"
            >
                <o-table-column v-slot="{ row }" field="title" label="Title">
                    <div>
                        {{ row.title }}
                        <div v-if="row.is_external" class="mt-1">
                            <span class="rounded bg-purple-100 px-2 py-1 text-xs font-medium text-purple-800"> External Submission </span>
                        </div>
                    </div>
                </o-table-column>
                <o-table-column v-slot="{ row }" field="submitter" label="Submitter">
                    <div v-if="row.is_external && row.submitter">
                        <div class="text-sm font-medium">{{ row.submitter.name }}</div>
                        <div v-if="row.is_external" class="text-xs text-gray-500">
                            <span>{{ row.submitter.email }}</span>
                            <div v-if="row.metadata && row.metadata.environment">env: {{ row.metadata.environment }}</div>
                            <a v-if="row.metadata && row.metadata.url" :href="row.metadata.url" class="text-blue-500 hover:underline" target="_blank">
                                {{ row.metadata.url }}
                            </a>
                        </div>
                    </div>
                    <div v-else-if="row.submitter">
                        <div class="text-sm font-medium">{{ row.submitter.name }}</div>
                        <div class="text-xs text-gray-500">Shift User</div>
                    </div>
                    <div v-else>
                        <div class="text-xs text-gray-500">Automated</div>
                    </div>
                </o-table-column>
                <o-table-column v-slot="{ row }" field="status" label="Status">
                    <span
                        :class="{
                            'bg-yellow-100 text-yellow-800': row.status === 'pending',
                            'bg-blue-100 text-blue-800': row.status === 'in-progress',
                            'bg-green-100 text-green-800': row.status === 'completed',
                            'bg-purple-100 text-purple-800': row.status === 'awaiting-feedback',
                        }"
                        class="rounded px-2 py-1 text-xs font-medium"
                    >
                        {{ row.status.replace('_', ' ').replace('-', ' ') }}
                    </span>
                    <DropdownMenu>
                        <DropdownMenuTrigger>
                            <Button class="ml-2" size="sm" variant="ghost">
                                <i class="fas fa-chevron-down"></i>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent>
                            <DropdownMenuItem v-for="option in statusOptions" :key="option.value" @click="updateTaskStatus(row, option.value)">
                                <span :class="option.class" class="mr-2 rounded px-2 py-1 text-xs font-medium">
                                    {{ option.label }}
                                </span>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </o-table-column>
                <o-table-column v-slot="{ row }" field="priority" label="Priority">
                    <span
                        :class="{
                            'bg-gray-100 text-gray-800': row.priority === 'low',
                            'bg-orange-100 text-orange-800': row.priority === 'medium',
                            'bg-red-100 text-red-800': row.priority === 'high',
                        }"
                        class="rounded px-2 py-1 text-xs font-medium"
                    >
                        {{ row.priority }}
                    </span>
                    <DropdownMenu>
                        <DropdownMenuTrigger>
                            <Button class="ml-2" size="sm" variant="ghost">
                                <i class="fas fa-chevron-down"></i>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent>
                            <DropdownMenuItem v-for="option in priorityOptions" :key="option.value" @click="updateTaskPriority(row, option.value)">
                                <span :class="option.class" class="mr-2 rounded px-2 py-1 text-xs font-medium">
                                    {{ option.label }}
                                </span>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </o-table-column>
                <o-table-column v-slot="{ row }" label="Actions">
                    <div class="flex justify-end gap-2">
                        <a :href="`/tasks/${row.id}/edit`" rel="noopener" aria-label="Edit task in new tab">
                            <Button variant="outline">
                                <i class="fas fa-edit"></i>
                            </Button>
                        </a>
                        <Button variant="destructive" @click="openDeleteModal(row)">
                            <i class="fas fa-trash"></i>
                        </Button>
                    </div>
                </o-table-column>

                <template #empty>
                    <div class="flex h-full items-center justify-center">
                        <p class="text-gray-500">No tasks found.</p>
                    </div>
                </template>
            </o-table>
        </div>

        <DeleteDialog :is-open="deleteForm.isActive" @cancel="deleteForm.isActive = false" @confirm="confirmDelete">
            <template #title> Delete Task</template>
            <template #description> Are you sure you want to delete this task? This action cannot be undone.</template>
            <template #cancel> Cancel</template>
            <template #confirm> Confirm</template>
        </DeleteDialog>
    </AppLayout>
</template>
