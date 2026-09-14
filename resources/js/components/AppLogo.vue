<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
</script>

<template>
    <span role="img" aria-label="SHIFT" class="inline-flex shrink-0 items-center">
        <AppLogoIcon class="hidden size-8 group-data-[collapsible=icon]:block" />
        <span class="group-data-[collapsible=icon]:hidden">
            <img src="/brand/shift-wordmark-black.svg" alt="" class="h-8 w-auto dark:hidden" width="410" height="102" />
            <img src="/brand/shift-wordmark-white.svg" alt="" class="hidden h-8 w-auto dark:block" width="410" height="102" />
        </span>
    </span>
</template>
