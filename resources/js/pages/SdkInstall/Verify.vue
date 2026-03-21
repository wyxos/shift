<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface InstallSession {
    user_code: string;
    state: 'pending' | 'approved' | 'expired';
    environment: string | null;
    environment_label: string | null;
    url: string | null;
    expires_at: string | null;
    approved: {
        at: string | null;
        by_current_user: boolean;
    } | null;
}

const props = defineProps<{
    userCode?: string | null;
    lookupError?: string | null;
    session?: InstallSession | null;
}>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'SDK Install',
        href: '/sdk/install',
    },
];

const lookupForm = useForm({
    user_code: props.userCode ?? '',
});

const approveForm = useForm({
    user_code: props.userCode ?? '',
});

const statusLabel = computed(() => {
    switch (props.session?.state) {
        case 'approved':
            return 'Approved';
        case 'expired':
            return 'Expired';
        default:
            return 'Pending';
    }
});

const statusVariant = computed<'default' | 'secondary' | 'destructive' | 'outline'>(() => {
    switch (props.session?.state) {
        case 'approved':
            return 'secondary';
        case 'expired':
            return 'destructive';
        default:
            return 'outline';
    }
});

const expiresAt = computed(() => {
    if (!props.session?.expires_at) return null;

    return new Date(props.session.expires_at).toLocaleString();
});

const approvedAt = computed(() => {
    if (!props.session?.approved?.at) return null;

    return new Date(props.session.approved.at).toLocaleString();
});

const canApprove = computed(() => props.session?.state === 'pending');
const approvedByCurrentUser = computed(() => props.session?.approved?.by_current_user === true);
const approvedByAnotherUser = computed(() => props.session?.state === 'approved' && props.session?.approved?.by_current_user === false);

const findInstall = () => {
    router.get(route('sdk-install.verify'), lookupForm.user_code ? { user_code: lookupForm.user_code } : {}, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
};

const approveInstall = () => {
    approveForm.user_code = props.session?.user_code ?? lookupForm.user_code;

    approveForm.post(route('sdk-install.approve'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="SDK Install" />

        <div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
            <Card>
                <CardHeader>
                    <CardTitle>Approve a SHIFT SDK install</CardTitle>
                    <CardDescription> Enter the code from your terminal, or open the verification link directly from the installer. </CardDescription>
                </CardHeader>
                <CardContent>
                    <form class="flex flex-col gap-4 sm:flex-row sm:items-end" @submit.prevent="findInstall">
                        <div class="flex-1 space-y-2">
                            <Label for="user_code">Install code</Label>
                            <Input id="user_code" v-model="lookupForm.user_code" autocomplete="off" class="uppercase" placeholder="ABCD-EFGH" />
                            <InputError :message="lookupError ?? approveForm.errors.user_code" />
                        </div>

                        <Button type="submit" variant="outline">Find install</Button>
                    </form>
                </CardContent>
            </Card>

            <Card v-if="session">
                <CardHeader class="gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="space-y-2">
                        <CardTitle>Install request {{ session.user_code }}</CardTitle>
                        <CardDescription> Review the target environment and approve this session from your SHIFT account. </CardDescription>
                    </div>

                    <Badge :variant="statusVariant">{{ statusLabel }}</Badge>
                </CardHeader>
                <CardContent class="space-y-6">
                    <dl class="grid gap-4 text-sm sm:grid-cols-2">
                        <div class="space-y-1">
                            <dt class="text-muted-foreground">Environment</dt>
                            <dd class="font-medium">{{ session.environment_label ?? session.environment ?? 'Unknown' }}</dd>
                        </div>
                        <div class="space-y-1">
                            <dt class="text-muted-foreground">Consumer URL</dt>
                            <dd class="font-medium break-all">{{ session.url ?? 'Unknown' }}</dd>
                        </div>
                        <div class="space-y-1">
                            <dt class="text-muted-foreground">Expires</dt>
                            <dd class="font-medium">{{ expiresAt ?? 'Expired' }}</dd>
                        </div>
                        <div v-if="approvedAt" class="space-y-1">
                            <dt class="text-muted-foreground">Approved</dt>
                            <dd class="font-medium">{{ approvedAt }}</dd>
                        </div>
                    </dl>

                    <div v-if="canApprove" class="space-y-3 rounded-lg border border-dashed p-4">
                        <p class="text-sm text-neutral-600">
                            Approval binds this install session to your account. The terminal can only finalize against projects you manage.
                        </p>
                        <Button :disabled="approveForm.processing" @click="approveInstall"> Approve install </Button>
                    </div>

                    <div v-else-if="approvedByCurrentUser" class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-900">
                        This install has been approved from your account. Return to the terminal to choose a project and finish the setup.
                    </div>

                    <div v-else-if="approvedByAnotherUser" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                        This install request was already approved by another SHIFT user and cannot be rebound here.
                    </div>

                    <div v-else class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900">
                        This install code has expired. Restart the installer to generate a new one.
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
