<script lang="ts" setup>
import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Cloud, ExternalLink, Github, Package, Server } from 'lucide-vue-next';

defineProps<{
    auth?: {
        user: any | null;
    };
}>();

const embeddedShowcase = {
    label: 'Client app',
    title: 'The tracker lives where the client already works.',
    url: 'app.northwind.com/shift/tasks',
    image: '/marketing/shift-embedded-tasks.png',
    alt: 'SHIFT task list embedded inside a client Laravel app with the client app menu visible',
    copy: 'The package mounts a task workspace inside the Laravel app. Clients stay in their own app and file work against the right project context.',
};

const platformShowcase = {
    label: 'Central platform',
    title: 'Your team handles every client from one queue.',
    url: 'shift.wyxos.com/tasks',
    image: '/marketing/shift-portal-queue.png',
    alt: 'SHIFT central portal task queue with organisation menu visible',
    copy: 'The central portal keeps the agency side organised by organisation, client, project, task, status, priority, and environment.',
};

const showcases = [embeddedShowcase, platformShowcase];

const workflowSteps = [
    {
        label: 'Install package',
        copy: 'Add wyxos/shift-php to the client Laravel app.',
    },
    {
        label: 'Client files request',
        copy: 'They create tasks from /shift/tasks without a separate SHIFT login.',
    },
    {
        label: 'Team triages in SHIFT',
        copy: 'Your team works from one portal across clients and projects.',
    },
];

const installSteps = [
    {
        label: 'Install',
        command: 'composer require wyxos/shift-php',
        note: 'Pull the package into the client project.',
    },
    {
        label: 'Point it',
        command: 'SHIFT_URL=https://shift.wyxos.com',
        note: 'Use hosted SHIFT, or replace it with your self-hosted URL.',
    },
    {
        label: 'Connect',
        command: 'php artisan install:shift',
        note: 'Approve in the browser and choose the SHIFT project.',
    },
];

const installFlowLines = [
    'Detected application environment: local',
    'Detected application URL: https://app.northwind.com',
    'Verify this installation in your browser to continue.',
    'Verification URL: https://shift.wyxos.com/sdk/install',
    'Short code: A1B2-C3',
    'Waiting for SHIFT approval...',
    'Select which SHIFT project to link to this application',
    'SHIFT authorization approved.',
    'Registered local => https://app.northwind.com with SHIFT.',
    'SHIFT installation complete.',
];

const faqItems = [
    {
        question: 'Do clients need a SHIFT login?',
        answer: 'No. They use the client app they already have. SHIFT sits inside it through the Laravel package.',
    },
    {
        question: 'Can we self-host it?',
        answer: 'Yes. Run the open-source Laravel portal on your own server and point the package at that URL.',
    },
    {
        question: 'What data leaves the client app?',
        answer: 'Task details, project context, environment, page URL, comments, and attachments you choose to send. It is built around explicit task reporting, not background scraping.',
    },
    {
        question: 'Can we use existing Laravel auth?',
        answer: 'Yes. The embedded workspace runs inside the client Laravel app, so users keep their normal application session.',
    },
    {
        question: 'Is this another project management tool?',
        answer: 'No. It is the intake and triage layer for client app work. Your team can still deliver the work however you already plan and ship it.',
    },
];
</script>

