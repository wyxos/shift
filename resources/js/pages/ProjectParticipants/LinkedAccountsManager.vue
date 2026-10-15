<script lang="ts" setup>
import { Button } from '@/components/ui/button';
import { Select, type SelectOption } from '@/components/ui/select';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

type LinkedAccount = {
    id: number | string;
    label?: string | null;
    name?: string | null;
    email?: string | null;
    provider?: string | null;
    environment?: string | null;
    url?: string | null;
    unlink_url?: string | null;
    unlinkUrl?: string | null;
    can_unlink?: boolean | null;
    canUnlink?: boolean | null;
    links?: { unlink?: string | null };
};
type ExternalIdentityRow = {
    id: number;
    name: string;
    email?: string | null;
    environment?: string | null;
    role?: string | null;
    role_label?: string | null;
    can_manage_role?: boolean | null;
    canManageRole?: boolean | null;
    linked_accounts?: LinkedAccount[];
    linkedAccounts?: LinkedAccount[];
    linkable_accounts?: LinkedAccount[];
    linkableAccounts?: LinkedAccount[];
    links?: { link_accounts?: string | null; linkAccounts?: string | null };
    project?: { id: number; name: string } | null;
};

const props = defineProps<{
    externalIdentity: ExternalIdentityRow;
    canManageLinkedAccounts: boolean;
}>();
const emit = defineEmits<{
    changed: [externalIdentity: ExternalIdentityRow];
}>();

const jsonHeaders = { headers: { Accept: 'application/json' } };
const savingId = ref<number | string | null>(null);
const linking = ref(false);
const selectedAccountId = ref('');
const errorMessage = ref<string | null>(null);
const linkableAccountOptions = computed<SelectOption[]>(() =>
    linkableAccountsFor(props.externalIdentity).map((account) => ({
        value: String(account.id),
        label: linkedAccountOptionLabel(account),
    })),
);

watch(
    () => props.externalIdentity.id,
    () => {
        selectedAccountId.value = '';
        errorMessage.value = null;
    },
);

function linkedAccountsFor(externalIdentity: ExternalIdentityRow) {
    return externalIdentity.linked_accounts ?? externalIdentity.linkedAccounts ?? [];
}

function linkableAccountsFor(externalIdentity: ExternalIdentityRow) {
    return (externalIdentity.linkable_accounts ?? externalIdentity.linkableAccounts ?? []).filter(
        (account) => String(account.id) !== String(externalIdentity.id),
    );
}

function linkedAccountLabel(account: LinkedAccount) {
    return account.label?.trim() || account.name?.trim() || account.email?.trim() || `Account ${account.id}`;
}

function linkedAccountMeta(account: LinkedAccount) {
    const label = linkedAccountLabel(account);

    return [account.email, account.provider, account.environment, linkedAccountHost(account)]
        .filter((value): value is string => Boolean(value?.trim()) && value?.trim() !== label)
        .join(' / ');
}

function linkedAccountHost(account: LinkedAccount) {
    const url = account.url?.trim();
    if (!url) return null;

    try {
        return new URL(url).host;
    } catch {
        return url;
    }
}

function linkedAccountOptionLabel(account: LinkedAccount) {
    const meta = linkedAccountMeta(account);

    return meta ? `${linkedAccountLabel(account)} (${meta})` : linkedAccountLabel(account);
}

function linkedAccountStoreUrl(externalIdentity: ExternalIdentityRow) {
    return (
        externalIdentity.links?.link_accounts ??
        externalIdentity.links?.linkAccounts ??
        `/project-participants/${externalIdentity.id}/linked-accounts`
    );
}

function linkedAccountUnlinkUrl(account: LinkedAccount) {
    return account.unlink_url ?? account.unlinkUrl ?? account.links?.unlink ?? null;
}

function canUnlinkLinkedAccount(account: LinkedAccount) {
    return props.canManageLinkedAccounts && account.can_unlink !== false && account.canUnlink !== false && Boolean(linkedAccountUnlinkUrl(account));
}

function requestErrorMessage(error: unknown, fallback: string) {
    const message = (error as { response?: { data?: { message?: string } } }).response?.data?.message?.trim();

    return message || fallback;
}

async function linkSelectedAccount() {
    const storeUrl = linkedAccountStoreUrl(props.externalIdentity);
    if (!storeUrl || !selectedAccountId.value || linking.value) return;

    linking.value = true;
    errorMessage.value = null;
    try {
        const response = await axios.post<{ external_identity: ExternalIdentityRow }>(
            storeUrl,
            {
                linked_external_identity_id: selectedAccountId.value,
            },
            jsonHeaders,
        );
        selectedAccountId.value = '';
        emit('changed', response.data.external_identity);
        toast.success('Account linked');
    } catch (error) {
        console.error('Error linking application account:', error);
        errorMessage.value = requestErrorMessage(error, 'Unable to link this account right now.');
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
        const response = await axios.delete<{ external_identity: ExternalIdentityRow }>(unlinkUrl, jsonHeaders);
        emit('changed', response.data.external_identity);
        toast.success('Account unlinked');
    } catch (error) {
        console.error('Error unlinking application account:', error);
        errorMessage.value = requestErrorMessage(error, 'Unable to unlink this account right now.');
    } finally {
        savingId.value = null;
    }
}
</script>

<template>
    <div class="space-y-3" data-testid="external-identity-linked-accounts">
        <div>
            <h2 class="text-sm font-medium">Linked accounts</h2>
            <p class="text-muted-foreground text-sm">
                Linking joins both accounts’ existing groups and shares their tickets. Unlinking removes shared access; original ticket ownership
                stays with its account.
            </p>
        </div>

        <div v-if="canManageLinkedAccounts && linkableAccountOptions.length" class="flex flex-col gap-2 sm:flex-row">
            <Select
                v-model="selectedAccountId"
                :disabled="linking"
                empty-label="No accounts found."
                :options="linkableAccountOptions"
                placeholder="Select account"
                test-id="external-identity-link-account-select"
            />
            <Button
                type="button"
                variant="outline"
                :disabled="linking || !selectedAccountId"
                data-testid="external-identity-link-account-submit"
                @click="linkSelectedAccount"
            >
                {{ linking ? 'Linking...' : 'Link' }}
            </Button>
        </div>

        <div v-if="linkedAccountsFor(externalIdentity).length" class="flex flex-col gap-2">
            <div
                v-for="account in linkedAccountsFor(externalIdentity)"
                :key="String(account.id)"
                class="flex items-start justify-between gap-3 rounded-lg border p-3 text-sm"
                data-list-row
                :data-testid="`external-identity-linked-account-${String(account.id)}`"
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
                    data-row-action
                    :disabled="savingId !== null"
                    :data-testid="`external-identity-linked-account-unlink-${String(account.id)}`"
                    @click="unlinkLinkedAccount(account)"
                >
                    {{ savingId === account.id ? 'Unlinking...' : 'Unlink' }}
                </Button>
            </div>
        </div>
        <p v-else class="text-muted-foreground rounded-lg border p-3 text-sm">No linked accounts yet.</p>
        <p v-if="errorMessage" class="text-destructive text-sm" role="alert">{{ errorMessage }}</p>
    </div>
</template>
