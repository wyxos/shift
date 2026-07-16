import { type ProjectAppErrorNotificationSettings, type ProjectRow } from '@/components/admin/projects/project-shared';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { ref } from 'vue';

export function useProjectAppErrorNotificationsState() {
    const appErrorNotificationsOpen = ref(false);
    const appErrorNotificationsLoading = ref(false);
    const appErrorNotificationsLoaded = ref(false);
    const appErrorNotificationsError = ref<string | null>(null);
    const appErrorNotificationsForm = ref({
        project_id: null as number | null,
        project_name: '',
        selected_user_ids: [] as number[],
        users: [] as ProjectAppErrorNotificationSettings['users'],
    });
    let appErrorNotificationsRequestId = 0;

    async function openAppErrorNotificationsModal(project: ProjectRow) {
        const requestId = ++appErrorNotificationsRequestId;
        appErrorNotificationsForm.value = {
            project_id: project.id,
            project_name: project.name,
            selected_user_ids: [],
            users: [],
        };
        appErrorNotificationsError.value = null;
        appErrorNotificationsLoaded.value = false;
        appErrorNotificationsOpen.value = true;
        appErrorNotificationsLoading.value = true;

        try {
            const response = await axios.get<ProjectAppErrorNotificationSettings>(`/projects/${project.id}/app-error-notifications`, {
                headers: { Accept: 'application/json' },
            });

            if (!isCurrentAppErrorNotificationsRequest(requestId, project.id)) return;

            appErrorNotificationsForm.value.selected_user_ids = (response.data.selected_user_ids ?? []).map(Number);
            appErrorNotificationsForm.value.users = response.data.users ?? [];
            appErrorNotificationsLoaded.value = true;
        } catch (error) {
            if (!isCurrentAppErrorNotificationsRequest(requestId, project.id)) return;

            console.error('Error loading app error notification settings:', error);
            appErrorNotificationsError.value = 'Unable to load notification settings right now.';
        } finally {
            if (isCurrentAppErrorNotificationsRequest(requestId, project.id)) {
                appErrorNotificationsLoading.value = false;
            }
        }
    }

    function closeAppErrorNotificationsModal() {
        appErrorNotificationsRequestId += 1;
        appErrorNotificationsOpen.value = false;
        appErrorNotificationsError.value = null;
        appErrorNotificationsLoaded.value = false;
    }

    async function saveAppErrorNotifications() {
        if (!appErrorNotificationsForm.value.project_id || !appErrorNotificationsLoaded.value || appErrorNotificationsLoading.value) return;

        appErrorNotificationsLoading.value = true;
        appErrorNotificationsError.value = null;

        try {
            await axios.put(
                `/projects/${appErrorNotificationsForm.value.project_id}/app-error-notifications`,
                {
                    user_ids: appErrorNotificationsForm.value.selected_user_ids,
                },
                { headers: { Accept: 'application/json' } },
            );
            closeAppErrorNotificationsModal();
            router.reload({ only: ['projects'] });
        } catch (error) {
            console.error('Error saving app error notification settings:', error);
            appErrorNotificationsError.value = 'Unable to save notification settings right now.';
        } finally {
            appErrorNotificationsLoading.value = false;
        }
    }

    function isCurrentAppErrorNotificationsRequest(requestId: number, projectId: number) {
        return appErrorNotificationsRequestId === requestId && appErrorNotificationsForm.value.project_id === projectId;
    }

    return {
        appErrorNotificationsError,
        appErrorNotificationsForm,
        appErrorNotificationsLoaded,
        appErrorNotificationsLoading,
        appErrorNotificationsOpen,
        closeAppErrorNotificationsModal,
        openAppErrorNotificationsModal,
        saveAppErrorNotifications,
    };
}
