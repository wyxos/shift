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
        internalLabel: 'Team',
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
const activeGroup = ref<CollaboratorKind>('internal');
const loading = ref(false);
const internalOptions = ref<CollaboratorOption[]>([]);
const externalOptions = ref<CollaboratorOption[]>([]);
const internalAvailable = ref(true);
const externalAvailable = ref(true);
const internalError = ref<string | null>(null);
const externalError = ref<string | null>(null);
const responseInternalLabel = ref<string | null>(null);
const responseExternalLabel = ref<string | null>(null);
const responseInternalDescription = ref<string | null>(null);
const responseExternalDescription = ref<string | null>(null);
let searchTimer: number | null = null;

const selection = computed(() => normalizeTaskCollaborators(props.modelValue));
const hasSelection = computed(() => selection.value.internal.length > 0 || selection.value.external.length > 0);

type CollaboratorKind = 'internal' | 'external';

type CollaboratorGroup = {
    kind: CollaboratorKind;
    label: string;
    description: string;
    options: CollaboratorOption[];
    available: boolean;
    error: string | null;
};

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
const resolvedInternalDescription = computed(() => responseInternalDescription.value ?? props.internalDescription);
const resolvedExternalDescription = computed(() => responseExternalDescription.value ?? props.externalDescription);

const collaboratorGroups = computed<CollaboratorGroup[]>(() => [
    {
        kind: 'internal',
        label: resolvedInternalLabel.value,
        description: resolvedInternalDescription.value,
        options: internalOptions.value,
        available: internalAvailable.value,
        error: internalError.value,
    },
    {
        kind: 'external',
        label: resolvedExternalLabel.value,
        description: resolvedExternalDescription.value,
        options: externalOptions.value,
        available: externalAvailable.value,
        error: externalError.value,
    },
]);

const activeCollaboratorGroup = computed(
    () => collaboratorGroups.value.find((group) => group.kind === activeGroup.value) ?? collaboratorGroups.value[0],
);

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
    responseInternalDescription.value = null;
    responseExternalDescription.value = null;
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

function groupStatusMessage(group: CollaboratorGroup): string | null {
    if (group.error) {
        return group.error;
    }

    if (!group.available) {
        return `${group.label} collaborators are unavailable.`;
    }

    if (group.options.length === 0) {
        return `No ${group.label.toLowerCase()} collaborators found.`;
    }

    return null;
}

function resolveLookupUrl(): string | null {
    if (props.lookupUrl) {
        return props.lookupUrl;
    }

    if (props.projectId === null) {
        return null;
    }

    return route('tasks.collaborators', { project: props.projectId });
}

async function fetchCollaborators() {
    if (props.readOnly) {
        internalOptions.value = [];
        externalOptions.value = [];
        internalAvailable.value = true;
        externalAvailable.value = props.environment !== null || props.lookupUrl !== null;
        internalError.value = null;
        externalError.value = null;
        resetLookupMetadata();
        return;
    }

    const lookupUrl = resolveLookupUrl();
    if (!lookupUrl) {
        internalOptions.value = [];
        externalOptions.value = [];
        internalAvailable.value = false;
        externalAvailable.value = false;
        internalError.value = 'Select a project before tagging collaborators.';
        externalError.value = 'Select a project before tagging collaborators.';
        resetLookupMetadata();
        return;
    }

    loading.value = true;

    try {
        const response = await axios.get(lookupUrl, {
            params: {
                ...(search.value.trim() ? { search: search.value.trim() } : {}),
                ...(props.environment ? { environment: props.environment } : {}),
            },
        });

        internalOptions.value = Array.isArray(response.data?.internal) ? response.data.internal : [];
        externalOptions.value = Array.isArray(response.data?.external) ? response.data.external : [];
        internalAvailable.value = response.data?.internal_available !== false;
        externalAvailable.value = response.data?.external_available !== false;
        internalError.value = typeof response.data?.internal_error === 'string' ? response.data.internal_error : null;
        externalError.value = typeof response.data?.external_error === 'string' ? response.data.external_error : null;
        responseInternalLabel.value = normalizeLookupText(response.data?.internal_label);
        responseExternalLabel.value = normalizeLookupText(response.data?.external_label);
        responseInternalDescription.value = normalizeLookupText(response.data?.internal_description);
        responseExternalDescription.value = normalizeLookupText(response.data?.external_description);
    } catch (error: any) {
        const message = error.response?.data?.message || error.message || 'Failed to load collaborators.';
        internalOptions.value = [];
        externalOptions.value = [];
        internalAvailable.value = false;
        externalAvailable.value = false;
        internalError.value = message;
        externalError.value = message;
        resetLookupMetadata();
    } finally {
        loading.value = false;
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

        void fetchCollaborators();
    },
    { immediate: true },
);

