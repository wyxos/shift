<script setup lang="ts">
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import RequestButton from '@/shared/components/RequestButton.vue';
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
const interacted = ref(false);
const activeIndex = ref(-1);
const comboboxRoot = ref<HTMLElement | null>(null);

const inputId = computed(() => `${props.testIdPrefix}-email`);
const listboxId = computed(() => `${props.testIdPrefix}-listbox`);

const normalizedQuery = computed(() => query.value.trim().toLowerCase());
const filteredCandidates = computed(() => {
    const needle = normalizedQuery.value;

    if (!needle) {
        return props.candidates.slice(0, 6);
    }

    return props.candidates.filter((candidate) => `${candidate.name} ${candidate.email}`.toLowerCase().includes(needle)).slice(0, 6);
});
const showCandidates = computed(() => open.value && (interacted.value || Boolean(normalizedQuery.value)) && filteredCandidates.value.length > 0);
const errorEntries = computed(() => Object.entries(props.errors ?? {}).filter(([, message]) => Boolean(message)));
const hasErrors = computed(() => errorEntries.value.length > 0);
const errorDescriptionIds = computed(() =>
    errorEntries.value.map(([key]) => `${props.testIdPrefix}-error-${key.replace(/[^a-z0-9_-]/gi, '-')}`).join(' '),
);
const activeCandidate = computed(() => filteredCandidates.value[activeIndex.value] ?? null);
const activeOptionId = computed(() => (showCandidates.value && activeCandidate.value ? candidateOptionId(activeCandidate.value) : undefined));

watch(
    () => props.email,
    (email) => {
        if (!email) {
            query.value = '';
            closeSuggestions();

            return;
        }

        if (!open.value && email !== query.value) {
            query.value = email;
        }
    },
);

function updateQuery(value: string | number) {
    const queryValue = String(value);

    query.value = queryValue;
    open.value = true;
    interacted.value = true;
    activeIndex.value = filteredCandidates.value.length > 0 ? 0 : -1;
    emit('update:email', queryValue.trim());

    if (!props.name.trim() || props.name === deriveNameFromEmail(props.email)) {
        emit('update:name', deriveNameFromEmail(queryValue.trim()));
    }
}

function openSuggestions() {
    interacted.value = true;
    open.value = true;
    activeIndex.value = filteredCandidates.value.length > 0 ? 0 : -1;
}

function handleFocus() {
    open.value = Boolean(normalizedQuery.value);

    if (open.value && filteredCandidates.value.length > 0) {
        activeIndex.value = 0;
    }
}

function handleFocusOut(event: FocusEvent) {
    const nextTarget = event.relatedTarget;

    if (nextTarget instanceof Node && comboboxRoot.value?.contains(nextTarget)) {
        return;
    }

    closeSuggestions();
}

function closeSuggestions() {
    open.value = false;
    interacted.value = false;
    activeIndex.value = -1;
}

function moveActiveOption(direction: 1 | -1) {
    const candidates = filteredCandidates.value;
    const wasOpen = showCandidates.value;

    interacted.value = true;
    open.value = true;

    if (candidates.length === 0) {
        activeIndex.value = -1;
        return;
    }

    if (!wasOpen || activeIndex.value < 0) {
        activeIndex.value = direction === 1 ? 0 : candidates.length - 1;
        return;
    }

    activeIndex.value = (activeIndex.value + direction + candidates.length) % candidates.length;
}

function handleKeydown(event: KeyboardEvent) {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        moveActiveOption(1);
        return;
    }

    if (event.key === 'ArrowUp') {
        event.preventDefault();
        moveActiveOption(-1);
        return;
    }

    if (event.key === 'Enter' && showCandidates.value && activeCandidate.value) {
        event.preventDefault();
        selectCandidate(activeCandidate.value);
        return;
    }

    if (event.key === 'Escape' && open.value) {
        event.preventDefault();
        closeSuggestions();
    }
}

function candidateOptionId(candidate: AccessUserCandidate) {
    return `${props.testIdPrefix}-candidate-option-${candidate.id}`;
}

function selectCandidate(candidate: AccessUserCandidate) {
    query.value = `${candidate.name} (${candidate.email})`;
    closeSuggestions();
    emit('update:email', candidate.email);
    emit('update:name', candidate.name);
}

watch(filteredCandidates, (candidates) => {
    if (candidates.length === 0) {
        activeIndex.value = -1;
        return;
    }

    if (activeIndex.value >= candidates.length) {
        activeIndex.value = candidates.length - 1;
    }
});
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex flex-col gap-2">
            <Label :for="`${testIdPrefix}-email`" class="sr-only">Add user</Label>
            <div class="flex gap-2">
                <div ref="comboboxRoot" class="relative min-w-0 flex-1" @focusout="handleFocusOut">
                    <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2" />
                    <Input
                        :id="inputId"
                        :model-value="query"
                        :aria-activedescendant="activeOptionId"
                        aria-autocomplete="list"
                        :aria-controls="listboxId"
                        :aria-describedby="errorDescriptionIds || undefined"
                        :aria-expanded="showCandidates"
                        :aria-invalid="hasErrors"
                        autocomplete="off"
                        class="pl-9"
                        :data-testid="`${testIdPrefix}-email`"
                        :disabled="processing"
                        placeholder="Type an email or search users"
                        @click="openSuggestions"
                        @focus="handleFocus"
                        @keydown="handleKeydown"
                        @update:model-value="updateQuery"
                        role="combobox"
                    />
                    <div
                        v-if="showCandidates"
                        :id="listboxId"
                        :aria-label="`User suggestions for ${query || 'all users'}`"
                        class="bg-popover text-popover-foreground absolute z-50 mt-1 w-full overflow-hidden rounded-md border shadow-md"
                        role="listbox"
                    >
                        <button
                            v-for="(candidate, index) in filteredCandidates"
                            :key="candidate.id"
                            :id="candidateOptionId(candidate)"
                            :aria-selected="index === activeIndex"
                            class="hover:bg-accent hover:text-accent-foreground flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm"
                            :class="index === activeIndex ? 'bg-accent text-accent-foreground' : ''"
                            :data-testid="`${testIdPrefix}-candidate-${candidate.id}`"
                            role="option"
                            tabindex="-1"
                            type="button"
                            @click="selectCandidate(candidate)"
                            @mousedown.prevent
                        >
                            <span class="min-w-0">
                                <span class="block truncate font-medium">{{ candidate.name }}</span>
                                <span class="text-muted-foreground block truncate text-xs">{{ candidate.email }}</span>
                            </span>
                            <Check v-if="candidate.email === email" class="h-4 w-4" />
                        </button>
                    </div>
                </div>
                <RequestButton
                    type="button"
                    :disabled="disabled || processing"
                    :loading="processing"
                    loading-label="Adding..."
                    :data-testid="`${testIdPrefix}-submit`"
                    @click="emit('submit')"
                >
                    <UserPlus class="h-4 w-4" />
                    {{ submitLabel }}
                </RequestButton>
            </div>
        </div>

        <div v-if="hasErrors" class="space-y-1">
            <p
                v-for="[key, error] in errorEntries"
                :id="`${testIdPrefix}-error-${key.replace(/[^a-z0-9_-]/gi, '-')}`"
                :key="key"
                class="text-destructive text-sm"
                role="alert"
            >
                {{ error }}
            </p>
        </div>
    </div>
</template>
