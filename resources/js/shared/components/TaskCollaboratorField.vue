<script setup lang="ts">
import { Input } from '@/components/ui/input';
import {
    collaboratorKey,
    emptyTaskCollaborators,
    normalizeTaskCollaborators,
    type CollaboratorOption,
    type TaskCollaboratorSelection,
} from '@shared/tasks/collaborators';
import axios from 'axios';
import { Check, LoaderCircle, Search, UserPlus, X } from 'lucide-vue-next';
import { ComboboxAnchor, ComboboxContent, ComboboxGroup, ComboboxInput, ComboboxItem, ComboboxPortal, ComboboxRoot } from 'reka-ui';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue?: TaskCollaboratorSelection | null;
        projectId?: number | null;
        environment?: string | null;
        readOnly?: boolean;
        disabled?: boolean;
        lookupUrl?: string | null;
        internalLabel?: string;
        internalBadgeLabel?: string | null;
        internalDescription?: string;
        externalLabel?: string;
        externalBadgeLabel?: string | null;
        externalDescription?: string;
        searchPlaceholder?: string;
    }>(),
    {
        modelValue: () => emptyTaskCollaborators(),
        projectId: null,
        environment: null,
        readOnly: false,
        disabled: false,
        lookupUrl: null,
        internalLabel: 'SHIFT team',
        internalBadgeLabel: null,
        internalDescription: 'Registered SHIFT users on this project.',
        externalLabel: 'Project users',
        externalBadgeLabel: 'Guest',
        externalDescription: 'Users available in the selected environment.',
        searchPlaceholder: 'Search collaborators',
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: TaskCollaboratorSelection];
}>();

const search = ref('');
const open = ref(false);
const loading = ref(false);
const internalOptions = ref<CollaboratorOption[]>([]);
const externalOptions = ref<CollaboratorOption[]>([]);
const internalAvailable = ref(true);
const externalAvailable = ref(true);
const internalError = ref<string | null>(null);
const externalError = ref<string | null>(null);
const responseInternalLabel = ref<string | null>(null);
const responseExternalLabel = ref<string | null>(null);
let searchTimer: ReturnType<typeof setTimeout> | null = null;
let lookupSequence = 0;
let lookupAbort: AbortController | null = null;

const selection = computed(() => normalizeTaskCollaborators(props.modelValue));
const hasSelection = computed(() => selection.value.internal.length > 0 || selection.value.external.length > 0);

type CollaboratorKind = 'internal' | 'external';

type CollaboratorSuggestion = CollaboratorOption & { kind: CollaboratorKind; rank: number };

type CollaboratorBadgeStyle = {
    shell: string;
    label: string;
    value: string;
    remove: string;
};

const collaboratorBadgeStyles: Record<CollaboratorKind, CollaboratorBadgeStyle> = {
    internal: {
        shell: 'inline-flex items-stretch overflow-hidden rounded-md border border-sky-300/70 shadow-xs dark:border-sky-500/30',
        label: 'bg-sky-100 px-2.5 py-1.5 text-[11px] font-semibold tracking-wide text-sky-900 dark:bg-sky-500/12 dark:text-sky-100',
        value: 'bg-sky-200 px-3 py-1.5 text-sm font-medium text-sky-950 dark:bg-sky-500/24 dark:text-sky-50',
        remove: 'border-l border-sky-300/70 bg-sky-200 px-2 text-sky-900 transition hover:bg-sky-300 dark:border-sky-500/30 dark:bg-sky-500/24 dark:text-sky-50 dark:hover:bg-sky-500/32',
    },
    external: {
        shell: 'inline-flex items-stretch overflow-hidden rounded-md border border-emerald-300/70 shadow-xs dark:border-emerald-500/30',
        label: 'bg-emerald-100 px-2.5 py-1.5 text-[11px] font-semibold tracking-wide text-emerald-900 dark:bg-emerald-500/12 dark:text-emerald-100',
        value: 'bg-emerald-200 px-3 py-1.5 text-sm font-medium text-emerald-950 dark:bg-emerald-500/24 dark:text-emerald-50',
        remove: 'border-l border-emerald-300/70 bg-emerald-200 px-2 text-emerald-900 transition hover:bg-emerald-300 dark:border-emerald-500/30 dark:bg-emerald-500/24 dark:text-emerald-50 dark:hover:bg-emerald-500/32',
    },
};

