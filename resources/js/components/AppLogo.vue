<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const DEFAULT_SHIFT_URL = 'https://shift.wyxos.com';

type ShiftRuntimeConfig = {
    shiftUrl?: string;
};

function shiftBrandBase(): string {
    if (typeof window === 'undefined') {
        return '';
    }

    const shiftConfig = (window as Window & { shiftConfig?: ShiftRuntimeConfig }).shiftConfig;

    if (shiftConfig === undefined) {
        return '';
    }

    const configured = typeof shiftConfig.shiftUrl === 'string' ? shiftConfig.shiftUrl.trim().replace(/\/$/, '') : '';

    return configured === '' ? DEFAULT_SHIFT_URL : configured;
}

const brandBase = shiftBrandBase();

function brandAsset(path: string): string {
    return `${brandBase}${path}`;
}
</script>

<template>
    <span role="img" aria-label="SHIFT" class="inline-flex shrink-0 items-center">
        <img
            v-if="brandBase !== ''"
            :src="brandAsset('/brand/shift-logo.svg')"
            alt=""
            class="hidden size-8 group-data-[collapsible=icon]:block"
            width="132"
            height="102"
        />
        <AppLogoIcon v-else class="hidden size-8 group-data-[collapsible=icon]:block" />
        <span class="group-data-[collapsible=icon]:hidden">
            <img :src="brandAsset('/brand/shift-wordmark-black.svg')" alt="" class="h-8 w-auto dark:hidden" width="410" height="102" />
            <img :src="brandAsset('/brand/shift-wordmark-white.svg')" alt="" class="hidden h-8 w-auto dark:block" width="410" height="102" />
        </span>
    </span>
</template>