watch(search, () => {
    if (props.readOnly) return;
    if (resolveLookupUrl() === null) return;

    if (searchTimer !== null) {
        window.clearTimeout(searchTimer);
    }

    searchTimer = window.setTimeout(() => {
        searchTimer = null;
        void fetchCollaborators();
    }, 250);
});

onBeforeUnmount(() => {
    if (searchTimer !== null) {
        window.clearTimeout(searchTimer);
    }
});
</script>

<template>
    <div class="space-y-4" data-testid="task-collaborators">
        <div class="space-y-1">
            <div class="text-muted-foreground flex items-center gap-2 text-sm leading-none font-medium select-none">Collaborators</div>
            <p class="text-muted-foreground text-xs">Tag the people who should be able to access this task.</p>
        </div>

        <div v-if="hasSelection" class="space-y-3">
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

        <div v-if="!readOnly" class="space-y-4">
            <div
                v-if="!lookupUrl && projectId === null"
                class="border-muted-foreground/30 bg-muted/10 text-muted-foreground rounded-md border border-dashed p-3 text-sm"
            >
                Select a project before tagging collaborators.
            </div>

            <template v-else>
                <div class="bg-background overflow-hidden rounded-md border" data-testid="task-collaborators-dropdown">
                    <div class="border-b p-2">
                        <div class="relative">
                            <Search class="text-muted-foreground absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2" />
                            <Input v-model="search" class="pr-9 pl-9" data-testid="task-collaborators-search" :placeholder="searchPlaceholder" />
                            <LoaderCircle
                                v-if="loading"
                                class="text-muted-foreground absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 animate-spin"
                            />
                        </div>
                    </div>

                    <div class="grid gap-1 border-b p-2 sm:grid-cols-2" data-testid="task-collaborators-group-filter">
                        <button
                            v-for="group in collaboratorGroups"
                            :key="group.kind"
                            type="button"
                            class="hover:bg-muted rounded-md border px-3 py-2 text-left transition"
                            :class="
                                activeGroup === group.kind
                                    ? 'border-primary/40 bg-muted text-foreground shadow-xs'
                                    : 'text-muted-foreground border-transparent'
                            "
                            :data-testid="`task-collaborators-group-${group.kind}`"
                            @click="activeGroup = group.kind"
                        >
                            <span class="flex items-start justify-between gap-3">
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium">{{ group.label }}</span>
                                    <span class="text-muted-foreground mt-0.5 block text-xs leading-snug">{{ group.description }}</span>
                                </span>
                                <span
                                    v-if="selection[group.kind].length > 0"
                                    class="bg-background text-muted-foreground shrink-0 rounded px-1.5 py-0.5 text-[11px]"
                                >
                                    {{ selection[group.kind].length }}
                                </span>
                            </span>
                        </button>
                    </div>

                    <div class="max-h-64 overflow-auto p-2">
                        <div
                            v-if="groupStatusMessage(activeCollaboratorGroup)"
                            class="text-muted-foreground rounded-md border border-dashed p-3 text-sm"
                        >
                            {{ groupStatusMessage(activeCollaboratorGroup) }}
                        </div>

                        <div v-else class="space-y-1">
                            <button
                                v-for="collaborator in activeCollaboratorGroup.options"
                                :key="`${activeCollaboratorGroup.kind}-option-${collaboratorKey(collaborator.id)}`"
                                type="button"
                                :class="
                                    isSelected(activeCollaboratorGroup.kind, collaborator)
                                        ? 'border-primary/40 bg-primary/10 hover:bg-primary/15'
                                        : 'hover:bg-muted/70 border-transparent'
                                "
                                class="flex w-full items-center justify-between rounded-md border px-3 py-2 text-left transition"
                                :data-testid="`${activeCollaboratorGroup.kind}-collaborator-option-${collaboratorKey(collaborator.id)}`"
                                @click="toggleCollaborator(activeCollaboratorGroup.kind, collaborator)"
                            >
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium">{{ collaborator.name }}</span>
                                    <span v-if="collaborator.email" class="text-muted-foreground block truncate text-xs">{{
                                        collaborator.email
                                    }}</span>
                                </span>
                                <Check
                                    v-if="isSelected(activeCollaboratorGroup.kind, collaborator)"
                                    class="text-primary h-4 w-4 shrink-0"
                                    :data-testid="`${activeCollaboratorGroup.kind}-collaborator-selected-${collaboratorKey(collaborator.id)}`"
                                    aria-hidden="true"
                                />
                                <UserPlus v-else class="text-muted-foreground h-4 w-4 shrink-0" />
                            </button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>
