<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import {
    collaboratorKey,
    emptyTaskCollaborators,
    normalizeTaskCollaborators,
    type CollaboratorOption,
    type TaskCollaboratorSelection,
} from '@shared/tasks/collaborators';
import axios from 'axios';
import { LoaderCircle, Search, UserPlus, X } from 'lucide-vue-next';
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
        internalDescription?: string;
        externalLabel?: string;
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
        internalLabel: 'Internal',
        internalDescription: 'Registered SHIFT users on this project.',
        externalLabel: 'External',
        externalDescription: 'Users available in the selected environment.',
        searchPlaceholder: 'Search collaborators',
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: TaskCollaboratorSelection];
}>();

const search = ref('');
const loading = ref(false);
const internalOptions = ref<CollaboratorOption[]>([]);
const externalOptions = ref<CollaboratorOption[]>([]);
const internalAvailable = ref(true);
const externalAvailable = ref(true);
const internalError = ref<string | null>(null);
const externalError = ref<string | null>(null);
let searchTimer: number | null = null;

const selection = computed(() => normalizeTaskCollaborators(props.modelValue));
const hasSelection = computed(() => selection.value.internal.length > 0 || selection.value.external.length > 0);

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

function resolveLookupUrl(): string | null {
    if (props.lookupUrl) {
        return props.lookupUrl;
    }

    if (props.projectId === null) {
        return null;
    }

    return route('tasks.v2.collaborators', { project: props.projectId });
}

async function fetchCollaborators() {
    if (props.readOnly) {
        internalOptions.value = [];
        externalOptions.value = [];
        internalAvailable.value = true;
        externalAvailable.value = props.environment !== null || props.lookupUrl !== null;
        internalError.value = null;
        externalError.value = null;
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
    } catch (error: any) {
        const message = error.response?.data?.message || error.message || 'Failed to load collaborators.';
        internalOptions.value = [];
        externalOptions.value = [];
        internalAvailable.value = false;
        externalAvailable.value = false;
        internalError.value = message;
        externalError.value = message;
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
                <Badge
                    v-for="collaborator in selection.internal"
                    :key="`internal-${collaboratorKey(collaborator.id)}`"
                    class="flex items-center gap-2 bg-sky-100 text-sky-900 hover:bg-sky-100"
                    variant="secondary"
                >
                    <span class="font-medium">{{ internalLabel }}</span>
                    <span>{{ collaborator.name }}</span>
                    <span v-if="collaborator.email" class="text-xs opacity-75">{{ collaborator.email }}</span>
                    <button
                        v-if="!readOnly"
                        type="button"
                        class="inline-flex h-4 w-4 items-center justify-center rounded-full"
                        @click="toggleCollaborator('internal', collaborator)"
                    >
                        <X class="h-3 w-3" />
                    </button>
                </Badge>

                <Badge
                    v-for="collaborator in selection.external"
                    :key="`external-${collaboratorKey(collaborator.id)}`"
                    class="flex items-center gap-2 bg-emerald-100 text-emerald-900 hover:bg-emerald-100"
                    variant="secondary"
                >
                    <span class="font-medium">{{ externalLabel }}</span>
                    <span>{{ collaborator.name }}</span>
                    <span v-if="collaborator.email" class="text-xs opacity-75">{{ collaborator.email }}</span>
                    <button
                        v-if="!readOnly"
                        type="button"
                        class="inline-flex h-4 w-4 items-center justify-center rounded-full"
                        @click="toggleCollaborator('external', collaborator)"
                    >
                        <X class="h-3 w-3" />
                    </button>
                </Badge>
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
                <div class="relative">
                    <Search class="text-muted-foreground absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2" />
                    <Input v-model="search" class="pl-9" data-testid="task-collaborators-search" :placeholder="searchPlaceholder" />
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="space-y-3 rounded-xl border p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="text-sm font-medium">{{ internalLabel }}</div>
                                <div class="text-muted-foreground text-xs">{{ internalDescription }}</div>
                            </div>
                            <LoaderCircle v-if="loading" class="text-muted-foreground h-4 w-4 animate-spin" />
                        </div>

                        <div v-if="internalError" class="text-muted-foreground rounded-md border border-dashed p-3 text-sm">
                            {{ internalError }}
                        </div>

                        <div v-else-if="!internalAvailable" class="text-muted-foreground rounded-md border border-dashed p-3 text-sm">
                            {{ internalLabel }} collaborators are unavailable.
                        </div>

                        <div v-else-if="internalOptions.length === 0" class="text-muted-foreground rounded-md border border-dashed p-3 text-sm">
                            No {{ internalLabel.toLowerCase() }} collaborators found.
                        </div>

                        <div v-else class="max-h-60 space-y-2 overflow-auto pr-1">
                            <button
                                v-for="collaborator in internalOptions"
                                :key="`internal-option-${collaboratorKey(collaborator.id)}`"
                                type="button"
                                class="hover:bg-muted/70 flex w-full items-center justify-between rounded-lg border px-3 py-2 text-left transition"
                                :data-testid="`internal-collaborator-option-${collaboratorKey(collaborator.id)}`"
                                @click="toggleCollaborator('internal', collaborator)"
                            >
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium">{{ collaborator.name }}</span>
                                    <span v-if="collaborator.email" class="text-muted-foreground block truncate text-xs">{{ collaborator.email }}</span>
                                </span>
                                <UserPlus v-if="!isSelected('internal', collaborator)" class="text-muted-foreground h-4 w-4 shrink-0" />
                                <Badge v-else variant="secondary">Selected</Badge>
                            </button>
                        </div>
                    </div>

                    <div class="space-y-3 rounded-xl border p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="text-sm font-medium">{{ externalLabel }}</div>
                                <div class="text-muted-foreground text-xs">{{ externalDescription }}</div>
                            </div>
                            <LoaderCircle v-if="loading" class="text-muted-foreground h-4 w-4 animate-spin" />
                        </div>

                        <div v-if="externalError" class="text-muted-foreground rounded-md border border-dashed p-3 text-sm">
                            {{ externalError }}
                        </div>

                        <div v-else-if="!externalAvailable" class="text-muted-foreground rounded-md border border-dashed p-3 text-sm">
                            {{ externalLabel }} collaborators are unavailable.
                        </div>

                        <div v-else-if="externalOptions.length === 0" class="text-muted-foreground rounded-md border border-dashed p-3 text-sm">
                            No {{ externalLabel.toLowerCase() }} collaborators found.
                        </div>

                        <div v-else class="max-h-60 space-y-2 overflow-auto pr-1">
                            <button
                                v-for="collaborator in externalOptions"
                                :key="`external-option-${collaboratorKey(collaborator.id)}`"
                                type="button"
                                class="hover:bg-muted/70 flex w-full items-center justify-between rounded-lg border px-3 py-2 text-left transition"
                                :data-testid="`external-collaborator-option-${collaboratorKey(collaborator.id)}`"
                                @click="toggleCollaborator('external', collaborator)"
                            >
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium">{{ collaborator.name }}</span>
                                    <span v-if="collaborator.email" class="text-muted-foreground block truncate text-xs">{{ collaborator.email }}</span>
                                </span>
                                <UserPlus v-if="!isSelected('external', collaborator)" class="text-muted-foreground h-4 w-4 shrink-0" />
                                <Badge v-else variant="secondary">Selected</Badge>
                            </button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>