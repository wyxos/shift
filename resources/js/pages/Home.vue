<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    auth?: {
        user: any | null
    }
}>()
</script>

<template>
    <Head title="Welcome">
        <meta name="description" content="SHIFT — Service Hub for Integrated Framework Tasks." />
    </Head>

    <div class="flex min-h-screen flex-col justify-center items-center bg-white dark:bg-gray-900 text-gray-900 dark:text-white p-6">
        <h1 class="text-4xl font-bold mb-4">Welcome to SHIFT</h1>
        <p class="mb-8 text-lg text-gray-600 dark:text-gray-400">
            Service Hub for Integrated Framework Tasks — streamline your Laravel project workflows.
        </p>

        <div class="flex gap-4" v-if="!auth?.user">
            <Link :href="route('login')" class="px-6 py-3 bg-blue-600 text-white rounded hover:bg-blue-700">Log In</Link>
            <Link :href="route('register')" class="px-6 py-3 border border-blue-600 text-blue-600 rounded hover:bg-blue-50">Register</Link>
        </div>
        <div v-else>
            <Link :href="route('dashboard')" class="mt-4 px-6 py-3 bg-green-600 text-white rounded hover:bg-green-700">Go to Dashboard</Link>
        </div>
    </div>
</template>
