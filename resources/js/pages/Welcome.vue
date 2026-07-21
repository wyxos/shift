<script lang="ts" setup>
import Home from '@/pages/Home.vue';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';

const page = usePage<SharedData>();
</script>

<template>
    <!-- Kept for backwards-compat / tests; primary landing page is Home.vue -->
    <Home :auth="page.props.auth" />
</template>
