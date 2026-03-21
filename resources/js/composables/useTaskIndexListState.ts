import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import type { SharedData } from '@/types';
import type { TaskPaginator } from '@/shared/tasks/types';

type UseTaskIndexListStateOptions = {
    tasks: TaskPaginator;
    buildListQuery: (page: number) => Record<string, unknown>;
};

export function useTaskIndexListState(options: UseTaskIndexListStateOptions) {
    const page = usePage<SharedData>();
    const aiImproveEnabled = computed(() => Boolean(page.props.shift?.ai_enabled));

    const tasksPage = ref<TaskPaginator>({ ...options.tasks });
    watch(
        () => options.tasks,
        (next) => {
            tasksPage.value = { ...next };
        },
        { deep: true },
    );

    const taskRows = computed(() => tasksPage.value.data ?? []);
    const error = ref<string | null>(null);
    const deleteLoading = ref<number | null>(null);
    const highlightedTaskId = ref<number | null>(null);
    let highlightTimer: number | null = null;

    function highlightTask(taskId: number) {
        highlightedTaskId.value = taskId;

        if (highlightTimer) {
            window.clearTimeout(highlightTimer);
        }

        highlightTimer = window.setTimeout(() => {
            highlightedTaskId.value = null;
            highlightTimer = null;
        }, 4500);
    }

    function goToPage(pageNumber: number) {
        const current = Number(tasksPage.value.current_page ?? 1);
        const last = Number(tasksPage.value.last_page ?? 1);
        const next = Math.max(1, Math.min(last, pageNumber));
        if (next === current) return;

        router.get('/tasks', options.buildListQuery(next), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    async function deleteTask(taskId: number) {
        if (!confirm('Are you sure you want to delete this task?')) return;

        deleteLoading.value = taskId;
        error.value = null;
        try {
            await axios.delete(route('tasks.v2.destroy', { task: taskId }));
            router.reload({ preserveScroll: true, preserveState: true });
        } catch (e: any) {
            error.value = e.response?.data?.error || e.response?.data?.message || e.message || 'Failed to delete task';
        } finally {
            deleteLoading.value = null;
        }
    }

    function handleTaskCreated(taskId: number | null) {
        router.reload({
            only: ['tasks', 'filters', 'projects'],
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                if (taskId !== null) {
                    highlightTask(taskId);
                }
            },
        });
    }

    return {
        aiImproveEnabled,
        deleteLoading,
        deleteTask,
        error,
        goToPage,
        handleTaskCreated,
        highlightedTaskId,
        highlightTask,
        taskRows,
        tasksPage,
    };
}