const resolvedInternalLabel = computed(() => responseInternalLabel.value ?? props.internalLabel);
const resolvedExternalLabel = computed(() => responseExternalLabel.value ?? props.externalLabel);
const suggestions = computed<CollaboratorSuggestion[]>(() => {
    const term = search.value.trim().toLocaleLowerCase();
    const score = (option: CollaboratorOption) => {
        const name = option.name.toLocaleLowerCase();
        const email = option.email?.toLocaleLowerCase() ?? '';
        if (name === term || email === term) return 0;
        if (name.startsWith(term) || email.startsWith(term)) return 1;
        if (name.split(/\s+/).some((word) => word.startsWith(term))) return 2;
        return 3;
    };

    return [
        ...internalOptions.value.map((option, rank) => ({ ...option, kind: 'internal' as const, rank })),
        ...externalOptions.value.map((option, rank) => ({ ...option, kind: 'external' as const, rank })),
    ]
        .sort((left, right) => score(left) - score(right) || left.rank - right.rank || left.name.localeCompare(right.name))
        .slice(0, 10);
});

const lookupErrors = computed(() => {
    const messages = [
        internalError.value ?? (!internalAvailable.value ? `${resolvedInternalLabel.value} collaborators are unavailable.` : null),
        externalError.value ?? (!externalAvailable.value ? `${resolvedExternalLabel.value} collaborators are unavailable.` : null),
    ];

    return [...new Set(messages.filter((message): message is string => Boolean(message)))];
});

function updateOpen(next: boolean) {
    open.value = next && search.value.trim().length > 0 && !props.disabled;
}

function chooseSuggestion(suggestion: CollaboratorSuggestion) {
    toggleCollaborator(suggestion.kind, suggestion);
    search.value = '';
    open.value = false;
}

function normalizeBadgeLabel(value?: string | null): string | null {
    if (typeof value !== 'string') {
        return null;
    }

    const normalized = value.trim();
    return normalized.length > 0 ? normalized : null;
}

function collaboratorBadgeLabel(kind: CollaboratorKind): string | null {
    return normalizeBadgeLabel(kind === 'internal' ? props.internalBadgeLabel : props.externalBadgeLabel);
}

function collaboratorDisplayValue(collaborator: Pick<CollaboratorOption, 'name' | 'email'>): string {
    const name = collaborator.name?.trim();
    const email = collaborator.email?.trim();

    return name || email || 'Unknown collaborator';
}

function normalizeLookupText(value: unknown): string | null {
    if (typeof value !== 'string') {
        return null;
    }

    const normalized = value.trim();

    return normalized.length > 0 ? normalized : null;
}

function resetLookupMetadata() {
    responseInternalLabel.value = null;
    responseExternalLabel.value = null;
}

function selectedBadgeStyle(kind: CollaboratorKind): CollaboratorBadgeStyle {
    return collaboratorBadgeStyles[kind];
}

function emitSelection(next: TaskCollaboratorSelection) {
    emit('update:modelValue', normalizeTaskCollaborators(next));
}

function isSelected(kind: 'internal' | 'external', collaborator: CollaboratorOption): boolean {
    return normalizeTaskCollaborators(props.modelValue)[kind].some((selected) => collaboratorKey(selected.id) === collaboratorKey(collaborator.id));
}

function toggleCollaborator(kind: 'internal' | 'external', collaborator: CollaboratorOption) {
    if (props.readOnly || props.disabled) return;

    const next = normalizeTaskCollaborators(props.modelValue);
    const existingIndex = next[kind].findIndex((selected) => collaboratorKey(selected.id) === collaboratorKey(collaborator.id));

    if (existingIndex >= 0) {
        next[kind].splice(existingIndex, 1);
    } else {
        next[kind].push({
            id: collaborator.id,
            name: collaborator.name,
            email: collaborator.email,
        });
    }

    emitSelection(next);
}

function resetLookup() {
    lookupSequence += 1;
    lookupAbort?.abort();
    lookupAbort = null;
    if (searchTimer !== null) clearTimeout(searchTimer);
    searchTimer = null;
    loading.value = false;
    internalOptions.value = [];
    externalOptions.value = [];
    internalError.value = null;
    externalError.value = null;
    resetLookupMetadata();
}

