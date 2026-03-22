<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ChartContainer, type ChartConfig } from '@/components/ui/chart';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { VisAxis, VisDonut, VisGroupedBar, VisSingleContainer, VisXYContainer } from '@unovis/vue';
import { AlertTriangle, CheckCircle2, Clock3, Flame } from 'lucide-vue-next';
import { computed } from 'vue';

type SegmentCount = {
    key: string;
    label: string;
    count: number;
};

type ThroughputPoint = {
    week_start: string;
    label: string;
    created: number;
    completed: number;
};

type ProjectPoint = {
    project: string;
    count: number;
};

const props = defineProps<{
    metrics: {
        total: number;
        pending: number;
        in_progress: number;
        completed: number;
        open?: number;
        awaiting_feedback?: number;
        high_priority_open?: number;
        completion_rate?: number;
    };
    charts?: {
        status?: SegmentCount[];
        priority?: SegmentCount[];
        environments?: SegmentCount[];
        throughput?: ThroughputPoint[];
        projects?: ProjectPoint[];
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

const chartPalette = ['var(--chart-1)', 'var(--chart-2)', 'var(--chart-3)', 'var(--chart-4)', 'var(--chart-5)'];

const statusSegments = computed(() => props.charts?.status ?? []);
const prioritySegments = computed(() => props.charts?.priority ?? []);
const environmentSegments = computed(() => props.charts?.environments ?? []);
const throughputPoints = computed(() => props.charts?.throughput ?? []);
const projectPoints = computed(() => props.charts?.projects ?? []);

const statusData = computed(() =>
    statusSegments.value
        .filter((item) => item.count > 0)
        .map((item) => ({
            segment: item.key,
            label: item.label,
            value: item.count,
        })),
);

const environmentData = computed(() =>
    environmentSegments.value
        .filter((item) => item.count > 0)
        .map((item) => ({
            segment: item.key,
            label: item.label,
            value: item.count,
        })),
);

const priorityData = computed(() =>
    prioritySegments.value.map((item, index) => ({
        key: item.key,
        label: item.label,
        value: item.count,
        order: index,
    })),
);

const projectData = computed(() =>
    projectPoints.value.map((item, index) => ({
        key: `project-${index}`,
        project: item.project,
        value: item.count,
        order: index,
    })),
);

const throughputData = computed(() =>
    throughputPoints.value.map((item) => ({
        ...item,
        date: new Date(item.week_start),
    })),
);

const statusChartConfig = {
    value: {
        label: 'Tasks',
        color: 'var(--chart-1)',
    },
    pending: {
        label: 'Pending',
        color: 'var(--chart-4)',
    },
    'in-progress': {
        label: 'In Progress',
        color: 'var(--chart-1)',
    },
    'awaiting-feedback': {
        label: 'Awaiting Feedback',
        color: 'var(--chart-3)',
    },
    completed: {
        label: 'Completed',
        color: 'var(--chart-2)',
    },
    closed: {
        label: 'Closed',
        color: 'var(--chart-5)',
    },
} satisfies ChartConfig;

const priorityChartConfig = {
    value: {
        label: 'Tasks',
        color: 'var(--chart-1)',
    },
    high: {
        label: 'High',
        color: 'var(--chart-1)',
    },
    medium: {
        label: 'Medium',
        color: 'var(--chart-3)',
    },
    low: {
        label: 'Low',
        color: 'var(--chart-5)',
    },
} satisfies ChartConfig;

const throughputChartConfig = {
    created: {
        label: 'Created',
        color: 'var(--chart-4)',
    },
    completed: {
        label: 'Completed',
        color: 'var(--chart-2)',
    },
} satisfies ChartConfig;

const environmentChartConfig = computed<ChartConfig>(() => {
    const config: ChartConfig = {
        value: {
            label: 'Tasks',
            color: 'var(--chart-1)',
        },
    };

    environmentSegments.value.forEach((segment, index) => {
        config[segment.key] = {
            label: segment.label,
            color: chartPalette[index % chartPalette.length],
        };
    });

    return config;
});

const projectChartConfig = computed<ChartConfig>(() => {
    const config: ChartConfig = {
        value: {
            label: 'Tasks',
            color: 'var(--chart-1)',
        },
    };

    projectData.value.forEach((segment, index) => {
        config[segment.key] = {
            label: segment.project,
            color: chartPalette[index % chartPalette.length],
        };
    });

    return config;
});

const openCount = computed(() => props.metrics.open ?? props.metrics.pending + props.metrics.in_progress + (props.metrics.awaiting_feedback ?? 0));
const completionRate = computed(
    () => props.metrics.completion_rate ?? (props.metrics.total > 0 ? Math.round((props.metrics.completed / props.metrics.total) * 1000) / 10 : 0),
);

const totalThroughputDelta = computed(() => {
    const created = throughputData.value.reduce((sum, point) => sum + point.created, 0);
    const completed = throughputData.value.reduce((sum, point) => sum + point.completed, 0);

    return created - completed;
});
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Task Intelligence</h1>
                    <p class="text-muted-foreground text-sm">A live summary of workload, flow, and risk across your visible projects.</p>
                </div>
                <div class="flex items-center gap-2">
                    <Button as-child variant="outline">
                        <Link href="/tasks">Open Tasks</Link>
                    </Button>
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Total Tasks</CardDescription>
                        <CardTitle class="text-3xl">{{ props.metrics.total }}</CardTitle>
                    </CardHeader>
                    <CardContent class="text-muted-foreground text-xs">All tasks currently visible to your account scope.</CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Open Work</CardDescription>
                        <CardTitle class="text-3xl">{{ openCount }}</CardTitle>
                    </CardHeader>
                    <CardContent class="text-muted-foreground flex items-center gap-2 text-xs">
                        <Clock3 class="h-3.5 w-3.5" />
                        Pending + In Progress + Awaiting Feedback
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Completion Rate</CardDescription>
                        <CardTitle class="text-3xl">{{ completionRate.toFixed(1) }}%</CardTitle>
                    </CardHeader>
                    <CardContent class="text-muted-foreground flex items-center gap-2 text-xs">
                        <CheckCircle2 class="h-3.5 w-3.5" />
                        Completed share of total tasks
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>High Priority Open</CardDescription>
                        <CardTitle class="text-3xl">{{ props.metrics.high_priority_open ?? 0 }}</CardTitle>
                    </CardHeader>
                    <CardContent class="text-muted-foreground flex items-center gap-2 text-xs">
                        <Flame class="h-3.5 w-3.5" />
                        High-priority tasks not yet closed/completed
                    </CardContent>
                </Card>
            </div>

            <div class="grid gap-4 xl:grid-cols-3">
                <Card class="xl:col-span-2">
                    <CardHeader class="pb-2">
                        <CardTitle>Weekly Throughput</CardTitle>
                        <CardDescription>Created vs completed tasks over the last 8 weeks.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div v-if="throughputData.length === 0" class="text-muted-foreground py-8 text-center text-sm">
                            No throughput history yet.
                        </div>
                        <ChartContainer v-else :config="throughputChartConfig" class="h-[300px] w-full" cursor>
                            <VisXYContainer :data="throughputData" :margin="{ left: -12, right: 12 }" :y-domain="[0, undefined]">
                                <VisGroupedBar
                                    :x="(d) => d.date"
                                    :y="[(d) => d.created, (d) => d.completed]"
                                    :color="[throughputChartConfig.created.color, throughputChartConfig.completed.color]"
                                    :rounded-corners="6"
                                />
                                <VisAxis
                                    type="x"
                                    :x="(d) => d.date"
                                    :tick-values="throughputData.map((d) => d.date)"
                                    :tick-line="false"
                                    :domain-line="false"
                                    :grid-line="false"
                                    :tick-format="(value: number) => new Date(value).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })"
                                />
                                <VisAxis type="y" :num-ticks="4" :tick-line="false" :domain-line="false" />
                            </VisXYContainer>
                        </ChartContainer>
                        <div class="text-muted-foreground mt-3 flex items-center justify-between text-xs">
                            <span>Net backlog change across window</span>
                            <Badge :variant="totalThroughputDelta <= 0 ? 'secondary' : 'outline'">
                                {{ totalThroughputDelta > 0 ? '+' : '' }}{{ totalThroughputDelta }}
                            </Badge>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle>Status Distribution</CardTitle>
                        <CardDescription>How current workload is spread by status.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div v-if="statusData.length === 0" class="text-muted-foreground py-8 text-center text-sm">No status data yet.</div>
                        <ChartContainer v-else :config="statusChartConfig" class="mx-auto h-[300px] max-w-[320px]">
                            <VisSingleContainer :data="statusData" :margin="{ top: 10, bottom: 10 }">
                                <VisDonut
                                    :value="(d) => d.value"
                                    :color="(d) => statusChartConfig[d.segment as keyof typeof statusChartConfig]?.color"
                                    :arc-width="24"
                                    :corner-radius="3"
                                />
                            </VisSingleContainer>
                        </ChartContainer>
                    </CardContent>
                </Card>
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle>Priority Mix</CardTitle>
                        <CardDescription>Current volume by priority level.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div v-if="priorityData.length === 0" class="text-muted-foreground py-8 text-center text-sm">No priority data yet.</div>
                        <ChartContainer v-else :config="priorityChartConfig" class="h-[280px] w-full" cursor>
                            <VisXYContainer :data="priorityData" :margin="{ left: 8, right: 12 }" :y-domain="[0, undefined]">
                                <VisGroupedBar
                                    :x="(d) => d.order"
                                    :y="(d) => d.value"
                                    :color="(d) => priorityChartConfig[d.key as keyof typeof priorityChartConfig]?.color"
                                    :group-max-width="42"
                                    :group-padding="0.2"
                                    :rounded-corners="8"
                                />
                                <VisAxis
                                    type="x"
                                    :x="(d) => d.order"
                                    :tick-values="priorityData.map((d) => d.order)"
                                    :tick-line="false"
                                    :domain-line="false"
                                    :grid-line="false"
                                    :tick-format="(value: number) => priorityData[value]?.label ?? ''"
                                />
                                <VisAxis type="y" :num-ticks="4" :tick-line="false" :domain-line="false" />
                            </VisXYContainer>
                        </ChartContainer>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle>Project Load</CardTitle>
                        <CardDescription>Top 5 projects by task volume in your scope.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div v-if="projectData.length === 0" class="text-muted-foreground py-8 text-center text-sm">No project data yet.</div>
                        <ChartContainer v-else :config="projectChartConfig" class="h-[280px] w-full" cursor>
                            <VisXYContainer :data="projectData" :margin="{ left: 12, right: 12 }" :y-domain="[0, undefined]">
                                <VisGroupedBar
                                    :x="(d) => d.order"
                                    :y="(d) => d.value"
                                    :color="(d) => projectChartConfig[d.key as keyof typeof projectChartConfig]?.color"
                                    :group-max-width="42"
                                    :group-padding="0.2"
                                    :rounded-corners="8"
                                />
                                <VisAxis
                                    type="x"
                                    :x="(d) => d.order"
                                    :tick-values="projectData.map((d) => d.order)"
                                    :tick-line="false"
                                    :domain-line="false"
                                    :grid-line="false"
                                    :tick-format="(value: number) => projectData[value]?.project ?? ''"
                                />
                                <VisAxis type="y" :num-ticks="4" :tick-line="false" :domain-line="false" />
                            </VisXYContainer>
                        </ChartContainer>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader class="pb-2">
                    <CardTitle>Environment Exposure</CardTitle>
                    <CardDescription>Where active task volume is concentrated.</CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="environmentData.length === 0" class="text-muted-foreground py-8 text-center text-sm">No environment data yet.</div>
                    <div v-else class="grid gap-4 md:grid-cols-[300px_1fr] md:items-center">
                        <ChartContainer :config="environmentChartConfig" class="mx-auto h-[260px] w-full max-w-[300px]">
                            <VisSingleContainer :data="environmentData" :margin="{ top: 8, bottom: 8 }">
                                <VisDonut
                                    :value="(d) => d.value"
                                    :color="(d) => environmentChartConfig[d.segment]?.color"
                                    :arc-width="22"
                                    :corner-radius="3"
                                />
                            </VisSingleContainer>
                        </ChartContainer>

                        <div class="space-y-2">
                            <div
                                v-for="segment in environmentData"
                                :key="segment.segment"
                                class="border-border bg-muted/20 flex items-center justify-between rounded-lg border px-3 py-2"
                            >
                                <div class="flex items-center gap-2">
                                    <span
                                        class="h-2.5 w-2.5 rounded-sm"
                                        :style="{ backgroundColor: environmentChartConfig[segment.segment]?.color }"
                                    />
                                    <span class="text-sm font-medium">{{ segment.label }}</span>
                                </div>
                                <span class="text-muted-foreground text-sm">{{ segment.value }}</span>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card
                v-if="(props.metrics.awaiting_feedback ?? 0) > 0"
                class="border-amber-300/70 bg-amber-50/30 dark:border-amber-700/50 dark:bg-amber-950/15"
            >
                <CardContent class="flex items-center gap-3 py-4 text-sm">
                    <AlertTriangle class="h-4 w-4 text-amber-600 dark:text-amber-400" />
                    <span>
                        {{ props.metrics.awaiting_feedback }} task{{ props.metrics.awaiting_feedback === 1 ? '' : 's' }} awaiting feedback may be
                        blocking closure.
                    </span>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
