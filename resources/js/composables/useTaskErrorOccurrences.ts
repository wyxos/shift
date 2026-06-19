import type { TaskErrorOccurrence, TaskErrorOccurrencePagination } from '@/shared/tasks/types';
import axios from 'axios';
import { ref } from 'vue';

export function useTaskErrorOccurrences() {
    const activeErrorThreadTab = ref<'comments' | 'occurrences'>('comments');
    const errorOccurrences = ref<TaskErrorOccurrence[]>([]);
    const errorOccurrencesPagination = ref<TaskErrorOccurrencePagination | null>(null);
    const errorOccurrencesLoading = ref(false);
    const errorOccurrencesError = ref<string | null>(null);

    function errorMessage(error: any, fallback: string): string {
        return error?.response?.data?.error || error?.response?.data?.message || error?.message || fallback;
    }

    function resetErrorOccurrences() {
        activeErrorThreadTab.value = 'comments';
        errorOccurrences.value = [];
        errorOccurrencesPagination.value = null;
        errorOccurrencesLoading.value = false;
        errorOccurrencesError.value = null;
    }

    function setActiveErrorThreadTab(tab: 'comments' | 'occurrences') {
        activeErrorThreadTab.value = tab;
    }

    async function fetchErrorOccurrences(taskId: number, page = 1) {
        errorOccurrencesLoading.value = true;
        errorOccurrencesError.value = null;

        try {
            const response = await axios.get(route('task-error-occurrences.index', { task: taskId }), { params: { page } });
            errorOccurrences.value = Array.isArray(response.data?.occurrences) ? response.data.occurrences : [];
            errorOccurrencesPagination.value = response.data?.pagination ?? null;
        } catch (error: any) {
            errorOccurrencesError.value = errorMessage(error, 'Failed to load occurrences');
        } finally {
            errorOccurrencesLoading.value = false;
        }
    }

    return {
        activeErrorThreadTab,
        errorOccurrences,
        errorOccurrencesError,
        errorOccurrencesLoading,
        errorOccurrencesPagination,
        fetchErrorOccurrences,
        resetErrorOccurrences,
        setActiveErrorThreadTab,
    };
}
