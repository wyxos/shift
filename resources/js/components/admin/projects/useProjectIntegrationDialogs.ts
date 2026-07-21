import { router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { ref } from 'vue';

import { type ProjectEnvironmentRow, type ProjectRow } from './project-shared';

type WidgetEnvironmentSettings = Pick<
    ProjectEnvironmentRow,
    'id' | 'key' | 'label' | 'url' | 'external_widget_enabled' | 'external_widget_guest_submissions_enabled'
>;

export function useProjectIntegrationDialogs() {
    const apiTokenLoading = ref(false);
    const apiTokenError = ref<string | null>(null);
    const widgetSettingsOpen = ref(false);
    const widgetSettingsLoading = ref(false);
    const widgetSettingsError = ref<string | null>(null);
    const mcpSettingsOpen = ref(false);
    const mcpSettingsLoading = ref(false);
    const mcpSettingsError = ref<string | null>(null);

    const apiTokenForm = useForm<{
        project_id: number | null;
        project_name: string;
        token: string;
        isOpen: boolean;
    }>({
        project_id: null,
        project_name: '',
        token: '',
        isOpen: false,
    });

    const widgetSettingsForm = ref<{
        project_id: number | null;
        project_name: string;
        external_widget_enabled: boolean;
        external_widget_guest_submissions_enabled: boolean;
        environments: WidgetEnvironmentSettings[];
    }>({
        project_id: null,
        project_name: '',
        external_widget_enabled: false,
        external_widget_guest_submissions_enabled: false,
        environments: [],
    });

    const mcpSettingsForm = ref<{
        project_id: number | null;
        project_name: string;
        mcp_enabled: boolean;
    }>({
        project_id: null,
        project_name: '',
        mcp_enabled: false,
    });

    function openApiTokenModal(project: ProjectRow) {
        apiTokenForm.project_id = project.id;
        apiTokenForm.project_name = project.name;
        apiTokenForm.token = project.token ?? '';
        apiTokenError.value = null;
        apiTokenForm.isOpen = true;
    }

    function openWidgetSettingsModal(project: ProjectRow) {
        widgetSettingsForm.value = {
            project_id: project.id,
            project_name: project.name,
            external_widget_enabled: Boolean(project.external_widget_enabled),
            external_widget_guest_submissions_enabled: Boolean(project.external_widget_guest_submissions_enabled),
            environments: (project.environments ?? []).map((environment) => ({
                id: environment.id,
                key: environment.key,
                label: environment.label,
                url: environment.url,
                external_widget_enabled: Boolean(environment.external_widget_enabled),
                external_widget_guest_submissions_enabled: Boolean(environment.external_widget_guest_submissions_enabled),
            })),
        };
        widgetSettingsError.value = null;
        widgetSettingsOpen.value = true;
    }

    function closeWidgetSettingsModal() {
        widgetSettingsOpen.value = false;
        widgetSettingsError.value = null;
    }

    function openMcpSettingsModal(project: ProjectRow) {
        mcpSettingsForm.value = {
            project_id: project.id,
            project_name: project.name,
            mcp_enabled: Boolean(project.mcp_enabled),
        };
        mcpSettingsError.value = null;
        mcpSettingsOpen.value = true;
    }

    function closeMcpSettingsModal() {
        mcpSettingsOpen.value = false;
        mcpSettingsError.value = null;
    }

    async function generateApiToken() {
        if (!apiTokenForm.project_id) return;

        apiTokenLoading.value = true;
        apiTokenError.value = null;

        try {
            const response = await axios.post(
                `/projects/${apiTokenForm.project_id}/api-token`,
                {},
                {
                    headers: {
                        Accept: 'application/json',
                    },
                },
            );

            apiTokenForm.token = response.data.token;
        } catch (error) {
            console.error('Error generating project token:', error);
            apiTokenError.value = 'Unable to generate a token right now.';
        } finally {
            apiTokenLoading.value = false;
        }
    }

    async function saveWidgetSettings() {
        if (!widgetSettingsForm.value.project_id) return;

        widgetSettingsLoading.value = true;
        widgetSettingsError.value = null;

        try {
            await axios.patch(
                `/projects/${widgetSettingsForm.value.project_id}/widget-settings`,
                {
                    external_widget_enabled: widgetSettingsForm.value.external_widget_enabled,
                    external_widget_guest_submissions_enabled: widgetSettingsForm.value.external_widget_guest_submissions_enabled,
                    environments: widgetSettingsForm.value.environments.map((environment) => ({
                        id: environment.id,
                        external_widget_enabled: Boolean(environment.external_widget_enabled),
                        external_widget_guest_submissions_enabled: Boolean(environment.external_widget_guest_submissions_enabled),
                    })),
                },
                {
                    headers: {
                        Accept: 'application/json',
                    },
                },
            );

            closeWidgetSettingsModal();
            router.reload({
                only: ['projects'],
            });
        } catch (error) {
            console.error('Error saving widget settings:', error);
            widgetSettingsError.value = 'Unable to save widget settings right now.';
        } finally {
            widgetSettingsLoading.value = false;
        }
    }

    async function saveMcpSettings() {
        if (!mcpSettingsForm.value.project_id) return;

        mcpSettingsLoading.value = true;
        mcpSettingsError.value = null;

        try {
            await axios.patch(
                `/projects/${mcpSettingsForm.value.project_id}/mcp-settings`,
                {
                    mcp_enabled: mcpSettingsForm.value.mcp_enabled,
                },
                {
                    headers: {
                        Accept: 'application/json',
                    },
                },
            );

            closeMcpSettingsModal();
            router.reload({
                only: ['projects'],
            });
        } catch (error) {
            console.error('Error saving MCP settings:', error);
            mcpSettingsError.value = 'Unable to save MCP settings right now.';
        } finally {
            mcpSettingsLoading.value = false;
        }
    }

    return {
        apiTokenError,
        apiTokenForm,
        apiTokenLoading,
        closeMcpSettingsModal,
        closeWidgetSettingsModal,
        generateApiToken,
        openApiTokenModal,
        openMcpSettingsModal,
        openWidgetSettingsModal,
        saveMcpSettings,
        saveWidgetSettings,
        mcpSettingsError,
        mcpSettingsForm,
        mcpSettingsLoading,
        mcpSettingsOpen,
        widgetSettingsError,
        widgetSettingsForm,
        widgetSettingsLoading,
        widgetSettingsOpen,
    };
}
