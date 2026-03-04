<script setup lang="ts">
import ShiftEditor from '@/components/ShiftEditor.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Components', href: '/components' }];

const shiftRef = ref<InstanceType<typeof ShiftEditor> | null>(null);
const editor = computed(() => shiftRef.value?.editor ?? null);
const page = usePage<SharedData>();
const aiImproveEnabled = computed(() => Boolean(page.props.shift?.ai_enabled));

defineExpose({ editor });
</script>

<template>
    <Head title="Components" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-4">
            <ShiftEditor ref="shiftRef" :enable-ai-improve="aiImproveEnabled" />
        </div>
    </AppLayout>
</template>

<style></style>
