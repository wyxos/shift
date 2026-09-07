<script setup lang="ts">
import { Badge } from '@/components/ui/badge';

defineProps<{
    email: string;
    emailTestId?: string;
    identityTestId?: string;
    name: string;
    roleLabel: string;
    roleTestId?: string;
    status: 'owner' | 'registered' | 'pending';
    statusLabel: string;
    statusTestId?: string;
}>();

function statusBadgeClass(status: 'owner' | 'registered' | 'pending') {
    if (status === 'owner') {
        return 'bg-emerald-100 text-emerald-900 hover:bg-emerald-100 dark:bg-emerald-500/15 dark:text-emerald-200';
    }

    if (status === 'pending') {
        return 'border-transparent bg-amber-100 text-amber-900 hover:bg-amber-100 dark:bg-amber-500/15 dark:text-amber-200';
    }

    return '';
}
</script>

<template>
    <div class="flex min-w-0 flex-col gap-1" :data-testid="identityTestId">
        <div class="truncate font-medium">{{ name }}</div>
        <div class="text-muted-foreground truncate text-xs" :data-testid="emailTestId">{{ email }}</div>
        <div class="mt-1 flex flex-wrap gap-1.5">
            <Badge variant="outline" :data-testid="roleTestId">{{ roleLabel }}</Badge>
            <Badge :class="statusBadgeClass(status)" variant="secondary" :data-testid="statusTestId">
                {{ statusLabel }}
            </Badge>
        </div>
    </div>
</template>
