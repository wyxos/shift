<script lang="ts" setup>
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Head, Link } from '@inertiajs/vue3';
import { CheckCircle2, Cloud, Github, Package, Server, Terminal } from 'lucide-vue-next';

defineProps<{
    auth?: {
        user: any | null;
    };
}>();

const setupPaths = [
    {
        title: 'Hosted SHIFT',
        description: 'Use the managed portal at shift.wyxos.com and connect Laravel projects with the package installer.',
        icon: Cloud,
        points: ['No portal hosting to maintain', 'Browser approval flow', 'Starter plan available'],
    },
    {
        title: 'Self-hosted SHIFT',
        description: 'Run the open-source portal yourself, then point client Laravel projects at your own SHIFT URL.',
        icon: Server,
        points: ['Open-source Laravel app', 'Own server and data controls', 'No hosted billing layer'],
    },
];

const installSteps = [
    {
        title: 'Pull the package',
        command: 'composer require wyxos/shift-php',
        description: 'Install the Laravel package inside the client project that should expose the SHIFT task UI.',
    },
    {
        title: 'Choose the portal',
        command: 'SHIFT_URL=https://shift.wyxos.com',
        description: 'Use the hosted URL, or replace it with your self-hosted SHIFT instance URL.',
    },
    {
        title: 'Connect the project',
        command: 'php artisan install:shift',
        description: 'Approve the browser flow, select or create a project, and let the installer write the token and project key.',
    },
];

const deliveryItems = [
    ['Organisations', 'Keep delivery aligned to your business units.'],
    ['Clients', 'Group customer work and keep history intact.'],
    ['Projects', 'Track initiatives, not just individual tasks.'],
    ['Tasks', 'Capture requests, updates, comments, and outcomes in one place.'],
];
</script>

