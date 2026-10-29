import {
    defaultAppErrorAlertRules,
    type ProjectAppErrorAlertRules,
    type ProjectAppErrorNotificationSettings,
    type ProjectRow,
} from '@/components/admin/projects/project-shared';
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
        settings: defaultAppErrorAlertRules(),
    });
    let appErrorNotificationsRequestId = 0;

    async function openAppErrorNotificationsModal(project: ProjectRow) {
        const requestId = ++appErrorNotificationsRequestId;
        appErrorNotificationsForm.value = {
            project_id: project.id,
            project_name: project.name,
            selected_user_ids: [],
            users: [],
            settings: defaultAppErrorAlertRules(),
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

            appErrorNotificationsForm.value.settings = parseAlertRules(response.data.settings);
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

        const projectId = appErrorNotificationsForm.value.project_id;
        const requestId = ++appErrorNotificationsRequestId;
        const rules = appErrorNotificationsForm.value.settings;
        if (!Number.isInteger(rules.quiet_hours) || rules.quiet_hours < 1 || rules.quiet_hours > 168) {
            appErrorNotificationsError.value = 'Choose a whole number between 1 and 168 hours.';
            return;
        }

        appErrorNotificationsLoading.value = true;
        appErrorNotificationsError.value = null;

        try {
            const response = await axios.put<{ scheduling_warning?: string | null }>(
                `/projects/${projectId}/app-error-notifications`,
                {
                    user_ids: [...appErrorNotificationsForm.value.selected_user_ids],
                    settings: { ...rules },
                },
                { headers: { Accept: 'application/json' } },
            );
            if (!isCurrentAppErrorNotificationsRequest(requestId, projectId)) return;

            if (typeof response.data.scheduling_warning === 'string' && response.data.scheduling_warning) {
                appErrorNotificationsError.value = response.data.scheduling_warning;
                return;
            }

            closeAppErrorNotificationsModal();
            router.reload({ only: ['projects'] });
        } catch (error) {
            if (!isCurrentAppErrorNotificationsRequest(requestId, projectId)) return;

            console.error('Error saving app error notification settings:', error);
            appErrorNotificationsError.value =
                responseErrorMessage(error) ?? 'Unable to save notification settings right now. Your changes are kept here.';
        } finally {
            if (isCurrentAppErrorNotificationsRequest(requestId, projectId) || !appErrorNotificationsOpen.value) {
                appErrorNotificationsLoading.value = false;
            }
        }
    }

    function parseAlertRules(value: unknown): ProjectAppErrorAlertRules {
        if (value === undefined) return defaultAppErrorAlertRules();
        if (
            typeof value !== 'object' ||
            value === null ||
            !('recurrence_enabled' in value) ||
            typeof value.recurrence_enabled !== 'boolean' ||
            !('quiet_hours' in value) ||
            typeof value.quiet_hours !== 'number' ||
            !Number.isInteger(value.quiet_hours) ||
            value.quiet_hours < 1 ||
            value.quiet_hours > 168 ||
            !('daily_summary_enabled' in value) ||
            typeof value.daily_summary_enabled !== 'boolean' ||
            !('daily_summary_time' in value) ||
            typeof value.daily_summary_time !== 'string' ||
            !/^([01]\d|2[0-3]):[0-5]\d$/.test(value.daily_summary_time) ||
            !('daily_summary_timezone' in value) ||
            typeof value.daily_summary_timezone !== 'string'
        )
            throw new Error('Invalid error alert settings.');

        return {
            recurrence_enabled: value.recurrence_enabled,
            quiet_hours: value.quiet_hours,
            daily_summary_enabled: value.daily_summary_enabled,
            daily_summary_time: value.daily_summary_time,
            daily_summary_timezone: value.daily_summary_timezone,
        };
    }

    function responseErrorMessage(error: unknown): string | null {
        if (typeof error !== 'object' || error === null || !('response' in error)) return null;
        const response = error.response;
        if (typeof response !== 'object' || response === null || !('data' in response)) return null;
        const data = response.data;
        if (typeof data !== 'object' || data === null || !('errors' in data)) return null;
        const errors = data.errors;
        if (typeof errors !== 'object' || errors === null) return null;
        for (const messages of Object.values(errors)) {
            if (Array.isArray(messages) && typeof messages[0] === 'string') return messages[0];
        }
        return null;
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
