<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Check, Search, UserPlus } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { type AccessUserCandidate, deriveNameFromEmail } from './access-users';

const props = withDefaults(
    defineProps<{
        candidates?: AccessUserCandidate[];
        disabled?: boolean;
        email: string;
        errors?: Record<string, string>;
        name: string;
        processing?: boolean;
        submitLabel?: string;
        testIdPrefix: string;
    }>(),
    {
        candidates: () => [],
        errors: () => ({}),
        submitLabel: 'Add',
    },
);

const emit = defineEmits<{
    'update:email': [value: string];
    'update:name': [value: string];
    submit: [];
}>();

const query = ref(props.email);
const open = ref(false);

const normalizedQuery = computed(() => query.value.trim().toLowerCase());
const filteredCandidates = computed(() => {
    const needle = normalizedQuery.value;

    if (!needle) {
        return props.candidates.slice(0, 6);
    }

    return props.candidates.filter((candidate) => `${candidate.name} ${candidate.email}`.toLowerCase().includes(needle)).slice(0, 6);
});
const hasErrors = computed(() => Object.keys(props.errors ?? {}).length > 0);

watch(
    () => props.email,
    (email) => {
        if (!open.value && email !== query.value) {
            query.value = email;
        }
    },
);

function updateQuery(value: string) {
    query.value = value;
    open.value = true;
    emit('update:email', value.trim());

    if (!props.name.trim() || props.name === deriveNameFromEmail(props.email)) {
        emit('update:name', deriveNameFromEmail(value.trim()));
    }
}

function selectCandidate(candidate: AccessUserCandidate) {
    query.value = `${candidate.name} (${candidate.email})`;
    open.value = false;
    emit('update:email', candidate.email);
    emit('update:name', candidate.name);
}
</script>

<template>
    <div class="bg-muted/20 space-y-3 rounded-lg border p-3">
        <div class="space-y-2">
            <Label :for="`${testIdPrefix}-email`" class="sr-only">Add user</Label>
            <div class="flex gap-2">
                <div class="relative min-w-0 flex-1">
                    <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2" />
                    <Input
                        :id="`${testIdPrefix}-email`"
                        :model-value="query"
                        autocomplete="off"
                        class="pl-9"
                        :data-testid="`${testIdPrefix}-email`"
                        placeholder="Type an email or search users"
                        @blur="open = false"
                        @focus="open = true"
                        @keydown.escape.prevent="open = false"
                        @update:model-value="updateQuery"
                    />
                    <div
                        v-if="open && filteredCandidates.length > 0"
                        class="bg-popover text-popover-foreground absolute z-50 mt-1 w-full overflow-hidden rounded-md border shadow-md"
                    >
                        <button
                            v-for="candidate in filteredCandidates"
                            :key="candidate.id"
                            class="hover:bg-accent hover:text-accent-foreground flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm"
                            :data-testid="`${testIdPrefix}-candidate-${candidate.id}`"
                            type="button"
                            @mousedown.prevent="selectCandidate(candidate)"
                        >
                            <span class="min-w-0">
                                <span class="block truncate font-medium">{{ candidate.name }}</span>
                                <span class="text-muted-foreground block truncate text-xs">{{ candidate.email }}</span>
                            </span>
                            <Check v-if="candidate.email === email" class="h-4 w-4" />
                        </button>
                    </div>
                </div>
                <Button type="button" :disabled="disabled || processing" :data-testid="`${testIdPrefix}-submit`" @click="emit('submit')">
                    <UserPlus class="h-4 w-4" />
                    {{ processing ? 'Adding...' : submitLabel }}
                </Button>
            </div>
        </div>

        <div v-if="hasErrors" class="space-y-1">
            <p v-for="(error, key) in errors" :key="key" class="text-destructive text-sm">{{ error }}</p>
        </div>
    </div>
</template>
