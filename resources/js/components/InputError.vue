<script setup lang="ts">
defineProps<{
    id?: string;
    message?: string;
}>();
</script>

<template>
    <div v-show="message">
        <p :id="id" aria-live="polite" class="text-sm text-red-600 dark:text-red-500" role="alert">
            {{ message }}
        </p>
    </div>
</template>
