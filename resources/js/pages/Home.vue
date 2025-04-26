<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Welcome" />

    <div class="flex min-h-screen flex-col justify-center items-center bg-white dark:bg-gray-900 text-gray-900 dark:text-white p-6">
        <h1 class="text-4xl font-bold mb-4">Welcome to SHIFT</h1>
        <p class="mb-8 text-lg text-gray-600 dark:text-gray-400">Manage projects, tasks, and issues easily with Laravel-powered speed.</p>

        <div class="flex gap-4">
            <Link :href="route('login')" class="px-6 py-3 bg-blue-600 text-white rounded hover:bg-blue-700">Log In</Link>
            <Link :href="route('register')" class="px-6 py-3 border border-blue-600 text-blue-600 rounded hover:bg-blue-50">Register</Link>
        </div>
    </div>
</template>
