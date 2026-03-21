<script setup lang="ts">
import TaskCreateSheet from '@/components/tasks/TaskCreateSheet.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { Filter, Pencil, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';
import { getTaskEnvironment } from '@/shared/tasks/metadata';
import type { TaskProjectOption } from '@/shared/tasks/projects';
import { getPriorityBadgeClass, getPriorityLabel, getStatusBadgeClass, getStatusLabel } from '@/shared/tasks/presentation';

const props = defineProps<{
    filters: any;
    editState: any;
    projects?: TaskProjectOption[];
    state: any;
}>();
const filters = props.filters;
const editState = props.editState;
const projects = props.projects;
const state = props.state;
const draftEnvironmentTermModel = computed({
    get: () => filters.draftEnvironmentTerm,
    set: (value: string) => filters.setDraftEnvironmentTerm(value),
});
const draftPrioritiesModel = computed({
    get: () => filters.draftPriorities,
    set: (value: string[]) => filters.setDraftPriorities(value),
});
const draftSearchTermModel = computed({
    get: () => filters.draftSearchTerm,
    set: (value: string) => filters.setDraftSearchTerm(value),
});
const draftSortByModel = computed({
    get: () => filters.draftSortBy,
    set: (value: string) => filters.setDraftSortBy(value),
});
const draftStatusesModel = computed({
    get: () => filters.draftStatuses,
    set: (value: string[]) => filters.setDraftStatuses(value),
});
const filtersOpenModel = computed({
    get: () => filters.filtersOpen,
    set: (value: boolean) => filters.setFiltersOpen(value),
});
</script>

