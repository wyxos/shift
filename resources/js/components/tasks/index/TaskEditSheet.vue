<script setup lang="ts">
import ShiftEditor from '@/components/ShiftEditor.vue';
import TaskCollaboratorField from '@/components/tasks/TaskCollaboratorField.vue';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ImageLightbox } from '@/components/ui/image-lightbox';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { getPriorityOptions, getRequirementStatusOptions, getStatusOptions } from '@/shared/tasks/presentation';
import { renderRichContent } from '@/shared/tasks/rich-content';
import { computed } from 'vue';
import TaskCommentsPane from './TaskCommentsPane.vue';

const props = defineProps<{
    state: any;
}>();
const state = props.state;
const confirmCloseOpenModel = computed({
    get: () => state.confirmCloseOpen,
    set: (value: boolean) => state.setConfirmCloseOpen(value),
});
const editDescriptionModel = computed({
    get: () => state.editForm.description,
    set: (value: string) => state.setEditField('description', value),
});
const editMobilePaneModel = computed({
    get: () => state.editMobilePane,
    set: (value: 'details' | 'comments') => state.setEditMobilePane(value),
});
const editPriorityModel = computed({
    get: () => state.editForm.priority,
    set: (value: string) => state.setEditField('priority', value),
});
const editStatusModel = computed({
    get: () => state.editForm.status,
    set: (value: string) => state.setEditField('status', value),
});
const editRequirementStatusModel = computed({
    get: () => state.editForm.requirement_status,
    set: (value: string) => state.setEditField('requirement_status', value),
});
const editTitleModel = computed({
    get: () => state.editForm.title,
    set: (value: string) => state.setEditField('title', value),
});

const taskStatusOptions = getStatusOptions({ includeClosed: false });
const requirementStatusOptions = getRequirementStatusOptions();
const taskPriorityOptions = getPriorityOptions();
const sheetTitle = computed(() => state.editTask?.title || (state.isRequirementPhase ? 'Requirement details' : 'Task details'));
const sheetDescription = computed(() => (state.isRequirementPhase ? 'Requirement details' : 'Task details'));
const canShowFinalizeRequirement = computed(
    () => state.isRequirementPhase && state.canFinalizeRequirement && state.editForm.requirement_status === 'ready-to-finalize',
);

function formatTaskTime(value?: string | null) {
    if (!value) return 'Unknown';
    return value.slice(11, 16);
}
</script>

