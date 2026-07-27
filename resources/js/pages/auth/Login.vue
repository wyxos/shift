<script setup lang="ts">
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <AuthBase title="Log in to SHIFT" description="Access your tasks, projects, and notifications.">
        <Head title="Log in" />

        <div v-if="status" class="mb-4 text-center text-sm font-medium text-green-600">
            {{ status }}
        </div>

        <form @submit.prevent="submit" class="flex flex-col gap-6">
            <div class="grid gap-6">
                <FormField id="email" :error="form.errors.email" label="Email address">
                    <template #default="{ controlAttrs }">
                        <Input
                            v-bind="controlAttrs"
                            v-model="form.email"
                            type="email"
                            required
                            autofocus
                            :tabindex="1"
                            autocomplete="email"
                            placeholder="email@example.com"
                        />
                    </template>
                </FormField>

                <FormField id="password" :error="form.errors.password" label="Password">
                    <template #label-action>
                        <TextLink v-if="canResetPassword" :href="route('password.request')" class="text-sm" :tabindex="5">
                            Forgot password?
                        </TextLink>
                    </template>
                    <template #default="{ controlAttrs }">
                        <Input
                            v-bind="controlAttrs"
                            v-model="form.password"
                            type="password"
                            required
                            :tabindex="2"
                            autocomplete="current-password"
                            placeholder="Password"
                        />
                    </template>
                </FormField>

                <div class="flex items-center justify-between">
                    <Label for="remember" class="flex items-center space-x-3">
                        <Checkbox id="remember" v-model="form.remember" :tabindex="3" />
                        <span>Remember me</span>
                    </Label>
                </div>

                <Button type="submit" class="mt-4 w-full" :tabindex="4" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    Log in
                </Button>
            </div>
        </form>

        <div class="text-muted-foreground text-center text-sm">
            New to SHIFT?
            <TextLink :href="route('register')" class="underline underline-offset-4" :tabindex="6">Create account</TextLink>
        </div>
    </AuthBase>
</template>
