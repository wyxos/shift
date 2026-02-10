<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { Head, useForm } from '@inertiajs/vue3';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type BreadcrumbItem } from '@/types';

defineProps({
    token: {
        type: String,
        default: '',
    },
});

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Password settings',
        href: '/settings/password',
    },
];

const form = useForm({
    name: '',
});

const createApiToken = () => {
    form.put(route('api.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="API Tokens" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall title="Create API Token" description="Create a new personal access token to use with the SHIFT API." />

                <div v-if="token" class="rounded bg-green-100 p-4 text-green-800">
                    <p class="font-semibold">Here is your new API token. Copy it now! It won't be shown again.</p>
                    <p class="mt-2 break-all">{{ token }}</p>
                </div>

                <form @submit.prevent="createApiToken" class="space-y-6">
                    <div>
                        <Label for="name" value="Token Name" />
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
