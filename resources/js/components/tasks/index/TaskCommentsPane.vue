<script setup lang="ts">
import ShiftEditor from '@/components/ShiftEditor.vue';
import ConfirmRequestDialog from '@/shared/components/ConfirmRequestDialog.vue';
import { renderRichContent } from '@/shared/tasks/rich-content';
import { Paperclip } from 'lucide-vue-next';
import { ContextMenuContent, ContextMenuItem, ContextMenuPortal, ContextMenuRoot, ContextMenuSeparator, ContextMenuTrigger } from 'reka-ui';
import { computed, ref, unref, watch, type ComponentPublicInstance } from 'vue';
import TaskErrorOccurrencesPane from './TaskErrorOccurrencesPane.vue';

const props = defineProps<{
    state: any;
}>();
const state = props.state;
const threadComposerHtmlModel = computed({
    get: () => state.threadComposerHtml,
    set: (value: string) => state.setThreadComposerHtml(value),
});
const deleteDialogOpen = ref(false);
const deleteConfirmLoading = ref(false);
const deleteConfirmError = ref<string | null>(null);
const pendingDeleteMessage = ref<any | null>(null);
const deleteDialogOpenModel = computed({
    get: () => deleteDialogOpen.value,
    set: (value: boolean) => {
        if (!value && deleteConfirmLoading.value) return;
        deleteDialogOpen.value = value;
    },
});

const requestDeleteThreadMessage = (message: any) => {
    deleteConfirmLoading.value = false;
    deleteConfirmError.value = null;
    pendingDeleteMessage.value = message;
    deleteDialogOpen.value = true;
};

const clearPendingDeleteMessage = () => {
    pendingDeleteMessage.value = null;
};

const requestErrorMessage = (error: unknown, fallback: string) => {
    return error instanceof Error && error.message ? error.message : fallback;
};

const confirmDeleteThreadMessage = async () => {
    const message = pendingDeleteMessage.value;

    if (!message || deleteConfirmLoading.value) return;

    deleteConfirmLoading.value = true;
    deleteConfirmError.value = null;

    let deleted;
    try {
        deleted = await state.deleteThreadMessage(message);
    } catch (error) {
        deleteConfirmError.value = unref(state.threadError) || requestErrorMessage(error, 'Unable to delete this comment right now.');
        deleteConfirmLoading.value = false;
        return;
    }

    if (deleted === false) {
        deleteConfirmError.value = unref(state.threadError) || 'Unable to delete this comment right now.';
        deleteConfirmLoading.value = false;
        return;
    }

    deleteDialogOpen.value = false;
    pendingDeleteMessage.value = null;
};

const assignCommentsScrollRef = (value: Element | ComponentPublicInstance | null) => {
    state.commentsScrollRef = value instanceof HTMLElement ? value : null;
};

const showOccurrences = computed(() => state.isErrorIntakeTask && state.activeErrorThreadTab === 'occurrences');
const occurrenceCount = computed(() => {
    const total = state.errorOccurrencesPagination?.total;

    if (typeof total === 'number') {
        return total;
    }

    const occurrences = Array.isArray(state.errorOccurrences) ? state.errorOccurrences : [];

    return occurrences.length || state.editTask?.error_occurrences_count || 0;
});
const messageCountLabel = computed(() => {
    if (showOccurrences.value) {
        return `${occurrenceCount.value} occurrence${occurrenceCount.value === 1 ? '' : 's'}`;
    }

    return `${state.threadMessages.length} message${state.threadMessages.length === 1 ? '' : 's'}`;
});

const tabClass = (tab: 'comments' | 'occurrences') => [
    'rounded-md px-2.5 py-1.5 text-xs font-medium transition',
    state.activeErrorThreadTab === tab ? 'bg-foreground text-background shadow-sm' : 'text-muted-foreground hover:bg-muted/70 hover:text-foreground',
];

watch(deleteDialogOpen, (open) => {
    if (!open && !deleteConfirmLoading.value) {
        clearPendingDeleteMessage();
    }
});
</script>

