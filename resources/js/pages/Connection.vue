<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, type SelectOption, type SelectOptionValue } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, Check, RefreshCw } from 'lucide-vue-next';
import { computed, ref } from 'vue';

type ReceivedReport = { id: number; title: string; url: string };
type ConnectionEnvironment = {
    id: number;
    environment: string;
    label: string;
    url: string;
    feedback_enabled: boolean;
    feedback_report: ReceivedReport | null;
    error_report: ReceivedReport | null;
};

const props = defineProps<{
    portalUrl: string;
    projects: Array<{ id: number; name: string }>;
    selectedProjectId: number | null;
    environments: ConnectionEnvironment[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Connect application', href: '/connect' }];
const projectOptions = computed<SelectOption[]>(() => props.projects.map((project) => ({ value: project.id, label: project.name })));
const selectedProject = computed(() => props.projects.find((project) => project.id === props.selectedProjectId));
const updatingEnvironmentId = ref<number | null>(null);
const updateError = ref<string | null>(null);
const refreshing = ref(false);

function selectProject(value: SelectOptionValue) {
    if (typeof value !== 'number' || value === props.selectedProjectId) return;

    updateError.value = null;
    router.get('/connect', { project_id: value }, { preserveState: false });
}

function setFeedback(environment: ConnectionEnvironment) {
    if (props.selectedProjectId === null || updatingEnvironmentId.value !== null) return;

    updatingEnvironmentId.value = environment.id;
    updateError.value = null;
    router.patch(
        `/projects/${props.selectedProjectId}/connection/environments/${environment.id}`,
        {
            feedback_enabled: !environment.feedback_enabled,
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                updateError.value = Object.values(errors)[0] ?? 'Feedback could not be updated. Try again.';
            },
            onFinish: () => {
                updatingEnvironmentId.value = null;
            },
        },
    );
}

function refreshReports() {
    if (refreshing.value) return;

    refreshing.value = true;
    router.reload({
        onFinish: () => {
            refreshing.value = false;
        },
    });
}
</script>

<template>
    <Head title="Connect application" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4">
            <div class="space-y-2">
                <h1 class="text-2xl font-semibold tracking-tight">Connect an application</h1>
                <p class="text-muted-foreground max-w-3xl text-sm">
                    Install SHIFT in a Laravel application, then check Feedback and automatic error reporting separately. A registered environment
                    only confirms the install reached SHIFT.
                </p>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Install the Laravel package</CardTitle>
                    <CardDescription>The installer can create a project or connect to one you manage.</CardDescription>
                </CardHeader>
                <CardContent class="space-y-4 text-sm">
                    <p>Add these settings to the Laravel application's <code class="bg-muted rounded px-1 py-0.5">.env</code> file:</p>
                    <pre class="bg-muted overflow-x-auto rounded-md p-4 text-sm"><code>SHIFT_URL={{ portalUrl }}
SHIFT_WIDGET_ENABLED=true
SHIFT_ERROR_REPORTING_ENABLED=true</code></pre>
                    <p>Then run these commands from that application:</p>
                    <pre class="bg-muted overflow-x-auto rounded-md p-4 text-sm"><code>composer require wyxos/shift-php
php artisan config:clear
php artisan install:shift</code></pre>
                    <p>Approve the install code in your browser, then finish the project choice in the terminal.</p>
                    <p class="text-muted-foreground">
                        The installer reads SHIFT_URL from the application configuration, writes project credentials, and registers the current
                        application environment.
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Check the connection</CardTitle>
                    <CardDescription>Choose a project to see its registered application environments and received reports.</CardDescription>
                </CardHeader>
                <CardContent class="space-y-5">
                    <div v-if="projects.length" class="max-w-sm space-y-2">
                        <span class="text-sm font-medium">Project</span>
                        <Select
                            :model-value="selectedProjectId"
                            :options="projectOptions"
                            aria-label="Project"
                            placeholder="Select a project"
                            search-placeholder="Search projects..."
                            empty-label="No projects found."
                            searchable
                            test-id="connection-project"
                            @update:model-value="selectProject"
                        />
                    </div>
                    <p v-else class="text-muted-foreground text-sm">
                        No projects are available yet. Complete the installer above to create or join one, then refresh this page.
                    </p>

                    <div v-if="selectedProject && environments.length" class="space-y-4">
                        <div
                            v-for="environment in environments"
                            :key="environment.id"
                            class="rounded-lg border p-4"
                            :data-testid="`connection-environment-${environment.id}`"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0 space-y-1">
                                    <h2 class="font-medium">{{ environment.label }}</h2>
                                    <p class="text-muted-foreground text-sm break-all">{{ environment.url }}</p>
                                    <a
                                        :href="environment.url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="text-primary inline-flex items-center gap-1 text-sm font-medium underline-offset-4 hover:underline"
                                        >Open application <ArrowRight class="h-4 w-4"
                                    /></a>
                                </div>
                                <Badge variant="secondary"><Check class="mr-1 h-3.5 w-3.5" />Environment registered</Badge>
                            </div>

                            <div class="mt-5 grid gap-5 border-t pt-5 md:grid-cols-2">
                                <section class="space-y-3" :aria-labelledby="`feedback-heading-${environment.id}`">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 :id="`feedback-heading-${environment.id}`" class="text-sm font-medium">Feedback</h3>
                                        <Badge :variant="environment.feedback_report ? 'secondary' : 'outline'">{{
                                            environment.feedback_report ? 'Report received' : 'No report received'
                                        }}</Badge>
                                    </div>
                                    <p class="text-muted-foreground text-sm">
                                        {{
                                            environment.feedback_enabled
                                                ? 'Feedback is enabled for this environment.'
                                                : 'Feedback is disabled for this environment. Enable it before testing the form.'
                                        }}
                                    </p>
                                    <Button size="sm" variant="outline" :disabled="updatingEnvironmentId !== null" @click="setFeedback(environment)">
                                        {{
                                            updatingEnvironmentId === environment.id
                                                ? 'Saving…'
                                                : environment.feedback_enabled
                                                  ? 'Disable Feedback'
                                                  : 'Enable Feedback'
                                        }}
                                    </Button>
                                    <p class="text-muted-foreground text-sm">
                                        Set <code class="bg-muted rounded px-1 py-0.5">SHIFT_WIDGET_ENABLED=true</code> in the application. Sign in
                                        there, open Feedback, submit a test issue, then refresh below.
                                    </p>
                                    <Link
                                        v-if="environment.feedback_report"
                                        :href="environment.feedback_report.url"
                                        class="text-primary inline-flex items-center gap-1 text-sm font-medium underline-offset-4 hover:underline"
                                    >
                                        Open received Feedback report <ArrowRight class="h-4 w-4" />
                                    </Link>
                                </section>

                                <section class="space-y-3" :aria-labelledby="`error-heading-${environment.id}`">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 :id="`error-heading-${environment.id}`" class="text-sm font-medium">Automatic error reporting</h3>
                                        <Badge :variant="environment.error_report ? 'secondary' : 'outline'">{{
                                            environment.error_report ? 'Error received' : 'No error received'
                                        }}</Badge>
                                    </div>
                                    <p class="text-muted-foreground text-sm">
                                        Set <code class="bg-muted rounded px-1 py-0.5">SHIFT_ERROR_REPORTING_ENABLED=true</code> in the application,
                                        then run this safe test command:
                                    </p>
                                    <pre class="bg-muted overflow-x-auto rounded-md p-3 text-sm"><code>php artisan shift:test --error</code></pre>
                                    <p class="text-muted-foreground text-sm">
                                        The test creates a report in this project. Future Laravel exceptions are reported automatically while this
                                        setting is enabled.
                                    </p>
                                    <Link
                                        v-if="environment.error_report"
                                        :href="environment.error_report.url"
                                        class="text-primary inline-flex items-center gap-1 text-sm font-medium underline-offset-4 hover:underline"
                                    >
                                        Open received error report <ArrowRight class="h-4 w-4" />
                                    </Link>
                                </section>
                            </div>
                        </div>
                    </div>
                    <p v-else-if="selectedProject" class="text-muted-foreground text-sm">
                        No environments registered for {{ selectedProject.name }} yet. Finish
                        <code class="bg-muted rounded px-1 py-0.5">php artisan install:shift</code> in your application, then refresh.
                    </p>

                    <p v-if="updateError" role="alert" class="text-destructive text-sm">{{ updateError }}</p>
                    <div class="flex flex-wrap items-center gap-3 border-t pt-4">
                        <Button variant="outline" :disabled="refreshing" @click="refreshReports"
                            ><RefreshCw class="mr-2 h-4 w-4" />{{ refreshing ? 'Refreshing…' : 'Refresh connection status' }}</Button
                        >
                        <p class="text-muted-foreground text-xs">Status changes when SHIFT receives a report. Refresh after each test.</p>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