async function fetchCollaborators() {
    const term = search.value.trim();
    if (props.readOnly || props.disabled || !props.lookupUrl || !term) return;

    const sequence = ++lookupSequence;
    lookupAbort?.abort();
    lookupAbort = new AbortController();
    loading.value = true;

    try {
        const response = await axios.get(props.lookupUrl, {
            params: {
                search: term,
                ...(props.environment ? { environment: props.environment } : {}),
            },
            signal: lookupAbort.signal,
        });
        if (sequence !== lookupSequence) return;

        internalOptions.value = Array.isArray(response.data?.internal) ? response.data.internal : [];
        externalOptions.value = Array.isArray(response.data?.external) ? response.data.external : [];
        internalAvailable.value = response.data?.internal_available !== false;
        externalAvailable.value = response.data?.external_available !== false;
        internalError.value = typeof response.data?.internal_error === 'string' ? response.data.internal_error : null;
        externalError.value = typeof response.data?.external_error === 'string' ? response.data.external_error : null;
        responseInternalLabel.value = normalizeLookupText(response.data?.internal_label);
        responseExternalLabel.value = normalizeLookupText(response.data?.external_label);
    } catch (error: unknown) {
        if (sequence !== lookupSequence || axios.isCancel(error)) return;
        const message = axios.isAxiosError(error) ? error.response?.data?.message || error.message : 'Failed to load collaborators.';
        internalOptions.value = [];
        externalOptions.value = [];
        internalError.value = message;
        externalError.value = null;
    } finally {
        if (sequence === lookupSequence) loading.value = false;
    }
}

watch(
    () => [props.projectId, props.environment, props.lookupUrl] as const,
    ([nextProjectId, nextEnvironment, nextLookupUrl], previousValue) => {
        const [previousProjectId, previousEnvironment, previousLookupUrl] = previousValue ?? [];

        if (!props.readOnly && previousProjectId !== undefined && previousProjectId !== null && previousProjectId !== nextProjectId) {
            emitSelection(emptyTaskCollaborators());
        } else if (
            !props.readOnly &&
            previousProjectId === nextProjectId &&
            previousLookupUrl === nextLookupUrl &&
            previousEnvironment !== undefined &&
            previousEnvironment !== nextEnvironment
        ) {
            emitSelection({
                internal: [...selection.value.internal],
                external: [],
            });
        }

        resetLookup();
        search.value = '';
        open.value = false;
    },
    { immediate: true },
);

watch(search, () => {
    resetLookup();
    updateOpen(true);
    if (!open.value || props.readOnly || !props.lookupUrl) return;

    loading.value = true;
    searchTimer = setTimeout(() => {
        searchTimer = null;
        void fetchCollaborators();
    }, 250);
});

watch(
    () => [props.readOnly, props.disabled],
    () => {
        resetLookup();
        open.value = false;
    },
);

onBeforeUnmount(resetLookup);
</script>