<template>
    <div
        class="border-muted-foreground/10 bg-background h-full min-h-0 min-w-0 flex-col overflow-hidden rounded-md border"
        data-testid="task-comments-pane"
    >
        <div class="border-muted-foreground/10 flex items-center justify-between gap-3 border-b px-4 py-3">
            <div v-if="state.isErrorIntakeTask" class="bg-muted/40 flex rounded-lg p-1" role="tablist" aria-label="Error task discussion">
                <button
                    :aria-selected="state.activeErrorThreadTab === 'comments'"
                    :class="tabClass('comments')"
                    data-testid="error-comments-tab"
                    role="tab"
                    type="button"
                    @click="state.setActiveErrorThreadTab('comments')"
                >
                    Comments
                </button>
                <button
                    :aria-selected="state.activeErrorThreadTab === 'occurrences'"
                    :class="tabClass('occurrences')"
                    data-testid="error-occurrences-tab"
                    dusk="error-occurrences-tab"
                    role="tab"
                    type="button"
                    @click="state.setActiveErrorThreadTab('occurrences')"
                >
                    Occurrences
                </button>
            </div>
            <div v-else>
                <h3 class="text-foreground text-sm font-semibold">
                    {{ state.isRequirementPhase ? 'Clarifications' : 'Comments' }}
                </h3>
            </div>
            <div class="text-muted-foreground shrink-0 text-xs">{{ messageCountLabel }}</div>
        </div>

        <TaskErrorOccurrencesPane v-if="showOccurrences" :state="state" />

        <div v-else :ref="assignCommentsScrollRef" class="flex-1 space-y-3 overflow-auto px-4 py-4" @load.capture="state.onCommentsMediaLoadCapture">
            <div v-if="state.threadLoading" class="text-muted-foreground py-6 text-center text-sm">Loading comments...</div>
            <div v-else-if="state.threadError" class="text-destructive py-6 text-center text-sm">{{ state.threadError }}</div>
            <div v-else-if="state.threadMessages.length === 0" class="text-muted-foreground py-6 text-center text-sm">No comments yet.</div>
            <div
                v-for="message in state.threadMessages"
                :key="message.clientId"
                :class="message.isYou ? 'justify-end' : 'justify-start'"
                class="flex"
            >
                <div class="max-w-[86%]">
                    <ContextMenuRoot @update:open="(open) => state.onCommentContextMenuOpen(message, open)">
                        <ContextMenuTrigger as-child>
                            <div
                                :id="message.id ? `comment-${message.id}` : undefined"
                                :data-testid="message.id ? `comment-bubble-${message.id}` : undefined"
                                :class="
                                    message.isYou
                                        ? 'rounded-br-md bg-sky-600 text-white'
                                        : 'border-muted-foreground/10 bg-background/70 text-foreground rounded-bl-md border'
                                "
                                class="rounded-lg px-3 py-2 text-sm shadow-sm"
                                @dblclick="state.canComment && state.onMessageDblClick(message, $event)"
                                @touchend="state.canComment && state.onMessageTouchEnd(message, $event)"
                            >
                                <div v-if="!message.isYou" class="text-foreground/80 mb-1 text-[11px] font-semibold">
                                    {{ message.author }}
                                </div>
                                <div
                                    class="shift-rich text-inherit [&_img]:my-2 [&_img]:max-w-full [&_img]:cursor-zoom-in [&_img]:rounded-lg [&_img]:shadow-sm [&_img.editor-tile]:aspect-square [&_img.editor-tile]:w-[200px] [&_img.editor-tile]:max-w-[200px] [&_img.editor-tile]:object-cover"
                                    @click="state.onRichContentClick"
                                    v-html="renderRichContent(message.content)"
                                ></div>
                                <div v-if="message.attachments?.length" class="mt-3 flex flex-wrap gap-2">
                                    <a
                                        v-for="attachment in message.attachments"
                                        :key="attachment.id"
                                        :href="attachment.url"
                                        :class="
                                            message.isYou
                                                ? 'border-white/20 bg-white/10 text-white hover:bg-white/15'
                                                : 'border-muted-foreground/20 bg-muted/20 text-foreground hover:bg-muted/30'
                                        "
                                        class="inline-flex max-w-[260px] items-center gap-1.5 truncate rounded-md border px-2 py-1 text-xs transition"
                                        rel="noreferrer"
                                        target="_blank"
                                    >
                                        <Paperclip class="h-3 w-3 shrink-0 opacity-80" />
                                        <span class="min-w-0 truncate">{{ attachment.original_filename }}</span>
                                    </a>
                                </div>
                            </div>
                        </ContextMenuTrigger>
                        <ContextMenuPortal>
                            <ContextMenuContent
                                class="bg-popover text-popover-foreground z-50 min-w-[10rem] overflow-hidden rounded-md border p-1 shadow-md"
                            >
                                <ContextMenuItem
                                    v-if="!message.isYou"
                                    class="hover:bg-accent hover:text-accent-foreground relative flex cursor-default items-center rounded-sm px-2 py-1.5 text-sm outline-none select-none"
                                    @select="state.copyEntireMessage(message)"
                                >
                                    Copy
                                </ContextMenuItem>
                                <ContextMenuItem
                                    v-if="state.shouldShowCopySelection(message)"
                                    class="hover:bg-accent hover:text-accent-foreground relative flex cursor-default items-center rounded-sm px-2 py-1.5 text-sm outline-none select-none"
                                    @select="state.copySelectedMessage"
                                >
                                    Copy selection
                                </ContextMenuItem>
                                <ContextMenuItem
                                    v-if="state.canComment && !message.isYou && message.id && !message.pending"
                                    class="hover:bg-accent hover:text-accent-foreground relative flex cursor-default items-center rounded-sm px-2 py-1.5 text-sm outline-none select-none"
                                    @select="state.startReplyToMessage(message)"
                                >
                                    Reply
                                </ContextMenuItem>
                                <ContextMenuSeparator
                                    v-if="state.canComment && !message.isYou && message.id && !message.pending"
                                    class="bg-border -mx-1 my-1 h-px"
                                />
                                <ContextMenuItem
                                    v-if="state.canComment && message.isYou && message.id && !message.pending"
                                    class="hover:bg-accent hover:text-accent-foreground relative flex cursor-default items-center rounded-sm px-2 py-1.5 text-sm outline-none select-none"
                                    @select="state.startThreadEdit(message)"
                                >
                                    Edit
                                </ContextMenuItem>
                                <ContextMenuSeparator
                                    v-if="state.canComment && message.isYou && message.id && !message.pending"
                                    class="bg-border -mx-1 my-1 h-px"
                                />
                                <ContextMenuItem
                                    v-if="state.canComment && message.isYou && message.id && !message.pending"
                                    class="text-destructive hover:bg-accent hover:text-destructive relative flex cursor-default items-center rounded-sm px-2 py-1.5 text-sm outline-none select-none"
                                    data-testid="delete-thread-message"
                                    @select="requestDeleteThreadMessage(message)"
                                >
                                    Delete
                                </ContextMenuItem>
                            </ContextMenuContent>
                        </ContextMenuPortal>
                    </ContextMenuRoot>
                    <div :class="message.isYou ? 'text-right' : 'text-left'" class="text-muted-foreground mt-1 text-[11px]">
                        {{ message.time }}
                    </div>
                </div>
            </div>
        </div>

        <div v-if="!showOccurrences" class="border-muted-foreground/10 bg-background/80 border-t px-4 py-3 backdrop-blur">
            <div v-if="state.threadEditError" class="text-destructive mb-2 text-xs">{{ state.threadEditError }}</div>
            <ShiftEditor
                v-if="state.canComment"
                ref="state.threadComposerRef"
                v-model="threadComposerHtmlModel"
                :enable-ai-improve="state.aiImproveEnabled"
                :ai-context="state.threadAiContext"
                :cancelable="Boolean(state.threadEditingId)"
                :clear-on-send="false"
                :temp-identifier="state.threadTempIdentifier"
                data-testid="comments-editor"
                :placeholder="state.threadEditingId ? 'Edit your comment...' : 'Write a comment...'"
                @cancel="state.cancelThreadEdit"
                @uploading="state.setThreadComposerUploading($event)"
                @send="state.handleThreadSend"
            />
            <div v-else class="text-muted-foreground py-2 text-sm">Commenting is unavailable for this task.</div>
        </div>

        <ConfirmRequestDialog
            v-model:open="deleteDialogOpenModel"
            title="Delete comment"
            confirm-label="Delete comment"
            confirm-test-id="confirm-thread-message-delete"
            confirm-variant="destructive"
            :error="deleteConfirmError"
            :loading="deleteConfirmLoading"
            loading-label="Deleting..."
            @cancel="deleteDialogOpen = false"
            @confirm="confirmDeleteThreadMessage"
        >
            <template #description>Delete this comment from the thread? This cannot be undone.</template>
        </ConfirmRequestDialog>
    </div>
</template>
