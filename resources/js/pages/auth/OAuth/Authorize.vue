<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head } from '@inertiajs/vue3';
import { Check, ShieldCheck } from 'lucide-vue-next';

defineProps<{
    client: {
        name: string;
    };
    scopes: Array<{
        id: string;
        description: string;
    }>;
    authToken: string;
    csrfToken: string;
    approveUrl: string;
    denyUrl: string;
}>();
</script>

<template>
    <AuthLayout title="Authorize MCP access" :description="`${client.name} is requesting access to your SHIFT account.`">
        <Head title="Authorize MCP access" />

        <div class="flex flex-col gap-6">
            <div class="border-border bg-muted/40 flex gap-3 rounded-lg border p-4">
                <ShieldCheck class="text-primary mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
                <div class="grid gap-1">
                    <p class="text-sm font-medium">OAuth-secured connection</p>
                    <p class="text-muted-foreground text-sm">
                        SHIFT will issue a short-lived access token. Your password is never shared with the MCP client.
                    </p>
                </div>
            </div>

            <section class="grid gap-3" aria-labelledby="oauth-permissions-heading">
                <h2 id="oauth-permissions-heading" class="text-sm font-semibold">Requested permissions</h2>
                <ul class="grid gap-3">
                    <li v-for="scope in scopes" :key="scope.id" class="flex gap-3 text-sm">
                        <Check class="text-primary mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                        <div class="grid gap-1">
                            <span class="font-medium">{{ scope.id }}</span>
                            <span class="text-muted-foreground">{{ scope.description }}</span>
                        </div>
                    </li>
                </ul>
            </section>

            <div class="grid gap-3 sm:grid-cols-2">
                <form :action="denyUrl" method="post">
                    <input type="hidden" name="_token" :value="csrfToken" />
                    <input type="hidden" name="_method" value="DELETE" />
                    <input type="hidden" name="auth_token" :value="authToken" />
                    <Button type="submit" variant="outline" class="w-full">Cancel</Button>
                </form>

                <form :action="approveUrl" method="post">
                    <input type="hidden" name="_token" :value="csrfToken" />
                    <input type="hidden" name="auth_token" :value="authToken" />
                    <Button type="submit" class="w-full">Allow access</Button>
                </form>
            </div>
        </div>
    </AuthLayout>
</template>
