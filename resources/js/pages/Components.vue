<script setup lang="ts">
import ShiftEditor from '@/components/ShiftEditor.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Components', href: '/components' }];

const shiftRef = ref<InstanceType<typeof ShiftEditor> | null>(null);
const editor = computed(() => shiftRef.value?.editor ?? null);

defineExpose({ editor });
</script>

<template>
    <Head title="Components" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-4">
            <ShiftEditor ref="shiftRef" />
        </div>
    </AppLayout>
</template>

<style></style>
