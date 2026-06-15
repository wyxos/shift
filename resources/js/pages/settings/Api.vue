<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import axios from 'axios';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type BreadcrumbItem } from '@/types';
import { RefreshCw } from 'lucide-vue-next';
import { ref } from 'vue';

type TokenRecord = {
    id: number;
    name: string;
    created_at: string | null;
    last_used_at: string | null;
};

type SdkTokenRecord = TokenRecord & {
    project: {
        id: number;
        name: string;
    } | null;
};

const props = withDefaults(
    defineProps<{
        token?: string;
        mcpTokens?: TokenRecord[];
        sdkTokens?: SdkTokenRecord[];
    }>(),
    {
        token: '',
        mcpTokens: () => [],
        sdkTokens: () => [],
    },
);

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'API tokens',
        href: '/settings/api',
    },
];

const form = useForm({
    name: '',
});
const issuedToken = ref(props.token);
const mcpTokens = ref<TokenRecord[]>([...props.mcpTokens]);
const sdkTokens = ref<SdkTokenRecord[]>([...props.sdkTokens]);
const mcpResetting = ref(false);
const sdkResetting = ref<number | null>(null);
const resetError = ref<string | null>(null);

const createApiToken = () => {
    form.put(route('api.update'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
        },
    });
};

async function resetMcpToken() {
    mcpResetting.value = true;
    resetError.value = null;

    try {
        const response = await axios.post(
            '/settings/api/tokens/mcp/reset',
            {},
            {
                headers: {
                    Accept: 'application/json',
                },
            },
        );

        issuedToken.value = response.data.token;
        mcpTokens.value = [response.data.record];
    } catch (error) {
        console.error('Error resetting MCP token:', error);
        resetError.value = 'Unable to reset the MCP token right now.';
    } finally {
        mcpResetting.value = false;
    }
}

async function resetSdkToken(token: SdkTokenRecord) {
    sdkResetting.value = token.id;
    resetError.value = null;

    try {
        const response = await axios.post(
            `/settings/api/tokens/sdk/${token.id}/reset`,
            {},
            {
                headers: {
                    Accept: 'application/json',
                },
            },
        );

        issuedToken.value = response.data.token;
        sdkTokens.value = sdkTokens.value.map((item) => (item.id === token.id ? response.data.record : item));
    } catch (error) {
        console.error('Error resetting SHIFT SDK token:', error);
        resetError.value = 'Unable to reset the SHIFT SDK token right now.';
    } finally {
        sdkResetting.value = null;
    }
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="API Tokens" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall title="Personal integration tokens" description="Manage the tokens connected to your account." />

                <div v-if="issuedToken" class="rounded border border-emerald-200 bg-emerald-50 p-4 text-emerald-900">
                    <p class="font-semibold">New token</p>
                    <p class="mt-2 font-mono text-sm break-all">{{ issuedToken }}</p>
                </div>

                <div v-if="resetError" class="rounded border border-red-200 bg-red-50 p-4 text-sm text-red-700">{{ resetError }}</div>

                <section class="space-y-3 rounded border p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-base font-semibold">MCP token</h2>
                            <p class="text-muted-foreground text-sm">Used by Codex MCP connections.</p>
                        </div>
                        <Button type="button" :disabled="mcpResetting" data-testid="reset-mcp-token" @click="resetMcpToken">
                            <RefreshCw class="mr-2 h-4 w-4" />
                            Reset
                        </Button>
                    </div>

                    <div class="space-y-2">
                        <div
                            v-for="tokenRecord in mcpTokens"
                            :key="tokenRecord.id"
                            class="flex items-center justify-between gap-4 rounded border p-3 text-sm"
                        >
                            <span class="font-medium">{{ tokenRecord.name }}</span>
                            <span class="text-muted-foreground">ID {{ tokenRecord.id }}</span>
                        </div>
                        <p v-if="!mcpTokens.length" class="text-muted-foreground text-sm">No MCP token has been issued.</p>
                    </div>
                </section>

                <section class="space-y-3 rounded border p-4">
                    <div>
                        <h2 class="text-base font-semibold">SHIFT SDK tokens</h2>
                        <p class="text-muted-foreground text-sm">Used by approved SHIFT SDK installs.</p>
                    </div>

                    <div class="space-y-2">
                        <div
                            v-for="tokenRecord in sdkTokens"
                            :key="tokenRecord.id"
                            class="flex flex-col gap-3 rounded border p-3 text-sm sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div class="grid gap-1">
                                <span class="font-medium">{{ tokenRecord.project?.name ?? tokenRecord.name }}</span>
                                <span class="text-muted-foreground break-all">{{ tokenRecord.name }}</span>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="sdkResetting === tokenRecord.id"
                                :data-testid="`reset-sdk-token-${tokenRecord.id}`"
                                @click="resetSdkToken(tokenRecord)"
                            >
                                <RefreshCw class="mr-2 h-4 w-4" />
                                Reset
                            </Button>
                        </div>
                        <p v-if="!sdkTokens.length" class="text-muted-foreground text-sm">No SHIFT SDK tokens have been issued.</p>
                    </div>
                </section>

                <HeadingSmall title="Create API Token" description="Create a new personal access token to use with the SHIFT API." />

                <form @submit.prevent="createApiToken" class="space-y-6">
                    <div>
                        <Label for="name">Token Name</Label>
                        <Input id="name" v-model="form.name" type="text" class="mt-1 block w-full" autocomplete="off" />
                        <InputError :message="form.errors.name" class="mt-2" />
                    </div>

                    <div class="flex items-center gap-4">
                        <Button :disabled="form.processing">Create API Token</Button>

                        <Transition
                            enter-active-class="transition ease-in-out"
                            enter-from-class="opacity-0"
                            leave-active-class="transition ease-in-out"
                            leave-to-class="opacity-0"
                        >
                            <p v-show="form.recentlySuccessful" class="text-sm text-neutral-600">Created.</p>
                        </Transition>
                    </div>
                </form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
