<script setup lang="ts">
import ShiftEditor from '@/components/ShiftEditor.vue';
import TaskCollaboratorField from '@/components/tasks/TaskCollaboratorField.vue';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { ImageLightbox } from '@/components/ui/image-lightbox';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { getPriorityOptions, getStatusOptions } from '@/shared/tasks/presentation';
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
const editTitleModel = computed({
    get: () => state.editForm.title,
    set: (value: string) => state.setEditField('title', value),
});

const taskStatusOptions = getStatusOptions({ includeClosed: false });
const taskPriorityOptions = getPriorityOptions();

function formatTaskTime(value?: string | null) {
    if (!value) return 'Unknown';
    return value.slice(11, 16);
}
</script>

<template>
    <Sheet :open="state.editOpen" @update:open="state.onEditOpenChange">
        <SheetContent class="flex h-full flex-col p-0" side="right" width-preset="task">
            <SheetHeader class="p-0">
                <div class="px-6 pt-6 pb-3">
                    <SheetTitle>Edit Task</SheetTitle>
                    <SheetDescription class="text-muted-foreground mt-1 text-sm">
                        {{ state.editTask?.title || 'Task details' }}
                    </SheetDescription>
                </div>
            </SheetHeader>

            <div class="flex-1 overflow-auto px-6 pb-4">
                <div v-if="state.editLoading" class="text-muted-foreground py-10 text-center text-sm">Loading task...</div>
                <div v-else-if="state.editError" class="text-destructive py-10 text-center text-sm">{{ state.editError }}</div>
                <div v-else-if="state.editTask" class="grid min-h-0 gap-4 lg:grid-cols-2" data-testid="task-edit-layout">
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
                        class="min-w-0 space-y-4"
                        data-testid="task-edit-details-pane"
                    >
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
                                type="text"
                            />
                        </div>

                        <div class="space-y-2">
                            <Label class="text-muted-foreground">Status</Label>
                            <ButtonGroup
                                v-model="editStatusModel"
                                :aria-label="'Task status'"
                                :class="'xl:grid-cols-4'"
                                :columns="2"
                                :options="taskStatusOptions"
                                test-id-prefix="task-status"
                            />
                        </div>

                        <div class="space-y-2">
                            <Label class="text-muted-foreground">Priority</Label>
                            <ButtonGroup
                                v-model="editPriorityModel"
                                :aria-label="'Task priority'"
                                :class="'xl:grid-cols-3'"
                                :columns="3"
                                :options="taskPriorityOptions"
                                test-id-prefix="task-priority"
                            />
                        </div>

                        <div class="space-y-2">
                            <Label class="text-muted-foreground">Description</Label>
                            <ShiftEditor
                                v-model="editDescriptionModel"
                                :enable-ai-improve="state.aiImproveEnabled"
                                :temp-identifier="state.editTempIdentifier"
                                data-testid="task-edit-description"
                                min-height="180"
                            />
                        </div>

                        <div class="space-y-2">
                            <TaskCollaboratorField
                                :disabled="state.editLoading || state.editUploading"
                                :environment="state.editForm.environment"
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
                                        v-if="state.isOwner"
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

                    <TaskCommentsPane :class="state.editMobilePane === 'details' ? 'hidden lg:flex' : 'flex'" :state="state" />
                </div>
            </div>

            <SheetFooter class="flex flex-row items-center justify-between border-t px-6 py-4">
                <div class="text-destructive text-sm">{{ state.taskSaveError }}</div>
                <div class="flex items-center gap-2">
                    <Button type="button" variant="outline" @click="state.attemptCloseEdit">Close</Button>
                    <Button :disabled="state.taskSaving" type="button" variant="default" @click="state.saveTaskChanges">
                        {{ state.taskSaving ? 'Saving...' : 'Save' }}
                    </Button>
                </div>
            </SheetFooter>
        </SheetContent>
    </Sheet>

    <Dialog v-model:open="confirmCloseOpenModel">
        <DialogContent class="sm:max-w-md">
            <div class="space-y-2">
                <div class="text-base font-semibold">Discard changes?</div>
                <div class="text-muted-foreground text-sm">You have unsaved changes. If you close now, they will be lost.</div>
            </div>

            <div class="mt-6 flex items-center justify-end gap-2">
                <Button type="button" variant="outline" @click="state.setConfirmCloseOpen(false)">Cancel</Button>
                <Button type="button" variant="destructive" @click="state.discardChangesAndClose">Discard</Button>
            </div>
        </DialogContent>
    </Dialog>

    <ImageLightbox v-model:open="state.lightboxOpen" :alt="state.lightboxAlt" :src="state.lightboxSrc" />
</template>