<template>
    <Sheet :open="state.editOpen" @update:open="state.onEditOpenChange">
        <SheetContent class="flex h-full min-h-0 flex-col p-0" side="right" width-preset="task">
            <SheetHeader class="shrink-0 p-0">
                <div class="px-6 pt-6 pb-3">
                    <SheetTitle class="truncate">{{ sheetTitle }}</SheetTitle>
                    <SheetDescription class="text-muted-foreground mt-1 text-sm">
                        {{ sheetDescription }}
                    </SheetDescription>
                </div>
            </SheetHeader>

            <div class="min-h-0 flex-1 overflow-hidden px-6 pb-4">
                <div v-if="state.editLoading" class="text-muted-foreground py-10 text-center text-sm">Loading task...</div>
                <div v-else-if="state.editError" class="text-destructive py-10 text-center text-sm">{{ state.editError }}</div>
                <div v-else-if="state.editTask" class="grid h-full min-h-0 gap-4 lg:grid-cols-2" data-testid="task-edit-layout">
                    <div class="lg:hidden">
                        <ButtonGroup
                            v-model="editMobilePaneModel"
                            :options="state.editMobilePaneOptions"
                            aria-label="Edit task section"
                            class="w-full"
                            :columns="2"
                            test-id-prefix="edit-mobile-pane"
                        />
                    </div>
                    <div
                        :class="state.editMobilePane === 'comments' ? 'hidden lg:block' : 'block'"
                        class="min-h-0 min-w-0 overflow-auto pr-1"
                        data-testid="task-edit-details-pane"
                    >
                        <div class="flex flex-col gap-4">
                            <div class="grid gap-4 sm:grid-cols-3">
                                <div class="space-y-1">
                                    <div class="text-muted-foreground text-xs tracking-wide uppercase">Created by</div>
                                    <div data-testid="edit-task-created-by" class="text-foreground text-sm font-medium">
                                        {{ state.editTaskCreatorLabel }}
                                    </div>
                                </div>
                                <div class="space-y-1">
                                    <div class="text-muted-foreground text-xs tracking-wide uppercase">Created</div>
                                    <div data-testid="edit-task-created-at" class="text-foreground text-sm font-medium">
                                        {{ formatTaskTime(state.editTask.created_at) }}
                                    </div>
                                </div>
                                <div class="space-y-1">
                                    <div class="text-muted-foreground text-xs tracking-wide uppercase">Updated</div>
                                    <div data-testid="edit-task-updated-at" class="text-foreground text-sm font-medium">
                                        Updated {{ formatTaskTime(state.editTask.updated_at) }}
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-2">
                                <Label class="text-muted-foreground">Title</Label>
                                <input
                                    v-model="editTitleModel"
                                    class="file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground dark:bg-input/30 border-input text-foreground focus-visible:border-ring focus-visible:ring-ring/50 flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium focus-visible:ring-[3px] disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                                    data-testid="task-edit-title"
                                    :disabled="!state.canEditTaskScope || state.taskSaving"
                                    type="text"
                                />
                            </div>

                            <div v-if="!state.isRequirementPhase" class="space-y-2">
                                <Label class="text-muted-foreground">Status</Label>
                                <ButtonGroup
                                    v-model="editStatusModel"
                                    :aria-label="'Task status'"
                                    :class="'xl:grid-cols-4'"
                                    :columns="2"
                                    :disabled="!state.canEditTaskScope || state.taskSaving"
                                    :options="taskStatusOptions"
                                    test-id-prefix="task-status"
                                />
                            </div>

                            <div v-if="state.isRequirementPhase" class="space-y-2">
                                <Label class="text-muted-foreground">Requirement state</Label>
                                <ButtonGroup
                                    v-model="editRequirementStatusModel"
                                    :aria-label="'Requirement state'"
                                    :class="'xl:grid-cols-3'"
                                    :columns="2"
                                    :disabled="!state.canEditTaskScope || state.taskSaving"
                                    :options="requirementStatusOptions"
                                    test-id-prefix="requirement-status"
                                />
                            </div>

                            <div class="space-y-2">
                                <Label class="text-muted-foreground">Priority</Label>
                                <ButtonGroup
                                    v-model="editPriorityModel"
                                    :aria-label="'Task priority'"
                                    :class="'xl:grid-cols-3'"
                                    :columns="3"
                                    :disabled="!state.canEditTaskScope || state.taskSaving"
                                    :options="taskPriorityOptions"
                                    test-id-prefix="task-priority"
                                />
                            </div>

                            <div class="space-y-2">
                                <Label class="text-muted-foreground">Description</Label>
                                <ShiftEditor
                                    v-if="state.canEditTaskScope"
                                    v-model="editDescriptionModel"
                                    :enable-ai-improve="state.aiImproveEnabled"
                                    :temp-identifier="state.editTempIdentifier"
                                    data-testid="task-edit-description"
                                    min-height="180"
                                    :sendable="false"
                                />
                                <div
                                    v-else
                                    class="shift-rich border-muted-foreground/30 bg-muted/10 text-foreground min-h-24 rounded-md border border-dashed p-3 text-sm"
                                    data-testid="task-edit-description"
                                    v-html="renderRichContent(state.editForm.description)"
                                ></div>
                            </div>

                            <div
                                v-if="state.isRequirementPhase && (state.editTask.submitted_title || state.editTask.submitted_description)"
                                class="space-y-2"
                            >
                                <Label class="text-muted-foreground">Original Submission</Label>
                                <div class="border-muted-foreground/30 bg-muted/10 rounded-md border border-dashed p-3 text-sm">
                                    <div v-if="state.editTask.submitted_title" class="text-foreground font-medium">
                                        {{ state.editTask.submitted_title }}
                                    </div>
                                    <div
                                        v-if="state.editTask.submitted_description"
                                        class="shift-rich text-muted-foreground mt-2"
                                        v-html="state.editTask.submitted_description"
                                    ></div>
                                </div>
                            </div>

                            <div class="space-y-2">
                                <TaskCollaboratorField
                                    :disabled="state.editLoading || state.editUploading"
                                    :environment="state.editForm.environment"
                                    :external-label="state.editTaskProjectUsersLabel"
                                    :model-value="state.editForm.collaborators"
                                    :project-id="state.editTask.project_id ?? null"
                                    :read-only="!state.canManageCollaborators"
                                    @update:model-value="state.updateEditCollaborators"
                                />
                                <p v-if="state.canManageCollaborators" class="text-muted-foreground text-xs">
                                    Adding collaborators here sends access notifications to newly added collaborators only.
                                </p>
                            </div>

                            <div class="space-y-2">
                                <Label class="text-muted-foreground">Environment</Label>
                                <div
                                    data-testid="edit-task-environment"
                                    class="text-foreground border-muted-foreground/30 bg-muted/10 rounded-md border border-dashed px-3 py-2 text-sm"
                                >
                                    {{ state.editTaskEnvironmentLabel }}
                                </div>
                            </div>

                            <div class="space-y-2">
                                <Label class="text-muted-foreground">Attachments</Label>
                                <div v-if="state.taskAttachments.length" class="space-y-2">
                                    <div
                                        v-for="attachment in state.taskAttachments"
                                        :key="attachment.id"
                                        class="border-muted-foreground/20 bg-muted/10 text-foreground flex items-center gap-2 rounded-md border px-3 py-2 text-sm"
                                    >
                                        <a
                                            :href="attachment.url"
                                            class="hover:text-foreground min-w-0 flex-1 truncate transition"
                                            rel="noreferrer"
                                            target="_blank"
                                        >
                                            {{ attachment.original_filename }}
                                        </a>
                                        <Button
                                            v-if="state.canEditTaskScope"
                                            size="sm"
                                            type="button"
                                            variant="outline"
                                            @click="state.removeAttachmentFromTask(attachment.id)"
                                        >
                                            Remove
                                        </Button>
                                    </div>
                                </div>
                                <div
                                    v-else
                                    class="border-muted-foreground/30 bg-muted/10 text-muted-foreground rounded-md border border-dashed p-3 text-sm"
                                >
                                    No attachments available
                                </div>
                            </div>
                        </div>
                    </div>

                    <TaskCommentsPane :class="state.editMobilePane === 'details' ? 'hidden lg:flex' : 'flex'" :state="state" />
                </div>
            </div>

            <SheetFooter class="flex flex-row items-center justify-between border-t px-6 py-4">
                <div class="text-destructive text-sm">{{ state.taskSaveError || state.requirementFinalizeError }}</div>
                <div class="flex items-center gap-2">
                    <Button type="button" variant="outline" @click="state.attemptCloseEdit">Close</Button>
                    <TooltipProvider v-if="canShowFinalizeRequirement" :delay-duration="0">
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <span class="inline-flex">
                                    <Button
                                        :disabled="state.taskSaving || state.requirementFinalizing"
                                        type="button"
                                        variant="outline"
                                        data-testid="finalize-requirement"
                                        @click="state.finalizeRequirement"
                                    >
                                        {{ state.requirementFinalizing ? 'Finalizing...' : 'Finalize Requirement' }}
                                    </Button>
                                </span>
                            </TooltipTrigger>
                            <TooltipContent>
                                <p>Promotes this requirement into an active task while keeping the same ID, collaborators, and clarifications.</p>
                            </TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                    <Button
                        v-if="state.canEditTaskScope || state.canManageCollaborators"
                        :disabled="state.taskSaving"
                        type="button"
                        variant="default"
                        data-testid="save-task-changes"
                        @click="state.saveTaskChanges"
                    >
                        {{ state.taskSaving ? 'Saving...' : 'Save' }}
                    </Button>
                </div>
            </SheetFooter>
        </SheetContent>
    </Sheet>

    <Dialog v-model:open="confirmCloseOpenModel">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Discard changes?</DialogTitle>
                <DialogDescription>You have unsaved changes. If you close now, they will be lost.</DialogDescription>
            </DialogHeader>

            <div class="mt-6 flex items-center justify-end gap-2">
                <Button type="button" variant="outline" @click="state.setConfirmCloseOpen(false)">Cancel</Button>
                <Button type="button" variant="destructive" @click="state.discardChangesAndClose">Discard</Button>
            </div>
        </DialogContent>
    </Dialog>

    <ImageLightbox v-model:open="state.lightboxOpen" :alt="state.lightboxAlt" :src="state.lightboxSrc" />
</template>
