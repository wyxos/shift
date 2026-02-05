<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    auth?: {
        user: any | null
    }
}>()

const stars = 0 // optionally fetch from GitHub API later
</script>

<template>
    <Head title="SHIFT - Open Source Task Management System">
        <meta name="description" content="SHIFT is an open-source, modular task management system built with Laravel and Vue.js. Streamline task tracking and project collaboration across teams with our powerful dashboard and SDK integration." />
        <meta name="keywords" content="task management, project management, open source, Laravel, Vue.js, collaboration, productivity, teams, dashboard, SDK" />
        <meta property="og:title" content="SHIFT - Open Source Task Management System" />
        <meta property="og:description" content="Streamline task tracking and project collaboration with our modular task management system. Built with Laravel and Vue.js." />
        <meta property="og:type" content="website" />
    </Head>
    <div class="min-h-screen bg-gradient-to-br from-slate-50 to-slate-100 dark:from-slate-900 dark:to-slate-800">
        <!-- Navigation -->
        <nav class="border-b border-slate-200 bg-white/50 backdrop-blur-lg dark:border-slate-700 dark:bg-slate-800/50">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 items-center justify-between">
                    <div class="flex items-center space-x-4">
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-blue-500 to-purple-600 text-white font-bold">
                            S
                        </div>
                        <span class="text-xl font-bold text-slate-900 dark:text-slate-100">SHIFT</span>
                    </div>
                    <div class="flex items-center space-x-4">
                        <a href="https://github.com/wyxos/shift" target="_blank" class="text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100 transition-colors">
                            <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/>
                            </svg>
                        </a>
                        <a href="https://wyxos.com" target="_blank" class="text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100 transition-colors">
                            Wyxos
                        </a>
                        <div v-if="!auth?.user" class="flex items-center space-x-2">
                            <Link :href="route('login')" class="text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100 transition-colors">
                                Log in
                            </Link>
                        </div>
                        <div v-else>
                            <Link :href="route('dashboard')" class="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 transition-colors">
                                Dashboard
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Hero Section -->
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-20">
            <div class="text-center">
                <h1 class="text-4xl font-bold tracking-tight text-slate-900 dark:text-slate-100 sm:text-6xl">
                    Streamline Task Management
                    <span class="text-transparent bg-clip-text bg-gradient-to-r from-blue-600 to-purple-600">with SHIFT</span>
                </h1>
                <p class="mt-6 text-lg leading-8 text-slate-600 dark:text-slate-400 max-w-2xl mx-auto">
                    SHIFT is an open-source, modular task management system built with Laravel and Vue.js. 
                    Designed to streamline task tracking and project collaboration across multiple teams and applications.
                </p>
                <div class="mt-10 flex items-center justify-center gap-x-6">
                    <Link v-if="auth?.user" :href="route('dashboard')" class="rounded-lg bg-green-600 px-8 py-3 text-base font-semibold text-white shadow-sm hover:bg-green-700 transition-colors">
                        Go to Dashboard
                    </Link>
                    <a href="https://github.com/wyxos/shift" target="_blank" class="text-base font-semibold leading-6 text-slate-900 dark:text-slate-100 hover:text-slate-600 dark:hover:text-slate-400 transition-colors">
                        View on GitHub <span aria-hidden="true">→</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Features Section -->
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-20">
            <div class="mx-auto max-w-2xl text-center">
                <h2 class="text-base font-semibold leading-7 text-blue-600 dark:text-blue-400">Everything you need</h2>
                <p class="mt-2 text-3xl font-bold tracking-tight text-slate-900 dark:text-slate-100 sm:text-4xl">
                    Powerful features for modern teams
                </p>
                <p class="mt-6 text-lg leading-8 text-slate-600 dark:text-slate-400">
                    SHIFT combines a comprehensive dashboard with a flexible SDK to provide complete task management solutions.
                </p>
            </div>
            <div class="mx-auto mt-16 max-w-2xl sm:mt-20 lg:mt-24 lg:max-w-none">
                <dl class="grid max-w-xl grid-cols-1 gap-x-8 gap-y-16 lg:max-w-none lg:grid-cols-3">
                    <div class="flex flex-col">
                        <dt class="flex items-center gap-x-3 text-base font-semibold leading-7 text-slate-900 dark:text-slate-100">
                            <div class="h-10 w-10 flex items-center justify-center rounded-lg bg-blue-600">
                                <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v6a2 2 0 002 2h6a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                                </svg>
                            </div>
                            Dashboard Management
                        </dt>
                        <dd class="mt-4 flex flex-auto flex-col text-base leading-7 text-slate-600 dark:text-slate-400">
                            <p class="flex-auto">Comprehensive web UI to manage organizations, clients, projects, and tasks with role-based access control.</p>
                        </dd>
                    </div>
                    <div class="flex flex-col">
                        <dt class="flex items-center gap-x-3 text-base font-semibold leading-7 text-slate-900 dark:text-slate-100">
                            <div class="h-10 w-10 flex items-center justify-center rounded-lg bg-blue-600">
                                <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m13 0h-6m-2-5v6a2 2 0 01-2 2H6a2 2 0 01-2-2V9a2 2 0 012-2h8a2 2 0 012 2z"></path>
                                </svg>
                            </div>
                            SDK Integration
                        </dt>
                        <dd class="mt-4 flex flex-auto flex-col text-base leading-7 text-slate-600 dark:text-slate-400">
                            <p class="flex-auto">The <strong>shift-php</strong> Laravel package enables seamless integration with external applications to submit and sync tasks via API.</p>
                        </dd>
                    </div>
                    <div class="flex flex-col">
                        <dt class="flex items-center gap-x-3 text-base font-semibold leading-7 text-slate-900 dark:text-slate-100">
                            <div class="h-10 w-10 flex items-center justify-center rounded-lg bg-blue-600">
                                <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                </svg>
                            </div>
                            Team Collaboration
                        </dt>
                        <dd class="mt-4 flex flex-auto flex-col text-base leading-7 text-slate-600 dark:text-slate-400">
                            <p class="flex-auto">Multi-user support with project assignments, priority levels, status tracking, and team management features.</p>
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        <!-- Tech Stack Section -->
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-20">
            <div class="mx-auto max-w-2xl text-center">
                <h2 class="text-base font-semibold leading-7 text-blue-600 dark:text-blue-400">Built with modern technology</h2>
                <p class="mt-2 text-3xl font-bold tracking-tight text-slate-900 dark:text-slate-100 sm:text-4xl">
                    Reliable Tech Stack
                </p>
            </div>
            <div class="mx-auto mt-16 grid max-w-2xl grid-cols-1 gap-6 sm:grid-cols-2 lg:mx-0 lg:max-w-none lg:grid-cols-4">
                <div class="flex flex-col items-center p-6 bg-white dark:bg-slate-800 rounded-lg shadow-sm">
                    <div class="text-2xl font-bold text-red-600 mb-2">Laravel</div>
                    <p class="text-sm text-slate-600 dark:text-slate-400 text-center">PHP framework for robust backend development</p>
                </div>
                <div class="flex flex-col items-center p-6 bg-white dark:bg-slate-800 rounded-lg shadow-sm">
                    <div class="text-2xl font-bold text-green-600 mb-2">Vue.js</div>
                    <p class="text-sm text-slate-600 dark:text-slate-400 text-center">Progressive JavaScript framework for dynamic UIs</p>
                </div>
                <div class="flex flex-col items-center p-6 bg-white dark:bg-slate-800 rounded-lg shadow-sm">
                    <div class="text-2xl font-bold text-blue-600 mb-2">TypeScript</div>
                    <p class="text-sm text-slate-600 dark:text-slate-400 text-center">Type-safe JavaScript for better development</p>
                </div>
                <div class="flex flex-col items-center p-6 bg-white dark:bg-slate-800 rounded-lg shadow-sm">
                    <div class="text-2xl font-bold text-cyan-600 mb-2">Tailwind</div>
                    <p class="text-sm text-slate-600 dark:text-slate-400 text-center">Utility-first CSS framework for modern design</p>
                </div>
            </div>
        </div>

        <!-- CTA Section -->
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-20">
            <div class="mx-auto max-w-2xl text-center">
                <h2 class="text-3xl font-bold tracking-tight text-slate-900 dark:text-slate-100 sm:text-4xl">
                    Ready to get started?
                </h2>
                <p class="mt-6 text-lg leading-8 text-slate-600 dark:text-slate-400">
                    SHIFT is completely open source and free to use. Deploy it on your own infrastructure and take full control of your task management.
                </p>
                <div class="mt-10 flex items-center justify-center gap-x-6">
                    <Link v-if="auth?.user" :href="route('dashboard')" class="rounded-lg bg-green-600 px-8 py-3 text-base font-semibold text-white shadow-sm hover:bg-green-700 transition-colors">
                        Go to Dashboard
                    </Link>
                    <a href="https://github.com/wyxos/shift" target="_blank" class="text-base font-semibold leading-6 text-slate-900 dark:text-slate-100 hover:text-slate-600 dark:hover:text-slate-400 transition-colors">
                        Contribute on GitHub <span aria-hidden="true">→</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="border-t border-slate-200 dark:border-slate-700">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                <div class="flex justify-between items-center">
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        © 2025 SHIFT. Open source under MIT License.
                    </p>
                    <div class="flex space-x-6">
                        <a href="https://github.com/wyxos/shift" target="_blank" class="text-slate-400 hover:text-slate-500 dark:hover:text-slate-300">
                            <span class="sr-only">GitHub</span>
                            <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/>
                            </svg>
                        </a>
                        <a href="https://wyxos.com" target="_blank" class="text-slate-400 hover:text-slate-500 dark:hover:text-slate-300">
                            <span class="sr-only">Wyxos</span>
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                            </svg>
                        </a>
                    </div>
                </div>
            </div>
        </footer>
    </div>
</template>
