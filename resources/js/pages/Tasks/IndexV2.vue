<script lang="ts" setup>
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Filter } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

type Task = {
    id: number;
    title: string;
    status: string;
    priority: string;
};

const props = defineProps<{
    tasks: Task[];
    filters: {
        status?: string[] | string | null;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tasks', href: '/tasks' },
    { title: 'Tasks V2', href: '/tasks-v2' },
];

const statusOptions = [
    { value: 'pending', label: 'Pending' },
    { value: 'in-progress', label: 'In Progress' },
    { value: 'awaiting-feedback', label: 'Awaiting Feedback' },
    { value: 'completed', label: 'Completed' },
];

const priorityOptions = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
];

const defaultStatuses = statusOptions.filter((option) => option.value !== 'completed').map((option) => option.value);

function normalizeStringList(value: unknown): string[] {
    if (Array.isArray(value)) return value.map(String).filter((item) => item.trim().length > 0);
    if (typeof value === 'string' && value.trim().length > 0) return [value.trim()];
    return [];
}

const filtersOpen = ref(false);
const searchTerm = ref('');

const providedStatuses = normalizeStringList(props.filters.status);
const selectedStatuses = ref<string[]>(providedStatuses.length ? providedStatuses : [...defaultStatuses]);

const selectedPriorities = ref<string[]>(priorityOptions.map((option) => option.value));

const activeFilterCount = computed(() => {
    let count = 0;
    if (selectedStatuses.value.length && selectedStatuses.value.length < statusOptions.length) count += 1;
    if (selectedPriorities.value.length && selectedPriorities.value.length < priorityOptions.length) count += 1;
    if (searchTerm.value.trim()) count += 1;
    return count;
});

