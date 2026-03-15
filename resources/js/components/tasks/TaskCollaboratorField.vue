<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import {
    collaboratorKey,
    emptyTaskCollaborators,
    normalizeTaskCollaborators,
    type CollaboratorOption,
    type TaskCollaboratorSelection,
} from '@/shared/tasks/collaborators';
import axios from 'axios';
import { LoaderCircle, Search, UserPlus, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue?: TaskCollaboratorSelection | null;
        projectId: number | null;
        environment?: string | null;
        readOnly?: boolean;
        disabled?: boolean;
    }>(),
    {
        modelValue: () => emptyTaskCollaborators(),
        environment: null,
        readOnly: false,
        disabled: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: TaskCollaboratorSelection];
}>();

const search = ref('');
const loading = ref(false);
const internalOptions = ref<CollaboratorOption[]>([]);
const externalOptions = ref<CollaboratorOption[]>([]);
const externalAvailable = ref(true);
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

async function fetchCollaborators() {
    if (props.readOnly) {
        internalOptions.value = [];
        externalOptions.value = [];
        externalAvailable.value = props.environment !== null;
        externalError.value = null;
        return;
    }

    if (props.projectId === null) {
        internalOptions.value = [];
        externalOptions.value = [];
        externalAvailable.value = false;
        externalError.value = 'Select a project before tagging collaborators.';
        return;
    }

    loading.value = true;

    try {
        const response = await axios.get(route('tasks.v2.collaborators', { project: props.projectId }), {
            params: {
                ...(search.value.trim() ? { search: search.value.trim() } : {}),
                ...(props.environment ? { environment: props.environment } : {}),
            },
        });

        internalOptions.value = Array.isArray(response.data?.internal) ? response.data.internal : [];
        externalOptions.value = Array.isArray(response.data?.external) ? response.data.external : [];
        externalAvailable.value = Boolean(response.data?.external_available);
        externalError.value = typeof response.data?.external_error === 'string' ? response.data.external_error : null;
    } catch (error: any) {
        internalOptions.value = [];
        externalOptions.value = [];
        externalAvailable.value = false;
        externalError.value = error.response?.data?.message || error.message || 'Failed to load collaborators.';
    } finally {
        loading.value = false;
    }
}

watch(
    () => [props.projectId, props.environment] as const,
    ([nextProjectId, nextEnvironment], previousValue) => {
        const [previousProjectId, previousEnvironment] = previousValue ?? [];

        if (!props.readOnly && previousProjectId !== undefined && previousProjectId !== null && previousProjectId !== nextProjectId) {
            emitSelection(emptyTaskCollaborators());
        } else if (
            !props.readOnly &&
            previousProjectId === nextProjectId &&
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
    if (props.readOnly || props.projectId === null) return;

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
            <div class="flex items-center gap-2 text-sm leading-none font-medium select-none">Collaborators</div>
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
                    <span class="font-medium">Internal</span>
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
                    <span class="font-medium">External</span>
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
                v-if="projectId === null"
                class="border-muted-foreground/30 bg-muted/10 text-muted-foreground rounded-md border border-dashed p-3 text-sm"
            >
                Select a project before tagging collaborators.
            </div>

            <template v-else>
                <div class="relative">
                    <Search class="text-muted-foreground absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2" />
                    <Input v-model="search" class="pl-9" data-testid="task-collaborators-search" placeholder="Search collaborators" />
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="space-y-3 rounded-xl border p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="text-sm font-medium">Internal</div>
                                <div class="text-muted-foreground text-xs">Registered SHIFT users on this project.</div>
                            </div>
                            <LoaderCircle v-if="loading" class="text-muted-foreground h-4 w-4 animate-spin" />
                        </div>

                        <div v-if="internalOptions.length === 0" class="text-muted-foreground rounded-md border border-dashed p-3 text-sm">
                            No internal collaborators found.
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
                                    <span v-if="collaborator.email" class="text-muted-foreground block truncate text-xs">{{
                                        collaborator.email
                                    }}</span>
                                </span>
                                <UserPlus v-if="!isSelected('internal', collaborator)" class="text-muted-foreground h-4 w-4 shrink-0" />
                                <Badge v-else variant="secondary">Selected</Badge>
                            </button>
                        </div>
                    </div>

                    <div class="space-y-3 rounded-xl border p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="text-sm font-medium">External</div>
                                <div class="text-muted-foreground text-xs">Eligible users from the connected client app.</div>
                            </div>
                            <LoaderCircle v-if="loading" class="text-muted-foreground h-4 w-4 animate-spin" />
                        </div>

                        <div
                            v-if="!externalAvailable"
                            class="border-muted-foreground/30 bg-muted/10 text-muted-foreground rounded-md border border-dashed p-3 text-sm"
                            data-testid="external-collaborators-unavailable"
                        >
                            {{ externalError ?? 'External collaborators are unavailable for this project.' }}
                        </div>

                        <div v-else-if="externalOptions.length === 0" class="text-muted-foreground rounded-md border border-dashed p-3 text-sm">
                            No external collaborators found.
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
                                    <span v-if="collaborator.email" class="text-muted-foreground block truncate text-xs">{{
                                        collaborator.email
                                    }}</span>
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
