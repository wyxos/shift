<script setup lang="ts">
import { Badge } from '@/components/ui/badge';

type OrganisationTeamUser = {
    id: string;
    name: string;
    email: string;
    status: 'owner' | 'registered' | 'pending';
    statusLabel: string;
};

defineProps<{
    organisation: {
        name: string;
        teamUsers: OrganisationTeamUser[];
    };
}>();

function statusBadgeClass(status: OrganisationTeamUser['status']) {
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
    <section class="bg-card rounded-xl border p-4">
        <div class="mb-4 flex flex-col gap-1">
            <h1 class="text-lg font-semibold">Team</h1>
            <p class="text-muted-foreground text-sm">{{ organisation.name }}</p>
        </div>

        <div class="overflow-hidden rounded-lg border">
            <div v-if="organisation.teamUsers.length === 0" class="text-muted-foreground p-4 text-sm">No users have access to this organisation.</div>
            <div
                v-for="teamUser in organisation.teamUsers"
                v-else
                :key="teamUser.id"
                class="flex items-center justify-between gap-4 border-b p-3 last:border-b-0"
                :data-testid="`organisation-team-user-${teamUser.id}`"
            >
                <div class="min-w-0">
                    <div class="truncate font-medium">
                        {{ teamUser.name }}
                        <span class="text-muted-foreground font-normal">({{ teamUser.email }})</span>
                    </div>
                </div>
                <Badge :class="statusBadgeClass(teamUser.status)" variant="secondary">{{ teamUser.statusLabel }}</Badge>
            </div>
        </div>
    </section>
</template>
