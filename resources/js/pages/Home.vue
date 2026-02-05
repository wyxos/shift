<script setup lang="ts">
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Head, Link } from '@inertiajs/vue3'
import { CheckCircle2, FolderKanban, MessageSquare, Paperclip, Plug, ShieldCheck } from 'lucide-vue-next'

defineProps<{
    auth?: {
        user: any | null
    }
}>()
</script>

<template>
    <Head title="SHIFT - Task Operations for Client Work">
        <meta
            name="description"
            content="SHIFT is an open-source task operations platform for client work. Organize delivery across organizations, clients, projects, and tasks with threaded collaboration, attachments, and API/SDK intake."
        />
        <meta
            name="keywords"
            content="task management, client delivery, project tracking, open source, organizations, projects, tasks, threads, attachments, SDK, API"
        />
        <meta property="og:title" content="SHIFT - Task Operations for Client Work" />
        <meta
            property="og:description"
            content="Organize delivery across organizations, clients, projects, and tasks with threaded collaboration, attachments, and API/SDK intake."
        />
        <meta property="og:type" content="website" />
    </Head>

    <div class="min-h-screen bg-background text-foreground">
        <div class="border-b bg-background/80 backdrop-blur">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-6">
                <div class="flex items-center gap-3">
                    <div class="flex size-9 items-center justify-center rounded-lg border bg-primary text-sm font-semibold text-primary-foreground">
                        S
                    </div>
                    <div class="flex flex-col leading-none">
                        <span class="text-sm font-semibold">SHIFT</span>
                        <span class="text-xs text-muted-foreground">Task operations</span>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button as-child variant="ghost" size="sm">
                        <a href="https://github.com/wyxos/shift" target="_blank" rel="noreferrer">GitHub</a>
                    </Button>
                    <Button v-if="auth?.user" as-child size="sm">
                        <Link :href="route('dashboard')">Go to Dashboard</Link>
                    </Button>
                    <Button v-else as-child variant="outline" size="sm">
                        <Link :href="route('login')">Log in</Link>
                    </Button>
                </div>
            </div>
        </div>

        <main class="mx-auto flex w-full max-w-6xl flex-col gap-20 px-6 py-16">
            <section class="grid items-center gap-10 lg:grid-cols-[1.1fr_0.9fr]">
                <div class="space-y-6">
                    <Badge variant="secondary" class="w-fit">Open Source</Badge>
                    <div class="space-y-4">
                        <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">
                            SHIFT keeps client work moving.
                        </h1>
                        <p class="text-lg text-muted-foreground">
                            Organize delivery across organizations, clients, projects, and tasks with clear ownership and shared context.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <Button v-if="auth?.user" as-child size="lg">
                            <Link :href="route('dashboard')">Go to Dashboard</Link>
                        </Button>
                        <Button v-else as-child size="lg">
                            <Link :href="route('login')">Log in</Link>
                        </Button>
                        <Button as-child variant="outline" size="lg">
                            <a href="https://github.com/wyxos/shift" target="_blank" rel="noreferrer">View on GitHub</a>
                        </Button>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs text-muted-foreground">
                        <span class="rounded-full border border-border bg-muted/40 px-3 py-1">Organizations</span>
                        <span class="rounded-full border border-border bg-muted/40 px-3 py-1">Clients</span>
                        <span class="rounded-full border border-border bg-muted/40 px-3 py-1">Projects</span>
                        <span class="rounded-full border border-border bg-muted/40 px-3 py-1">Tasks</span>
                    </div>
                </div>

                <Card class="border-border/70 bg-muted/20 shadow-none">
                    <CardHeader>
                        <CardTitle class="text-base">Portal Overview</CardTitle>
                        <CardDescription>Everything you need to run task delivery in one place.</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="flex items-start gap-3">
                            <div class="flex size-9 items-center justify-center rounded-lg border bg-background">
                                <FolderKanban class="size-4 text-muted-foreground" />
                            </div>
                            <div>
                                <p class="text-sm font-medium">Structured hierarchy</p>
                                <p class="text-sm text-muted-foreground">Stay aligned on scope with org, client, and project layers.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="flex size-9 items-center justify-center rounded-lg border bg-background">
                                <MessageSquare class="size-4 text-muted-foreground" />
                            </div>
                            <div>
                                <p class="text-sm font-medium">Threaded updates</p>
                                <p class="text-sm text-muted-foreground">Keep progress, feedback, and decisions tied to every task.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="flex size-9 items-center justify-center rounded-lg border bg-background">
                                <Paperclip class="size-4 text-muted-foreground" />
                            </div>
                            <div>
                                <p class="text-sm font-medium">Attachments included</p>
                                <p class="text-sm text-muted-foreground">Files stay with the work so nothing gets lost.</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </section>

            <section class="grid gap-6 lg:grid-cols-3">
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Delivery structure</CardTitle>
                        <CardDescription>Model real client work, not just a flat list of tasks.</CardDescription>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground">
                        SHIFT keeps scope and ownership clear across organizations, clients, and projects so teams deliver the right work at the right time.
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Collaboration you can trace</CardTitle>
                        <CardDescription>Every decision stays attached to the task.</CardDescription>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground">
                        Threaded messages, status changes, and attachments build a single source of truth for delivery and support.
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Extensible intake</CardTitle>
                        <CardDescription>Connect SHIFT to the apps your clients already use.</CardDescription>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground">
                        Use the SHIFT API and SDK to sync issues, submit new tasks, and keep external systems aligned.
                    </CardContent>
                </Card>
            </section>

            <section class="grid gap-10 lg:grid-cols-[1.15fr_0.85fr]">
                <div class="space-y-4">
                    <h2 class="text-2xl font-semibold">Built for teams shipping client work.</h2>
                    <p class="text-muted-foreground">
                        SHIFT balances internal operations with external collaboration, so your team can respond faster and deliver with confidence.
                    </p>
                    <div class="grid gap-3">
                        <div class="flex items-start gap-3 text-sm">
                            <CheckCircle2 class="mt-0.5 size-4 text-muted-foreground" />
                            <span>Role-based access for organisations, clients, projects, and tasks.</span>
                        </div>
                        <div class="flex items-start gap-3 text-sm">
                            <CheckCircle2 class="mt-0.5 size-4 text-muted-foreground" />
                            <span>Internal and external task threads that keep feedback in context.</span>
                        </div>
                        <div class="flex items-start gap-3 text-sm">
                            <CheckCircle2 class="mt-0.5 size-4 text-muted-foreground" />
                            <span>Attachment handling built into the workflow, not bolted on.</span>
                        </div>
                    </div>
                </div>
                <Card class="border-border/70 bg-muted/20 shadow-none">
                    <CardHeader>
                        <CardTitle class="text-base">API + SDK ready</CardTitle>
                        <CardDescription>Secure integrations with the same structure as the portal.</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4 text-sm text-muted-foreground">
                        <div class="flex items-start gap-3">
                            <div class="flex size-9 items-center justify-center rounded-lg border bg-background">
                                <Plug class="size-4 text-muted-foreground" />
                            </div>
                            <span>Send tasks from external apps with the SHIFT SDK.</span>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="flex size-9 items-center justify-center rounded-lg border bg-background">
                                <ShieldCheck class="size-4 text-muted-foreground" />
                            </div>
                            <span>Sanctum-secured endpoints for safe inbound workflows.</span>
                        </div>
                    </CardContent>
                </Card>
            </section>

            <section class="flex flex-col items-start justify-between gap-6 rounded-2xl border border-border/70 bg-muted/30 p-8 md:flex-row md:items-center">
                <div class="space-y-2">
                    <h2 class="text-2xl font-semibold">Ready to get back to the work?</h2>
                    <p class="text-sm text-muted-foreground">
                        SHIFT is open source and free to deploy. Build the workflow your team actually needs.
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <Button v-if="auth?.user" as-child>
                        <Link :href="route('dashboard')">Go to Dashboard</Link>
                    </Button>
                    <Button v-else as-child>
                        <Link :href="route('login')">Log in</Link>
                    </Button>
                    <Button as-child variant="outline">
                        <a href="https://github.com/wyxos/shift" target="_blank" rel="noreferrer">Contribute on GitHub</a>
                    </Button>
                </div>
            </section>
        </main>

        <footer class="border-t">
            <div class="mx-auto flex w-full max-w-6xl flex-col gap-2 px-6 py-8 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
                <span>© 2025 SHIFT. Open source under MIT License.</span>
                <div class="flex items-center gap-4">
                    <a href="https://github.com/wyxos/shift" target="_blank" rel="noreferrer" class="hover:text-foreground">
                        GitHub
                    </a>
                    <a href="https://wyxos.com" target="_blank" rel="noreferrer" class="hover:text-foreground">
                        Wyxos
                    </a>
                </div>
            </div>
        </footer>
    </div>
</template>