<template>
    <Card class="w-full">
        <CardHeader class="flex flex-row items-center justify-between space-y-0">
            <CardTitle class="flex items-center gap-2">
                Tasks
                <Badge v-if="filters.activeFilterCount > 0" variant="secondary">{{ filters.activeFilterCount }} filters</Badge>
            </CardTitle>
            <div class="flex items-center gap-2">
                <Sheet v-model:open="filtersOpenModel">
                    <SheetTrigger as-child>
                        <Button data-testid="filters-trigger" size="sm" variant="outline">
                            <Filter class="mr-2 h-4 w-4" />
                            Filters
                        </Button>
                    </SheetTrigger>
                    <SheetContent class="flex h-full flex-col p-0" side="right" width-preset="task">
                        <SheetHeader class="p-0">
                            <div class="px-6 pt-6 pb-3">
                                <SheetTitle>Filters</SheetTitle>
                                <SheetDescription class="text-muted-foreground mt-1 text-sm">Filter tasks by status, priority, and search.</SheetDescription>
                            </div>
                        </SheetHeader>

                        <div class="flex-1 overflow-auto px-6 pb-4">
                            <div class="space-y-4">
                                <div class="space-y-2">
                                    <label class="flex items-center gap-2 text-sm leading-none font-medium select-none">Search</label>
                                    <input
                                        v-model="draftSearchTermModel"
                                        class="file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground dark:bg-input/30 border-input flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                                        placeholder="Search by title"
                                        type="text"
                                        data-testid="task-filter-search"
                                    />
                                </div>

                                <div class="space-y-2">
                                    <div class="flex items-center justify-between">
                                        <label class="flex items-center gap-2 text-sm leading-none font-medium select-none">Status</label>
                                        <button class="text-primary text-xs font-medium" type="button" @click="filters.selectAllStatuses">All</button>
                                    </div>
                                    <ButtonGroup
                                        v-model="draftStatusesModel"
                                        :aria-label="'Statuses'"
                                        :columns="2"
                                        :options="filters.statusOptions"
                                        test-id-prefix="task-filter-status"
                                        multiple
                                    />
                                </div>

                                <div class="space-y-2">
                                    <div class="flex items-center justify-between">
                                        <label class="flex items-center gap-2 text-sm leading-none font-medium select-none">Priority</label>
                                        <button class="text-primary text-xs font-medium" type="button" @click="filters.selectAllPriorities">All</button>
                                    </div>
                                    <ButtonGroup
                                        v-model="draftPrioritiesModel"
                                        :aria-label="'Priorities'"
                                        :columns="2"
                                        :options="filters.priorityOptions"
                                        test-id-prefix="task-filter-priority"
                                        multiple
                                    />
                                </div>

                                <div class="space-y-2">
                                    <label class="flex items-center gap-2 text-sm leading-none font-medium select-none">Environment</label>
                                    <input
                                        v-model="draftEnvironmentTermModel"
                                        class="file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground dark:bg-input/30 border-input flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                                        placeholder="Environment"
                                        type="text"
                                        data-testid="task-filter-environment"
                                    />
                                </div>

                                <div class="space-y-2">
                                    <label class="flex items-center gap-2 text-sm leading-none font-medium select-none">Sort</label>
                                    <select
                                        v-model="draftSortByModel"
                                        class="file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground dark:bg-input/30 border-input flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                                        data-testid="task-filter-sort"
                                    >
                                        <option v-for="option in filters.sortByOptions" :key="option.value" :value="option.value">
                                            {{ option.label }}
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <SheetFooter class="flex flex-row items-center justify-between border-t px-6 py-4">
                            <Button type="button" variant="outline" @click="filters.resetFilters">Reset</Button>
                            <Button type="button" variant="default" @click="filters.applyFilters">Apply</Button>
                        </SheetFooter>
                    </SheetContent>
                </Sheet>

                <TaskCreateSheet :projects="projects" @created="state.handleTaskCreated" />
            </div>
        </CardHeader>

        <CardContent class="space-y-4">
            <div v-if="state.error" class="text-destructive text-sm">{{ state.error }}</div>

            <div v-if="state.taskRows.length" class="overflow-hidden rounded-md border">
                <div
                    v-for="task in state.taskRows"
                    :key="task.id"
                    :class="state.highlightedTaskId === task.id ? 'ring-primary ring-2 ring-inset' : ''"
                    class="flex items-center justify-between gap-3 border-b px-4 py-3 last:border-b-0"
                    data-testid="task-row"
                >
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="truncate font-medium">{{ task.title }}</span>
                            <Badge :class="getStatusBadgeClass(task.status)" :data-testid="`task-status-badge-${task.id}`" variant="secondary">
                                {{ getStatusLabel(task.status) }}
                            </Badge>
                            <Badge :class="getPriorityBadgeClass(task.priority)" :data-testid="`task-priority-badge-${task.id}`" variant="outline">
                                {{ getPriorityLabel(task.priority) }}
                            </Badge>
                        </div>
                        <div :data-testid="`task-environment-badge-${task.id}`" class="text-muted-foreground text-sm">
                            {{ getTaskEnvironment(task) ?? 'Unknown' }}
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <Button type="button" variant="outline" title="Edit" @click="editState.openEdit(task.id)">
                            <Pencil class="h-4 w-4" />
                        </Button>
                        <Button :disabled="state.deleteLoading === task.id" type="button" variant="destructive" title="Delete" @click="state.deleteTask(task.id)">
                            <Trash2 class="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            </div>

            <div v-else class="text-muted-foreground py-8 text-center text-sm">No tasks found.</div>

            <div class="flex items-center justify-between">
                <div class="text-muted-foreground text-sm">
                    Showing {{ state.tasksPage.from ?? 0 }}-{{ state.tasksPage.to ?? 0 }} of {{ state.tasksPage.total }}
                </div>
                <div class="flex items-center gap-2">
                    <Button :disabled="state.tasksPage.current_page <= 1" type="button" variant="outline" @click="state.goToPage(state.tasksPage.current_page - 1)">
                        Prev
                    </Button>
                    <Button
                        :disabled="state.tasksPage.current_page >= state.tasksPage.last_page"
                        type="button"
                        variant="outline"
                        @click="state.goToPage(state.tasksPage.current_page + 1)"
                    >
                        Next
                    </Button>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