<template>
    <Head title="Open Source Client Task Portal">
        <meta
            content="SHIFT puts an issue and task tracker inside the Laravel apps you build for clients. Install one package, and every report lands in one portal, hosted or self-hosted."
            name="description"
        />
        <meta content="client task portal, issue tracker, open source, Laravel, shift-php, hosted, self-hosted" name="keywords" />
        <meta content="Open Source Client Task Portal" property="og:title" />
        <meta
            content="An issue and task tracker that lives inside your client's own Laravel app. Install wyxos/shift-php, and everything lands in one portal you run."
            property="og:description"
        />
        <meta content="website" property="og:type" />
    </Head>

    <div class="bg-background text-foreground min-h-screen overflow-x-hidden">
        <header class="bg-background/80 sticky top-0 z-30 border-b backdrop-blur">
            <div class="mx-auto flex h-16 w-full max-w-[1536px] items-center justify-between px-6 lg:px-12">
                <div class="flex shrink-0 items-center gap-3">
                    <img alt="SHIFT" class="h-8 w-11 shrink-0 object-contain" src="/brand/shift-logo.svg" />
                    <span class="text-base font-semibold tracking-tight">SHIFT</span>
                </div>
                <div class="flex items-center gap-1.5">
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
                            <Link :href="route('login')">Sign in</Link>
                        </Button>
                        <Button as-child class="bg-blue-600 text-white hover:bg-blue-700" size="sm">
                            <Link :href="route('register')">Sign up</Link>
                        </Button>
                    </template>
                </div>
            </div>
        </header>

        <main>
            <section class="border-b">
                <div
                    class="mx-auto grid w-full max-w-[1536px] items-center gap-12 px-6 py-20 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.2fr)] lg:gap-20 lg:px-12 lg:py-28"
                >
                    <div class="max-w-2xl">
                        <h1 class="text-4xl leading-[1.04] font-semibold tracking-tight sm:text-5xl lg:text-6xl xl:text-7xl">
                            Tasks from your clients,
                            <span class="text-blue-600 dark:text-blue-400">captured inside their app.</span>
                        </h1>
                        <p class="text-muted-foreground mt-6 max-w-xl text-lg leading-relaxed lg:text-xl">
                            SHIFT adds an issue and task tracker to the Laravel apps you build for clients. They report bugs and requests in context.
                            Your team triages every client from one portal.
                        </p>
                        <p class="text-muted-foreground mt-4 max-w-xl text-base leading-relaxed">
                            For Laravel teams maintaining client apps, support portals, and internal tools.
                        </p>
                        <div class="mt-8 flex flex-wrap items-center gap-3">
                            <Button v-if="!auth?.user" as-child class="bg-blue-600 text-white hover:bg-blue-700" size="lg">
                                <Link :href="route('register')">
                                    Start hosted
                                    <ArrowRight class="ml-1.5 size-4" />
                                </Link>
                            </Button>
                            <Button v-else as-child class="bg-blue-600 text-white hover:bg-blue-700" size="lg">
                                <Link :href="route('dashboard')">
                                    Go to Dashboard
                                    <ArrowRight class="ml-1.5 size-4" />
                                </Link>
                            </Button>
                            <Button as-child size="lg" variant="outline">
                                <a href="https://packagist.org/packages/wyxos/shift-php" rel="noreferrer" target="_blank">
                                    <Package class="mr-1.5 size-4" />
                                    Install package
                                </a>
                            </Button>
                        </div>
                    </div>

                    <figure class="relative">
                        <div
                            class="absolute -inset-8 -z-10 rounded-[2.5rem] bg-gradient-to-tr from-blue-500/15 via-blue-400/5 to-transparent blur-3xl"
                        ></div>
                        <div class="bg-card overflow-hidden rounded-lg border shadow-2xl shadow-blue-950/10">
                            <div class="bg-muted/40 flex items-center gap-2 border-b px-4 py-3">
                                <span class="size-3 rounded-full bg-rose-400/70"></span>
                                <span class="size-3 rounded-full bg-amber-400/70"></span>
                                <span class="size-3 rounded-full bg-emerald-400/70"></span>
                                <span class="bg-background/80 text-muted-foreground mx-auto rounded-md px-3 py-1 text-xs">
                                    {{ embeddedShowcase.url }}
                                </span>
                            </div>
                            <img :alt="embeddedShowcase.alt" class="aspect-[2/1] w-full object-cover object-top" :src="embeddedShowcase.image" />
                        </div>
                    </figure>
                </div>
            </section>

            <section class="border-b">
                <div class="mx-auto w-full max-w-[1536px] px-6 py-16 lg:px-12">
                    <div class="grid gap-4 md:grid-cols-3">
                        <div v-for="(step, i) in workflowSteps" :key="step.label" class="border-border/70 bg-card rounded-lg border p-6">
                            <div class="flex items-center gap-3">
                                <span class="flex size-8 items-center justify-center rounded-full bg-blue-600 text-sm font-semibold text-white">{{
                                    i + 1
                                }}</span>
                                <h2 class="text-base font-semibold">{{ step.label }}</h2>
                            </div>
                            <p class="text-muted-foreground mt-4 text-sm leading-relaxed">{{ step.copy }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="bg-muted/30 border-b">
                <div class="mx-auto w-full max-w-[1536px] px-6 py-24 lg:px-12 lg:py-32">
                    <div class="max-w-3xl">
                        <span class="text-sm font-semibold tracking-wide text-blue-600 uppercase dark:text-blue-400">Two surfaces</span>
                        <h2 class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl lg:text-5xl">
                            Inside the client app, and inside your central platform.
                        </h2>
                    </div>

                    <div class="mt-12 grid gap-8 xl:grid-cols-2">
                        <article v-for="showcase in showcases" :key="showcase.url" class="space-y-5">
                            <div class="max-w-xl">
                                <span class="text-sm font-semibold tracking-wide text-blue-600 uppercase dark:text-blue-400">{{
                                    showcase.label
                                }}</span>
                                <h3 class="mt-2 text-2xl font-semibold tracking-tight">{{ showcase.title }}</h3>
                                <p class="text-muted-foreground mt-3 text-base leading-relaxed">{{ showcase.copy }}</p>
                            </div>
                            <figure class="bg-card overflow-hidden rounded-lg border shadow-xl shadow-blue-950/5">
                                <div class="bg-muted/40 flex items-center gap-2 border-b px-4 py-3">
                                    <span class="size-3 rounded-full bg-rose-400/70"></span>
                                    <span class="size-3 rounded-full bg-amber-400/70"></span>
                                    <span class="size-3 rounded-full bg-emerald-400/70"></span>
                                    <span class="bg-background/80 text-muted-foreground mx-auto rounded-md px-3 py-1 text-xs">
                                        {{ showcase.url }}
                                    </span>
                                </div>
                                <img :alt="showcase.alt" class="aspect-[2/1] w-full object-cover object-top" :src="showcase.image" />
                            </figure>
                        </article>
                    </div>
                </div>
            </section>

            <section class="border-b">
                <div class="mx-auto w-full max-w-[1536px] px-6 py-24 lg:px-12 lg:py-32">
                    <div class="max-w-2xl">
                        <span class="text-sm font-semibold tracking-wide text-blue-600 uppercase dark:text-blue-400">Your infrastructure</span>
                        <h2 class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl lg:text-5xl">Hosted, or on your own server.</h2>
                        <p class="text-muted-foreground mt-6 text-lg leading-relaxed">
                            Start on the hosted portal in minutes, or run the open-source app yourself. Same package either way. The installer just
                            points at whichever SHIFT URL you choose.
                        </p>
                    </div>
                    <div class="mt-12 grid gap-6 lg:grid-cols-2">
                        <div class="bg-card rounded-lg border p-8" data-testid="setup-path-Hosted SHIFT">
                            <div class="flex items-center gap-3">
                                <span class="flex size-11 items-center justify-center rounded-lg bg-blue-600 text-white"
                                    ><Cloud class="size-5"
                                /></span>
                                <h3 class="text-xl font-semibold">Hosted SHIFT</h3>
                            </div>
                            <p class="text-muted-foreground mt-5 text-lg leading-relaxed">
                                Use the managed portal at <span class="text-foreground/80 font-medium">shift.wyxos.com</span>. We run it; you connect
                                projects with the installer. There's a starter plan to get going.
                            </p>
                        </div>
                        <div class="bg-card rounded-lg border p-8" data-testid="setup-path-Self-hosted SHIFT">
                            <div class="flex items-center gap-3">
                                <span class="bg-foreground text-background flex size-11 items-center justify-center rounded-lg"
                                    ><Server class="size-5"
                                /></span>
                                <h3 class="text-xl font-semibold">Self-hosted SHIFT</h3>
                            </div>
                            <p class="text-muted-foreground mt-5 text-lg leading-relaxed">
                                Run the open-source Laravel app on your own server. Your data and your controls, without a hosted billing layer. Point
                                the package at your URL.
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="bg-muted/30 border-b" id="install">
                <div class="mx-auto grid w-full max-w-[1536px] gap-12 px-6 py-24 lg:grid-cols-[0.9fr_1.1fr] lg:px-12 lg:py-32">
                    <div>
                        <span class="text-sm font-semibold tracking-wide text-blue-600 uppercase dark:text-blue-400">Setup</span>
                        <h2 class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl lg:text-5xl">Run php artisan install:shift.</h2>
                        <p class="text-muted-foreground mt-6 text-lg leading-relaxed">
                            The installer detects the current app, opens browser approval, lets you choose a project, and writes the scoped connection
                            details.
                        </p>
                        <div class="mt-8 grid gap-4">
                            <div
                                v-for="(step, i) in installSteps"
                                :key="step.label"
                                class="bg-card rounded-lg border p-5"
                                :data-testid="`install-step-${step.label}`"
                            >
                                <div class="flex items-center gap-2.5">
                                    <span class="flex size-7 items-center justify-center rounded-full bg-blue-600 text-sm font-semibold text-white">{{
                                        i + 1
                                    }}</span>
                                    <span class="font-semibold">{{ step.label }}</span>
                                </div>
                                <pre
                                    class="bg-muted/70 mt-4 overflow-x-auto rounded-md border px-4 py-3 text-sm"
                                ><code>{{ step.command }}</code></pre>
                                <p class="text-muted-foreground mt-3 text-sm">{{ step.note }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-card overflow-hidden rounded-lg border">
                        <div class="border-b px-5 py-4">
                            <h3 class="font-semibold">Installer sequence</h3>
                            <p class="text-muted-foreground mt-1 text-sm">Based on the current package command.</p>
                        </div>
                        <div class="space-y-4 p-5">
                            <pre
                                class="bg-foreground text-background overflow-x-auto rounded-md px-4 py-3 text-sm"
                            ><code>$ php artisan install:shift</code></pre>
                            <ol class="space-y-2">
                                <li
                                    v-for="(line, i) in installFlowLines"
                                    :key="line"
                                    class="grid grid-cols-[2rem_minmax(0,1fr)] items-start gap-3 rounded-md border px-4 py-3"
                                >
                                    <span class="text-muted-foreground font-mono text-xs leading-6">{{ String(i + 1).padStart(2, '0') }}</span>
                                    <span class="font-mono text-sm leading-6">{{ line }}</span>
                                </li>
                            </ol>
                        </div>
                    </div>
                </div>
            </section>

            <section class="border-b">
                <div class="mx-auto w-full max-w-[1536px] px-6 py-24 lg:px-12 lg:py-32">
                    <div class="max-w-2xl">
                        <span class="text-sm font-semibold tracking-wide text-blue-600 uppercase dark:text-blue-400">Questions teams ask</span>
                        <h2 class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl lg:text-5xl">No new client portal to explain.</h2>
                    </div>
                    <div class="mt-12 grid gap-4 lg:grid-cols-2">
                        <article v-for="item in faqItems" :key="item.question" class="bg-card rounded-lg border p-6">
                            <h3 class="font-semibold">{{ item.question }}</h3>
                            <p class="text-muted-foreground mt-3 leading-relaxed">{{ item.answer }}</p>
                        </article>
                    </div>
                </div>
            </section>

            <section>
                <div class="mx-auto w-full max-w-[1536px] px-6 py-24 lg:px-12 lg:py-32">
                    <div class="overflow-hidden rounded-lg bg-blue-600 px-8 py-16 text-white sm:px-16">
                        <div class="flex flex-col items-start justify-between gap-8 lg:flex-row lg:items-end">
                            <div class="max-w-2xl">
                                <h2 class="text-3xl font-semibold tracking-tight sm:text-4xl lg:text-5xl">Give clients a better way to report.</h2>
                                <p class="mt-5 text-lg text-blue-100">Start hosted, or install the package and point it at your own SHIFT portal.</p>
                            </div>
                            <div class="flex shrink-0 flex-wrap gap-3">
                                <Button v-if="!auth?.user" as-child class="bg-white text-blue-700 hover:bg-blue-50" size="lg">
                                    <Link :href="route('register')">Start hosted</Link>
                                </Button>
                                <Button v-else as-child class="bg-white text-blue-700 hover:bg-blue-50" size="lg">
                                    <Link :href="route('dashboard')">Go to Dashboard</Link>
                                </Button>
                                <Button as-child class="border-white/40 bg-transparent text-white hover:bg-white/10" size="lg" variant="outline">
                                    <a href="https://packagist.org/packages/wyxos/shift-php" rel="noreferrer" target="_blank">Install package</a>
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t">
            <div
                class="text-muted-foreground mx-auto flex w-full max-w-[1536px] flex-col gap-2 px-6 py-8 text-sm sm:flex-row sm:items-center sm:justify-between lg:px-12"
            >
                <span>Open source under the MIT License.</span>
                <div class="flex flex-wrap items-center gap-5">
                    <a
                        class="hover:text-foreground inline-flex items-center gap-1.5"
                        href="https://packagist.org/packages/wyxos/shift-php"
                        rel="noreferrer"
                        target="_blank"
                    >
                        <Package class="size-4" />
                        Package
                    </a>
                    <a class="hover:text-foreground inline-flex items-center gap-1.5" href="https://wyxos.com" rel="noreferrer" target="_blank">
                        <ExternalLink class="size-4" />
                        wyxos.com
                    </a>
                    <a
                        class="hover:text-foreground inline-flex items-center gap-1.5"
                        href="https://github.com/wyxos/shift"
                        rel="noreferrer"
                        target="_blank"
                    >
                        <Github class="size-4" />
                        GitHub
                    </a>
                </div>
            </div>
        </footer>
    </div>
</template>