<template>
    <div class="flex flex-col gap-4" data-testid="task-collaborators">
        <div class="flex flex-col gap-1">
            <div class="text-muted-foreground flex items-center gap-2 text-sm leading-none font-medium select-none">Collaborators</div>
            <p class="text-muted-foreground text-xs">Tag the people who should be able to access this task.</p>
        </div>

        <div v-if="hasSelection" class="flex flex-col gap-3">
            <div class="flex flex-wrap gap-2">
                <div
                    v-for="collaborator in selection.internal"
                    :key="`internal-${collaboratorKey(collaborator.id)}`"
                    :class="selectedBadgeStyle('internal').shell"
                    data-collaborator-badge-kind="internal"
                >
                    <span
                        v-if="collaboratorBadgeLabel('internal')"
                        :class="selectedBadgeStyle('internal').label"
                        data-collaborator-badge-label-kind="internal"
                    >
                        {{ collaboratorBadgeLabel('internal') }}
                    </span>
                    <span :class="selectedBadgeStyle('internal').value" data-collaborator-badge-value-kind="internal">
                        {{ collaboratorDisplayValue(collaborator) }}
                    </span>
                    <button
                        v-if="!readOnly"
                        type="button"
                        :class="selectedBadgeStyle('internal').remove"
                        :aria-label="`Remove ${collaboratorDisplayValue(collaborator)}`"
                        :disabled="disabled"
                        @click="toggleCollaborator('internal', collaborator)"
                    >
                        <X class="h-3 w-3" />
                    </button>
                </div>

                <div
                    v-for="collaborator in selection.external"
                    :key="`external-${collaboratorKey(collaborator.id)}`"
                    :class="selectedBadgeStyle('external').shell"
                    data-collaborator-badge-kind="external"
                >
                    <span
                        v-if="collaboratorBadgeLabel('external')"
                        :class="selectedBadgeStyle('external').label"
                        data-collaborator-badge-label-kind="external"
                    >
                        {{ collaboratorBadgeLabel('external') }}
                    </span>
                    <span :class="selectedBadgeStyle('external').value" data-collaborator-badge-value-kind="external">
                        {{ collaboratorDisplayValue(collaborator) }}
                    </span>
                    <button
                        v-if="!readOnly"
                        type="button"
                        :class="selectedBadgeStyle('external').remove"
                        :aria-label="`Remove ${collaboratorDisplayValue(collaborator)}`"
                        :disabled="disabled"
                        @click="toggleCollaborator('external', collaborator)"
                    >
                        <X class="h-3 w-3" />
                    </button>
                </div>
            </div>
        </div>

        <div v-else-if="readOnly" class="border-muted-foreground/30 bg-muted/10 text-muted-foreground rounded-md border border-dashed p-3 text-sm">
            No collaborators tagged.
        </div>

        <div v-if="!readOnly" class="flex flex-col gap-2">
            <div v-if="!lookupUrl" class="text-muted-foreground rounded-md border border-dashed p-3 text-sm">
                Select a project before tagging collaborators.
            </div>

            <ComboboxRoot
                v-else
                :open="open"
                :disabled="disabled"
                :ignore-filter="true"
                :reset-search-term-on-blur="false"
                :reset-search-term-on-select="false"
                @update:open="updateOpen"
            >
                <ComboboxAnchor class="relative">
                    <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                    <ComboboxInput v-model="search" as-child>
                        <Input
                            v-model="search"
                            :disabled="disabled"
                            class="pr-9 pl-9"
                            data-testid="task-collaborators-search"
                            :placeholder="searchPlaceholder"
                            aria-label="Search collaborators"
                        />
                    </ComboboxInput>
                    <LoaderCircle v-if="loading" class="text-muted-foreground absolute top-1/2 right-3 size-4 -translate-y-1/2 animate-spin" />
                </ComboboxAnchor>

                <ComboboxPortal>
                    <ComboboxContent
                        position="popper"
                        update-position-strategy="always"
                        align="start"
                        :side-offset="4"
                        class="bg-popover text-popover-foreground z-50 w-(--reka-combobox-trigger-width) max-w-[calc(100vw-2rem)] rounded-md border shadow-md outline-none"
                        data-testid="task-collaborators-dropdown"
                    >
                        <div class="max-h-72 overflow-y-auto p-1">
                            <div v-if="loading" role="status" class="text-muted-foreground px-3 py-4 text-sm">Searching collaborators…</div>
                            <ComboboxGroup v-else-if="suggestions.length > 0" aria-label="Matching collaborators">
                                <ComboboxItem
                                    v-for="suggestion in suggestions"
                                    :key="`${suggestion.kind}-${collaboratorKey(suggestion.id)}`"
                                    :value="`${suggestion.kind}-${collaboratorKey(suggestion.id)}`"
                                    :text-value="suggestion.name"
                                    :data-testid="`${suggestion.kind}-collaborator-option-${collaboratorKey(suggestion.id)}`"
                                    class="data-highlighted:bg-accent data-highlighted:text-accent-foreground flex cursor-pointer items-center gap-3 rounded-sm px-3 py-2 text-sm outline-none"
                                    @select.prevent="chooseSuggestion(suggestion)"
                                >
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-medium">{{ collaboratorDisplayValue(suggestion) }}</span>
                                        <span v-if="suggestion.email" class="text-muted-foreground block truncate text-xs">{{
                                            suggestion.email
                                        }}</span>
                                    </span>
                                    <span class="text-muted-foreground max-w-[35%] truncate text-xs" data-collaborator-source>
                                        {{ suggestion.kind === 'internal' ? resolvedInternalLabel : resolvedExternalLabel }}
                                    </span>
                                    <Check
                                        v-if="isSelected(suggestion.kind, suggestion)"
                                        class="text-primary size-4 shrink-0"
                                        :data-testid="`${suggestion.kind}-collaborator-selected-${collaboratorKey(suggestion.id)}`"
                                    />
                                    <UserPlus v-else class="text-muted-foreground size-4 shrink-0" />
                                </ComboboxItem>
                            </ComboboxGroup>
                            <div v-else-if="lookupErrors.length === 0" role="status" class="text-muted-foreground px-3 py-4 text-sm">
                                No matching collaborators.
                            </div>
                            <div v-for="message in lookupErrors" :key="message" role="status" class="text-muted-foreground px-3 py-2 text-xs">
                                {{ message }}
                            </div>
                        </div>
                    </ComboboxContent>
                </ComboboxPortal>
            </ComboboxRoot>
        </div>
    </div>
</template>
