<script setup lang="ts">
import ShiftEditor from '@/components/ShiftEditor.vue';
import TaskCollaboratorField from '@/components/tasks/TaskCollaboratorField.vue';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ImageLightbox } from '@/components/ui/image-lightbox';
import { InputGroup, InputGroupAddon, InputGroupButton, InputGroupInput } from '@/components/ui/input-group';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { renderRichContent } from '@/shared/tasks/rich-content';
import { ArrowLeft } from 'lucide-vue-next';
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui';
import { computed } from 'vue';
import TaskCommentsPane from './TaskCommentsPane.vue';
import TaskEditSummary from './TaskEditSummary.vue';
import TaskErrorOccurrencesPane from './TaskErrorOccurrencesPane.vue';

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
const editTitleModel = computed({
    get: () => state.editForm.title,
    set: (value: string) => state.setEditField('title', value),
});

const sheetTitle = computed(() => state.editTask?.title || (state.isRequirementPhase ? 'Requirement details' : 'Task details'));
const titleInputLabel = computed(() => (state.isRequirementPhase ? 'Requirement title' : 'Task title'));
const canShowFinalizeRequirement = computed(
    () => state.isRequirementPhase && state.canFinalizeRequirement && state.editForm.requirement_status === 'ready-to-finalize',
);

const errorSectionModel = computed({
    get: () => (state.isErrorIntakeTask ? state.activeErrorSection : 'task'),
    set: (value: string | number) => state.setActiveErrorSection(value === 'events' ? 'events' : 'task'),
});
const eventCount = computed(() => state.errorOccurrencesPagination?.total ?? state.editTask?.error_occurrences_count ?? 0);
</script>

<template>
    <Sheet :open="state.editOpen" @update:open="state.onEditOpenChange">
        <SheetContent :show-close="false" class="flex h-full min-h-0 flex-col p-0" side="right" width-preset="task">
            <SheetHeader class="shrink-0 p-0">
                <div class="px-6 pt-6 pb-3">
                    <SheetTitle class="min-w-0">
                        <InputGroup data-testid="task-title-group">
                            <InputGroupAddon>
                                <InputGroupButton
                                    size="icon-sm"
                                    aria-label="Back to tasks"
                                    data-testid="task-edit-back"
                                    type="button"
                                    @click="state.onEditOpenChange(false)"
                                >
                                    <ArrowLeft />
                                </InputGroupButton>
                            </InputGroupAddon>
                            <InputGroupInput
                                v-if="state.editTask"
                                v-model="editTitleModel"
                                :aria-label="titleInputLabel"
                                data-shift-field-control
                                data-testid="task-edit-title"
                                :disabled="!state.canEditTaskScope || state.taskSaving"
                                type="text"
                            />
                            <span v-else class="block truncate">{{ sheetTitle }}</span>
                        </InputGroup>
                    </SheetTitle>
                </div>
            </SheetHeader>

            <div class="min-h-0 flex-1 overflow-hidden px-6 pb-4">
                <div v-if="state.editLoading" class="text-muted-foreground py-10 text-center text-sm">Loading task...</div>
                <div v-else-if="state.editError" class="text-destructive py-10 text-center text-sm">{{ state.editError }}</div>
                <TabsRoot v-else-if="state.editTask" v-model="errorSectionModel" class="flex h-full min-h-0 flex-col gap-4">
                    <template v-if="state.isErrorIntakeTask">
                        <TaskEditSummary :state="state" class="shrink-0" />
                        <TabsList aria-label="Error report sections" class="border-muted-foreground/10 flex shrink-0 gap-2 border-b pb-3">
                            <TabsTrigger value="task" data-testid="error-task-tab" class="error-section-tab">Task</TabsTrigger>
                            <TabsTrigger value="events" data-testid="error-events-tab" dusk="error-events-tab" class="error-section-tab">
                                Events <span class="tabular-nums">{{ eventCount }}</span>
                            </TabsTrigger>
                        </TabsList>
                    </template>
                    <div class="relative min-h-0 flex-1">
                        <Transition name="error-section">
                            <TabsContent
                                value="task"
                                force-mount
                                v-show="errorSectionModel === 'task'"
                                class="grid h-full min-h-0 grid-rows-[auto_minmax(0,1fr)] gap-4 lg:grid-cols-2 lg:grid-rows-1"
                                data-testid="task-edit-layout"
                            >
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
                                        <TaskEditSummary v-if="!state.isErrorIntakeTask" :state="state" />

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
                                            v-if="
                                                state.isRequirementPhase && (state.editTask.submitted_title || state.editTask.submitted_description)
                                            "
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

                                        <div v-if="state.taskAttachments.length" class="space-y-2" data-testid="task-edit-attachments">
                                            <Label class="text-muted-foreground">Attachments</Label>
                                            <div class="space-y-2">
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
                                        </div>
                                    </div>
                                </div>

                                <TaskCommentsPane :class="state.editMobilePane === 'details' ? 'hidden lg:flex' : 'flex'" :state="state" />
                            </TabsContent>
                        </Transition>
                        <Transition name="error-section">
                            <TabsContent
                                v-if="state.isErrorIntakeTask"
                                value="events"
                                force-mount
                                v-show="errorSectionModel === 'events'"
                                class="flex h-full min-h-0 min-w-0 flex-col"
                            >
                                <TaskErrorOccurrencesPane :state="state" />
                            </TabsContent>
                        </Transition>
                    </div>
                </TabsRoot>
            </div>

            <SheetFooter
                v-if="canShowFinalizeRequirement || state.requirementFinalizeError"
                class="flex flex-row items-center justify-between border-t px-6 py-4"
                data-testid="task-edit-footer"
            >
                <div class="text-destructive text-sm">{{ state.requirementFinalizeError }}</div>
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

<style scoped>
@reference "../../../../css/app.css";
.error-section-tab {
    @apply focus-visible:ring-ring flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none motion-reduce:transition-none;
}
.error-section-tab[data-state='active'] {
    @apply bg-foreground text-background;
}
.error-section-tab[data-state='inactive'] {
    @apply text-muted-foreground hover:bg-muted hover:text-foreground;
}
.error-section-enter-active,
.error-section-leave-active {
    transition: opacity 120ms ease;
}
.error-section-leave-active {
    position: absolute;
    inset: 0;
}
.error-section-enter-from,
.error-section-leave-to {
    opacity: 0;
}
@media (prefers-reduced-motion: reduce) {
    .error-section-enter-active,
    .error-section-leave-active {
        transition: none;
    }
}
</style>
