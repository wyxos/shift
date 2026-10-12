<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <Card data-testid="dashboard-first-report">
        <CardHeader>
            <CardTitle>Get your first report into SHIFT</CardTitle>
            <CardDescription
                >Connect a Laravel application, then send a Feedback report and an automatic error report to check both paths.</CardDescription
            >
        </CardHeader>
        <CardContent class="flex flex-wrap items-center justify-between gap-4">
            <p class="text-muted-foreground max-w-2xl text-sm">
                If you have already connected an application, the connection guide shows which reports SHIFT has received. A connected environment
                alone does not confirm reporting works.
            </p>
            <Button as-child><Link href="/connect">Start connection guide</Link></Button>
        </CardContent>
    </Card>
</template>
