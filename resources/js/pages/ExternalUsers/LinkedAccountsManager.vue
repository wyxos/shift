<script lang="ts" setup>
import { Button } from '@/components/ui/button';
import { Select, type SelectOption } from '@/components/ui/select';
import axios from 'axios';
import { computed, ref, watch } from 'vue';

type LinkedAccount = {
    id: number | string;
    label?: string | null;
    name?: string | null;
    email?: string | null;
    provider?: string | null;
    environment?: string | null;
    unlink_url?: string | null;
    unlinkUrl?: string | null;
    can_unlink?: boolean | null;
    canUnlink?: boolean | null;
    links?: { unlink?: string | null };
};
type ExternalUserRow = {
    id: number;
    linked_accounts?: LinkedAccount[];
    linkedAccounts?: LinkedAccount[];
    linkable_accounts?: LinkedAccount[];
    linkableAccounts?: LinkedAccount[];
    links?: { link_accounts?: string | null; linkAccounts?: string | null };
};

const props = defineProps<{
    externalUser: ExternalUserRow;
    canManageLinkedAccounts: boolean;
}>();
const emit = defineEmits<{
    changed: [];
}>();

const jsonHeaders = { headers: { Accept: 'application/json' } };
const savingId = ref<number | string | null>(null);
const linking = ref(false);
const selectedAccountId = ref('');
const errorMessage = ref<string | null>(null);
const linkableAccountOptions = computed<SelectOption[]>(() =>
    linkableAccountsFor(props.externalUser).map((account) => ({
        value: String(account.id),
        label: linkedAccountOptionLabel(account),
    })),
);

watch(
    () => props.externalUser.id,
    () => {
        selectedAccountId.value = '';
        errorMessage.value = null;
    },
);

function linkedAccountsFor(externalUser: ExternalUserRow) {
    return externalUser.linked_accounts ?? externalUser.linkedAccounts ?? [];
}

function linkableAccountsFor(externalUser: ExternalUserRow) {
    return externalUser.linkable_accounts ?? externalUser.linkableAccounts ?? [];
}

function linkedAccountLabel(account: LinkedAccount) {
    return account.label?.trim() || account.name?.trim() || account.email?.trim() || `Account ${account.id}`;
}

function linkedAccountMeta(account: LinkedAccount) {
    return [account.email, account.provider, account.environment].filter((value): value is string => Boolean(value?.trim())).join(' / ');
}

function linkedAccountOptionLabel(account: LinkedAccount) {
    const meta = linkedAccountMeta(account);

    return meta ? `${linkedAccountLabel(account)} (${meta})` : linkedAccountLabel(account);
}

function linkedAccountStoreUrl(externalUser: ExternalUserRow) {
    return externalUser.links?.link_accounts ?? externalUser.links?.linkAccounts ?? `/external-users/${externalUser.id}/linked-accounts`;
}

function linkedAccountUnlinkUrl(account: LinkedAccount) {
    return account.unlink_url ?? account.unlinkUrl ?? account.links?.unlink ?? null;
}

function canUnlinkLinkedAccount(account: LinkedAccount) {
    return props.canManageLinkedAccounts && account.can_unlink !== false && account.canUnlink !== false && Boolean(linkedAccountUnlinkUrl(account));
}

async function linkSelectedAccount() {
    const storeUrl = linkedAccountStoreUrl(props.externalUser);
    if (!storeUrl || !selectedAccountId.value || linking.value) return;

    linking.value = true;
    errorMessage.value = null;
    try {
        await axios.post(
            storeUrl,
            {
                linked_external_user_id: selectedAccountId.value,
            },
            jsonHeaders,
        );
        selectedAccountId.value = '';
        emit('changed');
    } catch (error) {
        console.error('Error linking external user account:', error);
        errorMessage.value = 'Unable to link this account right now.';
    } finally {
        linking.value = false;
    }
}

async function unlinkLinkedAccount(account: LinkedAccount) {
    const unlinkUrl = linkedAccountUnlinkUrl(account);
    if (!unlinkUrl || savingId.value !== null) return;

    savingId.value = account.id;
    errorMessage.value = null;
    try {
        await axios.delete(unlinkUrl, jsonHeaders);
        emit('changed');
    } catch (error) {
        console.error('Error unlinking external user account:', error);
        errorMessage.value = 'Unable to unlink this account right now.';
    } finally {
        savingId.value = null;
    }
}
</script>

<template>
    <div class="space-y-3" data-testid="external-user-linked-accounts">
        <div>
            <h2 class="text-sm font-medium">Linked accounts</h2>
            <p class="text-muted-foreground text-sm">Manage app accounts connected to this external user.</p>
        </div>

        <div v-if="canManageLinkedAccounts && linkableAccountOptions.length" class="flex flex-col gap-2 sm:flex-row">
            <Select
                v-model="selectedAccountId"
                :disabled="linking"
                empty-label="No accounts found."
                :options="linkableAccountOptions"
                placeholder="Select account"
                test-id="external-user-link-account-select"
            />
            <Button
                type="button"
                variant="outline"
                :disabled="linking || !selectedAccountId"
                data-testid="external-user-link-account-submit"
                @click="linkSelectedAccount"
            >
                {{ linking ? 'Linking...' : 'Link' }}
            </Button>
        </div>

        <div v-if="linkedAccountsFor(externalUser).length" class="flex flex-col gap-2">
            <div
                v-for="account in linkedAccountsFor(externalUser)"
                :key="String(account.id)"
                class="flex items-start justify-between gap-3 rounded-lg border p-3 text-sm"
                :data-testid="`external-user-linked-account-${String(account.id)}`"
            >
                <div class="min-w-0">
                    <div class="truncate font-medium">{{ linkedAccountLabel(account) }}</div>
                    <div v-if="linkedAccountMeta(account)" class="text-muted-foreground truncate text-xs">
                        {{ linkedAccountMeta(account) }}
                    </div>
                </div>
                <Button
                    v-if="canUnlinkLinkedAccount(account)"
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="savingId !== null"
                    :data-testid="`external-user-linked-account-unlink-${String(account.id)}`"
                    @click="unlinkLinkedAccount(account)"
                >
                    {{ savingId === account.id ? 'Unlinking...' : 'Unlink' }}
                </Button>
            </div>
        </div>
        <p v-else class="text-muted-foreground rounded-lg border p-3 text-sm">No linked accounts yet.</p>
        <p v-if="errorMessage" class="text-destructive text-sm">{{ errorMessage }}</p>
    </div>
</template>
