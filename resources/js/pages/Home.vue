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
    <Head title="Welcome">
        <meta name="description" content="SHIFT – Laravel-based internal task management and SDK." />
    </Head>
    <div class="p-4 min-h-screen flex flex-col">
        <div class="flex justify-end">
            <div class="flex gap-4 mb-10">
                <a href="https://github.com/wyxos/shift" target="_blank" class="inline-flex items-center gap-2 px-4 py-2 border border-gray-300 dark:border-gray-700 rounded hover:bg-gray-100 dark:hover:bg-gray-800">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 0a12 12 0 00-3.79 23.4c.6.1.82-.26.82-.58v-2.2c-3.34.73-4.04-1.6-4.04-1.6a3.18 3.18 0 00-1.34-1.76c-1.1-.75.08-.74.08-.74a2.52 2.52 0 011.85 1.24 2.56 2.56 0 003.5 1 2.57 2.57 0 01.77-1.6c-2.66-.3-5.46-1.33-5.46-5.92a4.62 4.62 0 011.24-3.2 4.3 4.3 0 01.12-3.16s1-.33 3.3 1.22a11.43 11.43 0 016 0c2.3-1.55 3.3-1.22 3.3-1.22a4.3 4.3 0 01.12 3.16 4.61 4.61 0 011.24 3.2c0 4.6-2.8 5.61-5.47 5.9a2.87 2.87 0 01.82 2.22v3.3c0 .32.22.69.83.57A12 12 0 0012 0z"/>
                    </svg>
                    GitHub<span class="text-sm text-gray-500 dark:text-gray-400">({{ stars }})</span>
                </a>
                <a href="https://wyxos.com" target="_blank" class="px-4 py-2 border border-gray-300 dark:border-gray-700 rounded hover:bg-gray-100 dark:hover:bg-gray-800">
                    Wyxos
                </a>
            </div>
        </div>
        <div class="flex-1 flex flex-col items-center justify-center bg-white dark:bg-gray-900 text-gray-900 dark:text-white p-6">
            <h1 class="text-4xl font-bold mb-2">SHIFT</h1>
            <p class="text-lg mb-6 text-gray-600 dark:text-gray-400 text-center">
                Service Hub for Integrated Framework Tasks — Laravel dashboard + SDK for unified task management.
            </p>



            <div v-if="!auth?.user" class="flex gap-4">
                <Link :href="route('login')" class="px-6 py-3 bg-blue-600 text-white rounded hover:bg-blue-700">Log In</Link>
                <Link :href="route('register')" class="px-6 py-3 border border-blue-600 text-blue-600 rounded hover:bg-blue-50">Register</Link>
            </div>
            <div v-else>
                <Link :href="route('dashboard')" class="mt-4 px-6 py-3 bg-green-600 text-white rounded hover:bg-green-700">Go to Dashboard</Link>
            </div>
        </div>
    </div>
</template>
