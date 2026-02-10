<script lang="ts" setup>
import Home from '@/pages/Home.vue';
</script>

<template>
    <!-- Kept for backwards-compat / tests; primary landing page is Home.vue -->
    <Home :auth="$page.props.auth" />
</template>
