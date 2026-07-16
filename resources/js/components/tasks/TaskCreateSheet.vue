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
import { AlertCircle, CheckCircle2, LoaderCircle, Mail, Plus, UploadCloud } from 'lucide-vue-next';
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

type TaskEmailImportResult = {
    title?: string;
    priority?: string;
    description_html?: string;
    missing_details?: string[];
    source?: {
        subject?: string;
        from?: string;
        attachments?: string[];
    };
    ai_used?: boolean;
    ai_error?: string | null;
};

const props = withDefaults(
    defineProps<{
        projects?: TaskProjectOption[];
        surface?: 'tasks' | 'requirements';
    }>(),
    {
        projects: () => [],
        surface: 'tasks',
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
const emailImportInput = ref<HTMLInputElement | null>(null);
const emailImportLoading = ref(false);
const emailImportDragging = ref(false);
const emailImportError = ref<string | null>(null);
const emailImportResult = ref<TaskEmailImportResult | null>(null);
const isRequirementSurface = computed(() => props.surface === 'requirements');

const defaultProjectId = computed(() => (props.projects.length === 1 ? props.projects[0].id : null));
const hasProjects = computed(() => props.projects.length > 0);
const showProjectSelector = computed(() => props.projects.length > 1);
const canSubmit = computed(() => createForm.value.projectId !== null && createForm.value.title.trim().length > 0);
const selectedProject = computed(() => props.projects.find((project) => project.id === createForm.value.projectId) ?? null);
const projectUsersLabel = computed(() => (selectedProject.value ? `${selectedProject.value.name} users` : 'Project users'));
const projectOptions = computed<SelectOption[]>(() => props.projects.map((project) => ({ value: project.id, label: project.name })));
const createButtonLabel = computed(() => (isRequirementSurface.value ? 'Create Requirement' : 'Create'));
const createSheetTitle = computed(() => (isRequirementSurface.value ? 'Create Requirement' : 'Create Task'));
const createSheetDescription = computed(() =>
    isRequirementSurface.value ? 'Add a new requirement to your review queue.' : 'Add a new task to your project queue.',
);
const createTitleLabel = computed(() => (isRequirementSurface.value ? 'Requirement' : 'Task'));
const createDescriptionLabel = computed(() => (isRequirementSurface.value ? 'Details' : 'Description'));
const createTitlePlaceholder = computed(() => (isRequirementSurface.value ? 'Short, descriptive requirement' : 'Short, descriptive title'));
const createDescriptionPlaceholder = computed(() =>
    isRequirementSurface.value ? 'Write the requirement details, then drag files into the editor.' : undefined,
);
const createSuccessTitle = computed(() => (isRequirementSurface.value ? 'Requirement created' : 'Task created'));
const createSuccessDescription = computed(() =>
    isRequirementSurface.value ? 'Your requirement has been added to the review queue.' : 'Your task has been added to the queue.',
);
const importedAttachmentCount = computed(() => emailImportResult.value?.source?.attachments?.length ?? 0);
const importedMissingDetails = computed(() => emailImportResult.value?.missing_details ?? []);

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
    emailImportError.value = null;
    emailImportLoading.value = false;
    emailImportDragging.value = false;
    emailImportResult.value = null;

    if (emailImportInput.value) {
        emailImportInput.value.value = '';
    }
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
    emailImportError.value = null;
}

function updateProjectFromSelect(value: SelectOptionValue) {
    updateProject(typeof value === 'number' ? value : value ? Number(value) : null);
}

function openEmailImportFilePicker() {
    if (!aiImproveEnabled.value || emailImportLoading.value) {
        return;
    }

    emailImportInput.value?.click();
}

function handleEmailImportInput(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = firstImportFile(input.files);

    void importEmailFile(file);

    input.value = '';
}

function handleEmailImportDrop(event: DragEvent) {
    emailImportDragging.value = false;
    void importEmailFile(firstImportFile(event.dataTransfer?.files));
}

function firstImportFile(files: FileList | File[] | null | undefined): File | null {
    if (!files || files.length < 1) {
        return null;
    }

    return files[0] ?? null;
}

function isEmlFile(file: File): boolean {
    return file.name.toLowerCase().endsWith('.eml') || file.type === 'message/rfc822';
}

function applyEmailImportResult(result: TaskEmailImportResult) {
    const title = typeof result.title === 'string' ? result.title.trim() : '';
    const description = typeof result.description_html === 'string' ? result.description_html.trim() : '';
    const priority = typeof result.priority === 'string' ? result.priority : '';

    if (title !== '') {
        createForm.value.title = title;
    }

    if (['low', 'medium', 'high'].includes(priority)) {
        createForm.value.priority = priority;
    }

    if (description !== '') {
        createForm.value.description = description;
    }
}

async function importEmailFile(file: File | null) {
    if (!aiImproveEnabled.value) {
        return;
    }

    if (!file) {
        return;
    }

    if (createForm.value.projectId === null) {
        emailImportError.value = 'Select a project before importing an email.';
        return;
    }

    if (!isEmlFile(file)) {
        emailImportError.value = 'Use an .eml email file.';
        return;
    }

    emailImportLoading.value = true;
    emailImportError.value = null;
    emailImportResult.value = null;

    try {
        const formData = new FormData();

        formData.append('project_id', String(createForm.value.projectId));
        formData.append('email', file);

        const response = await axios.post(route('tasks.email-import'), formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        const result = (response.data?.data ?? response.data ?? {}) as TaskEmailImportResult;

        applyEmailImportResult(result);
        emailImportResult.value = result;

        if (result.ai_error) {
            emailImportError.value = result.ai_error;
        }

        toast.success('Email imported', { description: 'Review the task draft before creating it.' });
    } catch (error: any) {
        emailImportError.value =
            error.response?.data?.errors?.email?.[0] ||
            error.response?.data?.errors?.project_id?.[0] ||
            error.response?.data?.message ||
            error.message ||
            'The email could not be imported.';
    } finally {
        emailImportLoading.value = false;
    }
}

async function createTask() {
    if (createForm.value.projectId === null) {
        createError.value = 'Select a project before creating the task.';
        return;
    }

    createError.value = null;
    createLoading.value = true;

    try {
        const response = await axios.post(route('tasks.store'), {
            title: createForm.value.title,
            description: createForm.value.description,
            priority: createForm.value.priority,
            project_id: createForm.value.projectId,
            phase: isRequirementSurface.value ? 'requirement' : undefined,
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
        toast.success(createSuccessTitle.value, { description: createSuccessDescription.value });
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
                {{ createButtonLabel }}
            </Button>
        </SheetTrigger>

        <SheetContent class="flex h-full flex-col p-0" side="right" width-preset="task">
            <SheetHeader class="p-0">
                <div class="px-6 pt-6 pb-3">
                    <SheetTitle>{{ createSheetTitle }}</SheetTitle>
                    <SheetDescription class="text-muted-foreground mt-1 text-sm">{{ createSheetDescription }}</SheetDescription>
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
                :title-label="createTitleLabel"
                :description-label="createDescriptionLabel"
                :title-placeholder="createTitlePlaceholder"
                :description-placeholder="createDescriptionPlaceholder"
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

                <div v-if="aiImproveEnabled && !isRequirementSurface" class="space-y-2">
                    <label class="text-muted-foreground flex items-center gap-2 text-sm leading-none font-medium select-none">
                        <Mail class="h-4 w-4" />
                        Email
                    </label>
                    <input
                        ref="emailImportInput"
                        accept=".eml,message/rfc822"
                        class="sr-only"
                        data-testid="task-email-import-input"
                        type="file"
                        @change="handleEmailImportInput"
                    />
                    <button
                        type="button"
                        data-testid="task-email-import-dropzone"
                        :disabled="emailImportLoading"
                        class="border-muted-foreground/30 hover:border-primary/60 hover:bg-muted/40 flex w-full items-center gap-3 rounded-md border border-dashed px-4 py-3 text-left transition disabled:cursor-not-allowed disabled:opacity-70"
                        :class="emailImportDragging ? 'border-primary bg-muted/50' : ''"
                        @click="openEmailImportFilePicker"
                        @dragenter.prevent="emailImportDragging = true"
                        @dragover.prevent="emailImportDragging = true"
                        @dragleave.prevent="emailImportDragging = false"
                        @drop.prevent="handleEmailImportDrop"
                    >
                        <LoaderCircle v-if="emailImportLoading" class="text-muted-foreground h-5 w-5 animate-spin" />
                        <UploadCloud v-else class="text-muted-foreground h-5 w-5" />
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium">
                                {{ emailImportLoading ? 'Digesting email...' : 'Drop .eml or browse' }}
                            </span>
                            <span class="text-muted-foreground block truncate text-xs">
                                {{ emailImportResult ? (emailImportResult.ai_used ? 'AI draft ready' : 'Imported without AI') : 'Outlook export' }}
                            </span>
                        </span>
                    </button>

                    <div
                        v-if="emailImportResult?.source?.subject"
                        data-testid="task-email-import-summary"
                        class="border-border bg-muted/30 space-y-1 rounded-md border px-3 py-2 text-sm"
                    >
                        <div class="flex min-w-0 items-center gap-2">
                            <CheckCircle2 class="h-4 w-4 shrink-0 text-emerald-600" />
                            <span class="truncate font-medium">{{ emailImportResult.source.subject }}</span>
                        </div>
                        <p v-if="emailImportResult.source.from" class="text-muted-foreground truncate text-xs">
                            From {{ emailImportResult.source.from }}
                        </p>
                        <p v-if="importedAttachmentCount > 0" class="text-muted-foreground text-xs">
                            {{ importedAttachmentCount }} attachment{{ importedAttachmentCount === 1 ? '' : 's' }} referenced
                        </p>
                    </div>

                    <div
                        v-if="importedMissingDetails.length > 0"
                        data-testid="task-email-import-missing"
                        class="border-border bg-background rounded-md border px-3 py-2 text-sm"
                    >
                        <div class="text-muted-foreground mb-1 flex items-center gap-2 text-xs font-medium">
                            <AlertCircle class="h-4 w-4" />
                            Missing details
                        </div>
                        <ul class="list-disc space-y-1 pl-5">
                            <li v-for="detail in importedMissingDetails" :key="detail">{{ detail }}</li>
                        </ul>
                    </div>

                    <p v-if="emailImportError" data-testid="task-email-import-error" class="text-sm text-red-600">
                        {{ emailImportError }}
                    </p>
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
                            {{ createLoading ? 'Creating...' : createButtonLabel }}
                        </Button>
                    </SheetFooter>
                </template>
            </TaskCreateForm>
        </SheetContent>
    </Sheet>
</template>