function visitWithFilters() {
    router.get(
        '/tasks-v2',
        {
            status: selectedStatuses.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

watch(
    selectedStatuses,
    () => {
        visitWithFilters();
    },
    { deep: true },
);

function resetFilters() {
    selectedStatuses.value = [...defaultStatuses];
    selectedPriorities.value = priorityOptions.map((option) => option.value);
    searchTerm.value = '';
}

function selectAllStatuses() {
    selectedStatuses.value = statusOptions.map((option) => option.value);
}

function selectAllPriorities() {
    selectedPriorities.value = priorityOptions.map((option) => option.value);
}

const filteredTasks = computed(() => {
    let list = [...props.tasks];

    if (selectedStatuses.value.length === 0) return [];
    if (selectedStatuses.value.length < statusOptions.length) {
        list = list.filter((task) => selectedStatuses.value.includes(task.status));
    }

    if (selectedPriorities.value.length === 0) return [];
    if (selectedPriorities.value.length < priorityOptions.length) {
        list = list.filter((task) => selectedPriorities.value.includes(task.priority));
    }

    const query = searchTerm.value.trim().toLowerCase();
    if (query) {
        list = list.filter((task) => task.title.toLowerCase().includes(query));
    }

    return list;
});

const totalTasks = computed(() => props.tasks.length);

function statusVariant(status: string) {
    switch (status) {
        case 'pending':
            return 'secondary';
        case 'in-progress':
            return 'default';
        case 'completed':
            return 'outline';
        default:
            return 'secondary';
    }
}

function priorityVariant(priority: string) {
    switch (priority) {
        case 'high':
            return 'destructive';
        case 'medium':
            return 'default';
        default:
            return 'outline';
    }
}

function getStatusLabel(value: string) {
    return statusOptions.find((option) => option.value === value)?.label ?? value;
}

function getPriorityLabel(value: string) {
    return priorityOptions.find((option) => option.value === value)?.label ?? value;
}
</script>

<template>
    <Head title="Tasks V2" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <Card class="w-full">
                <CardHeader class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <CardTitle>Tasks V2</CardTitle>
                        <p class="text-muted-foreground text-sm">Default view hides completed tasks.</p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <Sheet v-model:open="filtersOpen">
                            <SheetTrigger as-child>
                                <Button variant="outline" size="sm" data-testid="filters-trigger">
                                    <Filter class="mr-2 h-4 w-4" />
                                    Filters
                                    <Badge v-if="activeFilterCount" variant="secondary" class="ml-2">
                                        {{ activeFilterCount }}
                                    </Badge>
                                </Button>
                            </SheetTrigger>
                            <SheetContent side="right" class="flex h-full w-[320px] flex-col p-0">
                                <SheetHeader class="p-0">
                                    <div class="px-6 pt-6 pb-3">
                                        <SheetTitle>Filters</SheetTitle>
                                        <SheetDescription class="text-muted-foreground mt-1 text-sm"> Refine your task list. </SheetDescription>
                                    </div>
                                </SheetHeader>

                                <div class="flex-1 space-y-6 overflow-auto px-6 pb-6">
                                    <div class="space-y-2">
                                        <Label>Search</Label>
                                        <Input v-model="searchTerm" data-testid="filter-search" placeholder="Search by title" />
                                    </div>

                                    <div class="space-y-2">
                                        <div class="flex items-center justify-between">
                                            <Label>Status</Label>
                                            <Button variant="ghost" size="sm" @click="selectAllStatuses">All</Button>
                                        </div>
                                        <div class="grid gap-2">
                                            <label v-for="option in statusOptions" :key="option.value" class="flex items-center gap-2 text-sm">
                                                <input
                                                    v-model="selectedStatuses"
                                                    type="checkbox"
                                                    :value="option.value"
                                                    :data-testid="`status-${option.value}`"
                                                />
                                                <span>{{ option.label }}</span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="space-y-2">
                                        <div class="flex items-center justify-between">
                                            <Label>Priority</Label>
                                            <Button variant="ghost" size="sm" @click="selectAllPriorities">All</Button>
                                        </div>
                                        <div class="grid gap-2">
                                            <label v-for="option in priorityOptions" :key="option.value" class="flex items-center gap-2 text-sm">
                                                <input
                                                    v-model="selectedPriorities"
                                                    type="checkbox"
                                                    :value="option.value"
                                                    :data-testid="`priority-${option.value}`"
                                                />
                                                <span>{{ option.label }}</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <SheetFooter class="flex flex-row items-center justify-between border-t px-6 py-4">
                                    <Button variant="ghost" @click="resetFilters">Reset</Button>
                                    <Button variant="default" @click="filtersOpen = false">Apply</Button>
                                </SheetFooter>
                            </SheetContent>
                        </Sheet>
                    </div>
                </CardHeader>

                <CardContent>
                    <div class="text-muted-foreground mb-4 flex flex-wrap items-center justify-between gap-2 text-xs">
                        <span> Showing {{ filteredTasks.length }} of {{ totalTasks }} tasks </span>
                        <span v-if="activeFilterCount">{{ activeFilterCount }} filter{{ activeFilterCount === 1 ? '' : 's' }} active</span>
                    </div>

                    <div v-if="filteredTasks.length === 0" class="text-muted-foreground py-8 text-center">No tasks found</div>

                    <ul v-else class="divide-border divide-y">
                        <li
                            v-for="task in filteredTasks"
                            :key="task.id"
                            data-testid="task-row"
                            class="flex flex-col gap-3 py-4 transition sm:flex-row sm:items-center sm:gap-4"
                        >
                            <div class="flex-1">
                                <div class="text-card-foreground text-lg font-medium">{{ task.title }}</div>
                                <div class="text-muted-foreground mt-1 flex flex-wrap items-center gap-2 text-xs">
                                    <Badge :variant="statusVariant(task.status)">{{ getStatusLabel(task.status) }}</Badge>
                                    <Badge :variant="priorityVariant(task.priority)">{{ getPriorityLabel(task.priority) }}</Badge>
                                </div>
                            </div>
                            <div class="flex items-center justify-end gap-2">
                                <Link :href="`/tasks/${task.id}/edit`" class="text-sm underline">Open</Link>
                            </div>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
