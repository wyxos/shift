<script lang="ts" setup>
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Head, Link } from '@inertiajs/vue3';
import { CheckCircle2, Github } from 'lucide-vue-next';

defineProps<{
    auth?: {
        user: any | null;
    };
}>();
</script>

<template>
    <Head title="Open Source Client Issue Intake">
        <meta
            content="An open-source portal for client issue and request intake. Built for Laravel projects today, with room to grow into other frameworks."
            name="description"
        />
        <meta content="client intake, issue tracking, task tracking, open source, Laravel, organizations, clients, projects, tasks" name="keywords" />
        <meta content="Open Source Client Issue Intake" property="og:title" />
        <meta
            content="An open-source portal for client issue and request intake. Built for Laravel projects today, with room to grow into other frameworks."
            property="og:description"
        />
        <meta content="website" property="og:type" />
    </Head>

    <div class="bg-background text-foreground min-h-screen">
        <div class="bg-background/90 border-b backdrop-blur">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-6">
                <div class="flex items-center gap-3">
                    <img alt="Logo" class="h-8 w-auto" src="/brand/shift-logo.svg" />
                    <span class="text-muted-foreground text-sm font-semibold">Client intake</span>
                </div>
                <div class="flex items-center gap-2">
                    <Button as-child class="h-9 w-9" size="icon" variant="ghost">
                        <a aria-label="GitHub" href="https://github.com/wyxos/shift" rel="noreferrer" target="_blank">
                            <Github class="text-muted-foreground size-4" />
                        </a>
                    </Button>
                    <Button as-child size="sm" variant="ghost">
                        <a href="https://wyxos.com" rel="noreferrer" target="_blank">Wyxos</a>
                    </Button>
                    <Button v-if="auth?.user" as-child size="sm">
                        <Link :href="route('dashboard')">Go to Dashboard</Link>
                    </Button>
                    <Button v-else as-child size="sm" variant="outline">
                        <Link :href="route('login')">Log in</Link>
                    </Button>
                </div>
            </div>
        </div>

        <main class="mx-auto flex w-full max-w-6xl flex-col gap-20 px-6 py-16">
            <section class="grid items-center gap-10 lg:grid-cols-[1.1fr_0.9fr]">
                <div class="space-y-6">
                    <Badge
                        class="w-fit border-blue-200/70 bg-blue-50 text-blue-700 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-200"
                        variant="outline"
                    >
                        Open source
                    </Badge>
                    <div class="space-y-4">
                        <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">SHIFT</h1>
                        <p class="text-muted-foreground text-lg">
                            An open-source tool that currently aims to remove the friction of asking clients to learn a tracking tool just to post
                            issues, bugs, and requests on your Laravel projects. Keep the work structured across organizations, clients, projects, and
                            tasks.
                        </p>
                        <p class="text-muted-foreground text-sm">Current focus: Laravel projects. Roadmap: integrations for other frameworks.</p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <Button v-if="!auth?.user" as-child class="bg-blue-600 text-white hover:bg-blue-700" size="lg">
                            <Link :href="route('login')">Log in</Link>
                        </Button>
                        <Button
                            as-child
                            class="border-blue-200 text-blue-700 hover:bg-blue-50 hover:text-blue-800 dark:border-blue-500/40 dark:text-blue-200 dark:hover:bg-blue-500/10"
                            size="lg"
                            variant="outline"
                        >
                            <a href="https://github.com/wyxos/shift" rel="noreferrer" target="_blank">GitHub</a>
                        </Button>
                        <Button as-child size="lg" variant="outline">
                            <a href="https://wyxos.com" rel="noreferrer" target="_blank">wyxos.com</a>
                        </Button>
                    </div>
                </div>

                <Card class="border-blue-100/70 bg-blue-50/20 shadow-none dark:border-blue-500/20 dark:bg-blue-500/5">
                    <CardHeader>
                        <CardTitle class="text-base">What this solves</CardTitle>
                        <CardDescription>Client intake without forcing a tool switch.</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="flex items-start gap-3">
                            <div class="bg-background flex size-9 items-center justify-center rounded-lg border border-blue-100/70">
                                <CheckCircle2 class="size-4 text-blue-600" />
                            </div>
                            <div>
                                <p class="text-sm font-medium">Clients can submit issues easily</p>
                                <p class="text-muted-foreground text-sm">A simple flow to report bugs and requests against real projects.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="bg-background flex size-9 items-center justify-center rounded-lg border border-blue-100/70">
                                <CheckCircle2 class="size-4 text-blue-600" />
                            </div>
                            <div>
                                <p class="text-sm font-medium">Work stays structured</p>
                                <p class="text-muted-foreground text-sm">Organize delivery across organizations, clients, projects, and tasks.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="bg-background flex size-9 items-center justify-center rounded-lg border border-blue-100/70">
                                <CheckCircle2 class="size-4 text-blue-600" />
                            </div>
                            <div>
                                <p class="text-sm font-medium">Context stays attached</p>
                                <p class="text-muted-foreground text-sm">Threads and attachments live with each task, not in scattered chats.</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </section>

            <section class="space-y-8">
                <div class="space-y-2">
                    <h2 class="text-2xl font-semibold">A structure that matches client delivery</h2>
                    <p class="text-muted-foreground text-sm">Designed around how agencies and teams deliver work: orgs, clients, projects, tasks.</p>
                </div>
                <div class="grid gap-6 lg:grid-cols-4">
                    <Card>
                        <CardHeader>
                            <CardTitle class="text-base">Organizations</CardTitle>
                            <CardDescription>Keep delivery aligned to your business units.</CardDescription>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle class="text-base">Clients</CardTitle>
                            <CardDescription>Group work by customer and keep history intact.</CardDescription>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle class="text-base">Projects</CardTitle>
                            <CardDescription>Track initiatives, not just individual tasks.</CardDescription>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle class="text-base">Tasks</CardTitle>
                            <CardDescription>Capture requests, updates, and outcomes in one place.</CardDescription>
                        </CardHeader>
                    </Card>
                </div>
            </section>

            <section class="grid gap-10 lg:grid-cols-[1.1fr_0.9fr]">
                <div class="space-y-4">
                    <h2 class="text-2xl font-semibold">Where this is headed</h2>
                    <p class="text-muted-foreground">
                        The goal is to make client issue intake feel native for Laravel teams today, then expand with integrations and adapters for
                        other frameworks.
                    </p>
                </div>
                <Card class="border-blue-100/70 bg-blue-50/20 shadow-none dark:border-blue-500/20 dark:bg-blue-500/5">
                    <CardHeader>
                        <CardTitle class="text-base">Open source, practical scope</CardTitle>
                        <CardDescription>Ship value now, evolve later.</CardDescription>
                    </CardHeader>
                    <CardContent class="text-muted-foreground space-y-2 text-sm">
                        <p>1. Solve the client intake problem for Laravel projects.</p>
                        <p>2. Add framework integrations over time, without changing the core workflow.</p>
                    </CardContent>
                </Card>
            </section>
        </main>

        <footer class="border-t">
            <div
                class="text-muted-foreground mx-auto flex w-full max-w-6xl flex-col gap-2 px-6 py-8 text-sm sm:flex-row sm:items-center sm:justify-between"
            >
                <span>Open source under the MIT License.</span>
                <div class="flex items-center gap-4">
                    <a class="hover:text-foreground" href="https://wyxos.com" rel="noreferrer" target="_blank">wyxos.com</a>
                    <a class="hover:text-foreground" href="https://github.com/wyxos/shift" rel="noreferrer" target="_blank">GitHub</a>
                </div>
            </div>
        </footer>
    </div>
</template>
