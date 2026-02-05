<script lang="ts" setup>
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Head, Link } from '@inertiajs/vue3'
import { CheckCircle2, FolderKanban, Github, MessageSquare, Paperclip, Plug } from 'lucide-vue-next'

defineProps<{
    auth?: {
        user: any | null
    }
}>()
</script>

<template>
    <Head title="SHIFT - Task Operations for Client Work">
        <meta
            content="SHIFT is an open-source task operations platform for client work. Organize delivery across organizations, clients, projects, and tasks with clear ownership and shared context."
            name="description"
        />
        <meta
            content="task management, client delivery, project tracking, open source, organizations, projects, tasks, threads, attachments"
            name="keywords"
        />
        <meta content="SHIFT - Task Operations for Client Work" property="og:title" />
        <meta
            content="Organize delivery across organizations, clients, projects, and tasks with clear ownership and shared context."
            property="og:description"
        />
        <meta content="website" property="og:type" />
    </Head>

    <div class="min-h-screen bg-background text-foreground">
        <div class="border-b bg-background/90 backdrop-blur">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-6">
                <div class="flex items-center gap-3">
                    <img
                        alt="SHIFT logo"
                        class="h-8 w-auto"
                        src="/brand/shift-logo.svg"
                    />
                    <div class="flex flex-col leading-none">
                        <span class="text-sm font-semibold">SHIFT</span>
                        <span class="text-xs text-muted-foreground">Task operations</span>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button as-child class="h-9 w-9" size="icon" variant="ghost">
                        <a aria-label="GitHub" href="https://github.com/wyxos/shift" rel="noreferrer" target="_blank">
                            <Github class="size-4 text-muted-foreground" />
                        </a>
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
                        Built for client work
                    </Badge>
                    <div class="space-y-4">
                        <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">
                            SHIFT keeps client work on track.
                        </h1>
                        <p class="text-lg text-muted-foreground">
                            Capture requests, track delivery, and keep context together across organizations, clients, projects, and tasks.
                        </p>
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
                            <a href="#workflow">See how it works</a>
                        </Button>
                    </div>
                </div>

                <Card class="border-blue-100/70 bg-blue-50/20 shadow-none dark:border-blue-500/20 dark:bg-blue-500/5">
                    <CardHeader>
                        <CardTitle class="text-base">What SHIFT solves</CardTitle>
                        <CardDescription>Less chaos, clearer delivery, better visibility.</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="flex items-start gap-3">
                            <div class="flex size-9 items-center justify-center rounded-lg border border-blue-100/70 bg-background">
                                <CheckCircle2 class="size-4 text-blue-600" />
                            </div>
                            <div>
                                <p class="text-sm font-medium">Requests stop getting lost</p>
                                <p class="text-sm text-muted-foreground">Every request becomes a tracked task with clear status.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="flex size-9 items-center justify-center rounded-lg border border-blue-100/70 bg-background">
                                <CheckCircle2 class="size-4 text-blue-600" />
                            </div>
                            <div>
                                <p class="text-sm font-medium">Ownership stays visible</p>
                                <p class="text-sm text-muted-foreground">Know who owns what across clients and projects.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="flex size-9 items-center justify-center rounded-lg border border-blue-100/70 bg-background">
                                <CheckCircle2 class="size-4 text-blue-600" />
                            </div>
                            <div>
                                <p class="text-sm font-medium">Context lives with the work</p>
                                <p class="text-sm text-muted-foreground">Threads and files stay attached to each task.</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </section>

            <section id="workflow" class="space-y-8">
                <div class="space-y-2">
                    <h2 class="text-2xl font-semibold">A workflow that matches client delivery</h2>
                    <p class="text-sm text-muted-foreground">
                        SHIFT mirrors how agencies and teams actually ship work, so scope and accountability stay clear.
                    </p>
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

            <section class="grid gap-6 lg:grid-cols-3">
                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex size-9 items-center justify-center rounded-lg border border-blue-100/70 bg-background">
                                <MessageSquare class="size-4 text-blue-600" />
                            </div>
                            <div>
                                <CardTitle class="text-base">Threaded updates</CardTitle>
                                <CardDescription>Every decision stays attached to the task.</CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground">
                        Keep internal and client-facing conversations in one place so there is no lost context.
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex size-9 items-center justify-center rounded-lg border border-blue-100/70 bg-background">
                                <Paperclip class="size-4 text-blue-600" />
                            </div>
                            <div>
                                <CardTitle class="text-base">Attachments included</CardTitle>
                                <CardDescription>Files stay with the work.</CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground">
                        Briefs, screenshots, and deliverables live alongside the task they belong to.
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex size-9 items-center justify-center rounded-lg border border-blue-100/70 bg-background">
                                <FolderKanban class="size-4 text-blue-600" />
                            </div>
                            <div>
                                <CardTitle class="text-base">Status clarity</CardTitle>
                                <CardDescription>Know what is pending, active, and done.</CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground">
                        A clean dashboard makes it easy to report and prioritize the work that matters.
                    </CardContent>
                </Card>
            </section>

            <section class="grid gap-10 lg:grid-cols-[1.1fr_0.9fr]">
                <div class="space-y-4">
                    <h2 class="text-2xl font-semibold">Bring work in from client tools</h2>
                    <p class="text-muted-foreground">
                        SHIFT can accept tasks from external systems so clients can submit work without changing their workflow.
                    </p>
                </div>
                <Card class="border-blue-100/70 bg-blue-50/20 shadow-none dark:border-blue-500/20 dark:bg-blue-500/5">
                    <CardHeader>
                        <CardTitle class="text-base">API + SDK ready</CardTitle>
                        <CardDescription>Connect intake while keeping the portal as the source of truth.</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4 text-sm text-muted-foreground">
                        <div class="flex items-start gap-3">
                            <div class="flex size-9 items-center justify-center rounded-lg border border-blue-100/70 bg-background">
                                <Plug class="size-4 text-blue-600" />
                            </div>
                            <span>Route external requests into SHIFT with consistent structure.</span>
                        </div>
                    </CardContent>
                </Card>
            </section>
        </main>

        <footer class="border-t">
            <div class="mx-auto flex w-full max-w-6xl flex-col gap-2 px-6 py-8 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
                <span>© 2025 SHIFT. Open source under MIT License.</span>
                <div class="flex items-center gap-4">
                    <a class="hover:text-foreground" href="https://wyxos.com" rel="noreferrer" target="_blank">
                        Wyxos
                    </a>
                </div>
            </div>
        </footer>
    </div>
</template>
