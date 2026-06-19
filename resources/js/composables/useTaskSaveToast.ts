import { ref } from 'vue';
import { toast } from 'vue-sonner';

export function useTaskSaveToast() {
    const taskSaveToastId = ref<string | number | null>(null);

    function showTaskSavingToast() {
        if (taskSaveToastId.value !== null) return;
        taskSaveToastId.value = toast.loading('Saving task changes...');
    }

    function showTaskSaveResultToast(success: boolean, message?: string) {
        const id = taskSaveToastId.value ?? undefined;
        taskSaveToastId.value = null;

        if (success) {
            toast.success('Task changes saved', { id, duration: 1400 });
            return;
        }

        toast.error('Failed to save task changes', { id, description: message ?? 'Unknown error', duration: 4000 });
    }

    function dismissTaskSaveToast() {
        if (taskSaveToastId.value === null) return;

        toast.dismiss(taskSaveToastId.value);
        taskSaveToastId.value = null;
    }

    return {
        dismissTaskSaveToast,
        showTaskSaveResultToast,
        showTaskSavingToast,
    };
}
