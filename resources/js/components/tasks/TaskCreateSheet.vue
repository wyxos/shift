<script setup lang="ts">
import TaskCollaboratorField from '@/components/tasks/TaskCollaboratorField.vue';
import TaskEnvironmentField from '@/components/tasks/TaskEnvironmentField.vue';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { emptyTaskCollaborators, type TaskCollaboratorSelection } from '@/shared/tasks/collaborators';
import type { TaskProjectOption } from '@/shared/tasks/projects';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import TaskCreateForm from '@shared/components/TaskCreateForm.vue';
import axios from 'axios';
import { Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

type TaskCreateDraft = {
    title: string;
    priority: string;
    description: string;
    projectId: number | null;
    environment: string | null;
    collaborators: TaskCollaboratorSelection;
};

const props = withDefaults(
    defineProps<{
        projects?: TaskProjectOption[];
    }>(),
    {
        projects: () => [],
    },
);

const emit = defineEmits<{
    created: [taskId: number | null];
}>();

const page = usePage<SharedData>();
const aiImproveEnabled = computed(() => Boolean(page.props.shift?.ai_enabled));
const createOpen = ref(false);
const createLoading = ref(false);
const createUploading = ref(false);
const createError = ref<string | null>(null);
const createTempIdentifier = ref(Date.now().toString());
const inputClass =
    'file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground dark:bg-input/30 border-input flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]';

const defaultProjectId = computed(() => (props.projects.length === 1 ? props.projects[0].id : null));
const hasProjects = computed(() => props.projects.length > 0);
const showProjectSelector = computed(() => props.projects.length > 1);
const canSubmit = computed(() => createForm.value.projectId !== null);

const createForm = ref<TaskCreateDraft>({
    title: '',
    priority: 'medium',
    description: '',
    projectId: defaultProjectId.value,
    collaborators: emptyTaskCollaborators(),
    environment: null,
});

function resetCreateForm() {
    createForm.value = {
        title: '',
        priority: 'medium',
        description: '',
        projectId: defaultProjectId.value,
        environment: null,
        collaborators: emptyTaskCollaborators(),
    };
    createTempIdentifier.value = Date.now().toString();
    createError.value = null;
    createUploading.value = false;
}

function openCreate() {
    if (!hasProjects.value) return;
    resetCreateForm();
    createOpen.value = true;
}

function closeCreate() {
    createOpen.value = false;
}

async function createTask() {
    if (createForm.value.projectId === null) {
        createError.value = 'Select a project before creating the task.';
        return;
    }

    createError.value = null;
    createLoading.value = true;

    try {
        const response = await axios.post(route('tasks.v2.store'), {
            title: createForm.value.title,
            description: createForm.value.description,
            priority: createForm.value.priority,
            project_id: createForm.value.projectId,
            environment: createForm.value.environment,
            temp_identifier: createTempIdentifier.value,
            internal_collaborator_ids: createForm.value.collaborators.internal.map((collaborator) => Number(collaborator.id)),
            external_collaborators: createForm.value.collaborators.external.map((collaborator) => ({
                id: collaborator.id,
                name: collaborator.name,
                email: collaborator.email,
            })),
        });

        const created = response.data?.data ?? response.data;
        const createdId = typeof created?.id === 'number' ? created.id : null;

        closeCreate();
        emit('created', createdId);
        toast.success('Task created', { description: 'Your task has been added to the queue.' });
    } catch (error: any) {
        createError.value = error.response?.data?.error || error.response?.data?.message || error.message || 'Unknown error';
    } finally {
        createLoading.value = false;
    }
}
</script>

<template>
    <Sheet v-model:open="createOpen">
        <SheetTrigger as-child>
            <Button data-testid="open-create-task" size="sm" variant="default" :disabled="!hasProjects" @click="openCreate">
                <Plus class="mr-2 h-4 w-4" />
                Create
            </Button>
        </SheetTrigger>

        <SheetContent class="flex h-full w-full max-w-none flex-col p-0 sm:w-1/2 sm:max-w-none lg:w-1/3" side="right">
            <SheetHeader class="p-0">
                <div class="px-6 pt-6 pb-3">
                    <SheetTitle>Create Task</SheetTitle>
                    <SheetDescription class="text-muted-foreground mt-1 text-sm">Add a new task to your project queue.</SheetDescription>
                </div>
            </SheetHeader>

            <TaskCreateForm
                :model-value="{
                    title: createForm.title,
                    priority: createForm.priority,
                    description: createForm.description,
                }"
                :temp-identifier="createTempIdentifier"
                :enable-ai-improve="aiImproveEnabled"
                :error="createError"
                @submit="createTask"
                @update:modelValue="
                    (value) => {
                        createForm.title = value.title;
                        createForm.priority = value.priority;
                        createForm.description = value.description;
                    }
                "
                @update:uploading="createUploading = $event"
            >
                <div v-if="showProjectSelector" class="space-y-2">
                    <label class="flex items-center gap-2 text-sm leading-none font-medium select-none">Project</label>
                    <select v-model="createForm.projectId" :class="inputClass" data-testid="create-task-project">
                        <option :value="null">Select a project</option>
                        <option v-for="project in projects" :key="project.id" :value="project.id">
                            {{ project.name }}
                        </option>
                    </select>
                </div>

                <TaskEnvironmentField
                    v-model="createForm.environment"
                    :project-id="createForm.projectId"
                    :projects="projects"
                    test-id="create-task-environment"
                />

                <TaskCollaboratorField v-model="createForm.collaborators" :environment="createForm.environment" :project-id="createForm.projectId" />
                <p class="text-muted-foreground text-xs">
                    On create, the submitter and selected collaborators are notified.
                </p>

                <template #actions>
                    <SheetFooter class="flex flex-row items-center justify-between border-t px-6 py-4">
                        <Button type="button" variant="outline" @click="closeCreate">Cancel</Button>
                        <Button
                            data-testid="submit-create-task"
                            :disabled="createLoading || createUploading || !canSubmit"
                            type="submit"
                            variant="default"
                        >
                            <Plus class="mr-2 h-4 w-4" />
                            {{ createLoading ? 'Creating...' : 'Create' }}
                        </Button>
                    </SheetFooter>
                </template>
            </TaskCreateForm>
        </SheetContent>
    </Sheet>
</template>
