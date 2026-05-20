<script setup lang="ts">
import TaskCollaboratorField from '@/components/tasks/TaskCollaboratorField.vue';
import TaskEnvironmentField from '@/components/tasks/TaskEnvironmentField.vue';
import { Button } from '@/components/ui/button';
import { Select, type SelectOption, type SelectOptionValue } from '@/components/ui/select';
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

const defaultProjectId = computed(() => (props.projects.length === 1 ? props.projects[0].id : null));
const hasProjects = computed(() => props.projects.length > 0);
const showProjectSelector = computed(() => props.projects.length > 1);
const canSubmit = computed(() => createForm.value.projectId !== null && createForm.value.title.trim().length > 0);
const selectedProject = computed(() => props.projects.find((project) => project.id === createForm.value.projectId) ?? null);
const projectUsersLabel = computed(() => (selectedProject.value ? `${selectedProject.value.name} users` : 'Project users'));
const projectOptions = computed<SelectOption[]>(() => props.projects.map((project) => ({ value: project.id, label: project.name })));

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

function updateProject(projectId: number | null) {
    if (createForm.value.projectId === projectId) {
        return;
    }

    createForm.value.projectId = projectId;
    createForm.value.environment = null;
    createForm.value.collaborators = emptyTaskCollaborators();
}

function updateProjectFromSelect(value: SelectOptionValue) {
    updateProject(typeof value === 'number' ? value : value ? Number(value) : null);
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

        <SheetContent class="flex h-full flex-col p-0" side="right" width-preset="task">
            <SheetHeader class="p-0">
                <div class="px-6 pt-6 pb-3">
                    <SheetTitle>Create Task</SheetTitle>
                    <SheetDescription class="text-muted-foreground mt-1 text-sm">Add a new task to your project queue.</SheetDescription>
                </div>
            </SheetHeader>

            <TaskCreateForm
                class="min-h-0 flex-1"
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
                    <Select
                        :model-value="createForm.projectId"
                        :options="projectOptions"
                        placeholder="Select a project"
                        search-placeholder="Search projects..."
                        empty-label="No projects found."
                        searchable
                        test-id="create-task-project"
                        @update:modelValue="updateProjectFromSelect"
                    />
                </div>

                <TaskEnvironmentField
                    v-model="createForm.environment"
                    :project-id="createForm.projectId"
                    :projects="projects"
                    test-id="create-task-environment"
                />

                <TaskCollaboratorField
                    v-model="createForm.collaborators"
                    :environment="createForm.environment"
                    :external-label="projectUsersLabel"
                    :project-id="createForm.projectId"
                />
                <p class="text-muted-foreground text-xs">On create, the submitter and selected collaborators are notified.</p>

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