<template>
    <Head title="Open Source Client Task Portal">
        <meta
            content="SHIFT is an open-source client task portal for Laravel teams. Use hosted SHIFT or self-host the portal, then expose tasks through the wyxos/shift-php package."
            name="description"
        />
        <meta content="client task portal, task tracking, open source, Laravel, shift-php, hosted, self-hosted" name="keywords" />
        <meta content="Open Source Client Task Portal" property="og:title" />
        <meta
            content="Use hosted SHIFT or self-host the portal, then expose normal SHIFT tasks inside client Laravel projects with wyxos/shift-php."
            property="og:description"
        />
        <meta content="website" property="og:type" />
    </Head>

    <div class="bg-background text-foreground min-h-screen">
        <div class="bg-background/90 border-b backdrop-blur">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-6">
                <div class="flex shrink-0 items-center gap-3">
                    <img alt="Logo" class="h-8 w-11 shrink-0 object-contain" src="/brand/shift-logo.svg" />
                    <span class="text-muted-foreground hidden text-sm font-semibold sm:inline">Client task portal</span>
                </div>
                <div class="flex items-center gap-2">
                    <Button as-child class="h-9 w-9" size="icon" variant="ghost">
                        <a aria-label="GitHub" href="https://github.com/wyxos/shift" rel="noreferrer" target="_blank">
                            <Github class="text-muted-foreground size-4" />
                        </a>
                    </Button>
                    <Button as-child class="hidden sm:inline-flex" size="sm" variant="ghost">
                        <a href="https://wyxos.com" rel="noreferrer" target="_blank">Wyxos</a>
                    </Button>
                    <Button v-if="auth?.user" as-child size="sm">
                        <Link :href="route('dashboard')">Go to Dashboard</Link>
                    </Button>
                    <template v-else>
                        <Button as-child size="sm" variant="ghost">
                            <Link :href="route('login')">Log in</Link>
                        </Button>
                        <Button as-child size="sm">
                            <Link :href="route('register')">Create account</Link>
                        </Button>
                    </template>
                </div>
            </div>
        </div>

        <main class="mx-auto flex w-full max-w-6xl flex-col gap-20 px-6 py-16">
            <section class="grid items-center gap-10 lg:grid-cols-[1.05fr_0.95fr]">
                <div class="space-y-6">
                    <Badge
                        class="w-fit border-blue-200/70 bg-blue-50 text-blue-700 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-200"
                        variant="outline"
                    >
                        Open source, hosted when needed
                    </Badge>
                    <div class="space-y-4">
                        <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">SHIFT</h1>
                        <p class="text-muted-foreground text-lg">
                            Let clients create and follow tasks from the Laravel app they already use. Run the open-source portal yourself, or use
                            hosted SHIFT and connect projects with <code class="font-mono text-sm">wyxos/shift-php</code>.
                        </p>
                        <p class="text-muted-foreground text-sm">
                            Current focus: Laravel projects, normal tasks, comments, collaborators, and attachments. No separate intake object.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <Button v-if="!auth?.user" as-child class="bg-blue-600 text-white hover:bg-blue-700" size="lg">
                            <Link :href="route('register')">Create account</Link>
                        </Button>
                        <Button v-if="!auth?.user" as-child size="lg" variant="outline">
                            <Link :href="route('login')">Log in</Link>
                        </Button>
                        <Button
                            as-child
                            class="border-blue-200 text-blue-700 hover:bg-blue-50 hover:text-blue-800 dark:border-blue-500/40 dark:text-blue-200 dark:hover:bg-blue-500/10"
                            size="lg"
                            variant="outline"
                        >
                            <a href="https://packagist.org/packages/wyxos/shift-php" rel="noreferrer" target="_blank">Install package</a>
                        </Button>
                        <Button as-child size="lg" variant="outline">
                            <a href="https://github.com/wyxos/shift" rel="noreferrer" target="_blank">GitHub</a>
                        </Button>
                    </div>
                </div>

                <Card class="border-blue-100/70 bg-blue-50/20 shadow-none dark:border-blue-500/20 dark:bg-blue-500/5">
                    <CardHeader>
                        <CardTitle class="text-base">How work moves</CardTitle>
                        <CardDescription>Client project UI into normal SHIFT tasks.</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="flex items-start gap-3">
                            <div class="bg-background flex size-9 items-center justify-center rounded-lg border border-blue-100/70">
                                <Package class="size-4 text-blue-600" />
                            </div>
                            <div>
                                <p class="text-sm font-medium">Install <code class="font-mono text-xs">wyxos/shift-php</code></p>
                                <p class="text-muted-foreground text-sm">
                                    The package exposes <code class="font-mono text-xs">/shift/tasks</code> inside a Laravel project.
                                </p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="bg-background flex size-9 items-center justify-center rounded-lg border border-blue-100/70">
                                <Terminal class="size-4 text-blue-600" />
                            </div>
                            <div>
                                <p class="text-sm font-medium">Run the installer</p>
                                <p class="text-muted-foreground text-sm">Set the portal URL, approve the project, and receive scoped credentials.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="bg-background flex size-9 items-center justify-center rounded-lg border border-blue-100/70">
                                <CheckCircle2 class="size-4 text-blue-600" />
                            </div>
                            <div>
                                <p class="text-sm font-medium">Use normal tasks</p>
                                <p class="text-muted-foreground text-sm">Client-created work stays in the same task flow your team manages.</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </section>

            <section class="space-y-8">
                <div class="space-y-2">
                    <h2 class="text-2xl font-semibold">Choose hosted or self-hosted</h2>
                    <p class="text-muted-foreground text-sm">
                        Both paths use the same package and task flow. The difference is who runs the SHIFT portal.
                    </p>
                </div>
                <div class="grid gap-6 lg:grid-cols-2">
                    <Card v-for="path in setupPaths" :key="path.title" class="rounded-lg shadow-none" :data-testid="`setup-path-${path.title}`">
                        <CardHeader class="space-y-4">
                            <div class="flex size-10 items-center justify-center rounded-lg border bg-blue-50 dark:bg-blue-500/10">
                                <component :is="path.icon" class="size-5 text-blue-600 dark:text-blue-300" />
                            </div>
                            <div class="space-y-1">
                                <CardTitle class="text-lg">{{ path.title }}</CardTitle>
                                <CardDescription>{{ path.description }}</CardDescription>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <ul class="space-y-3 text-sm">
                                <li v-for="point in path.points" :key="point" class="flex items-start gap-2">
                                    <CheckCircle2 class="mt-0.5 size-4 shrink-0 text-blue-600" />
                                    <span>{{ point }}</span>
                                </li>
                            </ul>
                        </CardContent>
                    </Card>
                </div>
            </section>

            <section class="grid gap-8 lg:grid-cols-[0.85fr_1.15fr]" id="install">
                <div class="space-y-4">
                    <Badge
                        class="w-fit border-blue-200/70 bg-blue-50 text-blue-700 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-200"
                        variant="outline"
                    >
                        Laravel package
                    </Badge>
                    <div class="space-y-2">
                        <h2 class="text-2xl font-semibold">Install the client project UI</h2>
                        <p class="text-muted-foreground text-sm">
                            Add the package to the Laravel project your client already uses. Hosted and self-hosted portals only change the
                            <code class="font-mono">SHIFT_URL</code> value.
                        </p>
                    </div>
                </div>
                <div class="grid gap-4">
                    <Card v-for="step in installSteps" :key="step.title" class="rounded-lg shadow-none" :data-testid="`install-step-${step.title}`">
                        <CardHeader>
                            <CardTitle class="text-base">{{ step.title }}</CardTitle>
                            <CardDescription>{{ step.description }}</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <pre class="bg-muted/60 overflow-x-auto rounded-lg border px-4 py-3 text-sm"><code>{{ step.command }}</code></pre>
                        </CardContent>
                    </Card>
                </div>
            </section>

            <section class="space-y-8">
                <div class="space-y-2">
                    <h2 class="text-2xl font-semibold">A structure that matches client delivery</h2>
                    <p class="text-muted-foreground text-sm">
                        Designed around how agencies and teams deliver work: organisations, clients, projects, tasks.
                    </p>
                </div>
                <div class="grid gap-6 lg:grid-cols-4">
                    <Card v-for="[title, description] in deliveryItems" :key="title" class="rounded-lg shadow-none">
                        <CardHeader>
                            <CardTitle class="text-base">{{ title }}</CardTitle>
                            <CardDescription>{{ description }}</CardDescription>
                        </CardHeader>
                    </Card>
                </div>
            </section>
        </main>

        <footer class="border-t">
            <div
                class="text-muted-foreground mx-auto flex w-full max-w-6xl flex-col gap-2 px-6 py-8 text-sm sm:flex-row sm:items-center sm:justify-between"
            >
                <span>Open source under the MIT License.</span>
                <div class="flex items-center gap-4">
                    <a class="hover:text-foreground" href="https://packagist.org/packages/wyxos/shift-php" rel="noreferrer" target="_blank"
                        >Package</a
                    >
                    <a class="hover:text-foreground" href="https://wyxos.com" rel="noreferrer" target="_blank">wyxos.com</a>
                    <a class="hover:text-foreground" href="https://github.com/wyxos/shift" rel="noreferrer" target="_blank">GitHub</a>
                </div>
            </div>
        </footer>
    </div>
</template>
