<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';

const props = defineProps<{
    metrics: {
        total: number
        pending: number
        in_progress: number
        completed: number
    }
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <div class="grid auto-rows-min gap-4 md:grid-cols-3">
                <div class="relative flex aspect-video flex-col items-center justify-center rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <p class="text-3xl font-bold">{{ props.metrics.pending }}</p>
                    <p class="text-muted-foreground">Pending</p>
                </div>
                <div class="relative flex aspect-video flex-col items-center justify-center rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <p class="text-3xl font-bold">{{ props.metrics.in_progress }}</p>
                    <p class="text-muted-foreground">In Progress</p>
                </div>
                <div class="relative flex aspect-video flex-col items-center justify-center rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <p class="text-3xl font-bold">{{ props.metrics.completed }}</p>
                    <p class="text-muted-foreground">Completed</p>
                </div>
            </div>
            <div class="relative flex min-h-[100vh] flex-1 flex-col items-center justify-center rounded-xl border border-sidebar-border/70 dark:border-sidebar-border md:min-h-min">
                <p class="text-4xl font-bold">{{ props.metrics.total }}</p>
                <p class="text-muted-foreground">Total Tasks</p>
            </div>
        </div>
    </AppLayout>
</template>
