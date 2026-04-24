<script setup lang="ts">
import ShiftEditor from '@/components/ShiftEditor.vue';
import { renderRichContent } from '@/shared/tasks/rich-content';
import { Paperclip } from 'lucide-vue-next';
import { ContextMenuContent, ContextMenuItem, ContextMenuPortal, ContextMenuRoot, ContextMenuSeparator, ContextMenuTrigger } from 'reka-ui';
import { computed } from 'vue';

const props = defineProps<{
    state: any;
}>();
const state = props.state;
const threadComposerHtmlModel = computed({
    get: () => state.threadComposerHtml,
    set: (value: string) => state.setThreadComposerHtml(value),
});
</script>

<template>
    <div
        class="border-muted-foreground/10 via-background to-background max-h-[70vh] min-h-[28rem] min-w-0 flex-col overflow-hidden rounded-2xl border bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-slate-900/5 lg:h-full lg:max-h-none lg:min-h-0"
        data-testid="task-comments-pane"
    >
        <div class="border-muted-foreground/10 flex items-center justify-between border-b px-4 py-3">
            <div>
                <h3 class="text-foreground text-sm font-semibold">Comments</h3>
            </div>
            <div class="text-muted-foreground text-xs">
                {{ state.threadMessages.length }} message{{ state.threadMessages.length === 1 ? '' : 's' }}
            </div>
        </div>

        <div ref="state.commentsScrollRef" class="flex-1 space-y-3 overflow-auto px-4 py-4" @load.capture="state.onCommentsMediaLoadCapture">
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
                                @dblclick="state.onMessageDblClick(message, $event)"
                                @touchend="state.onMessageTouchEnd(message, $event)"
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
                                    v-if="!message.isYou && message.id && !message.pending"
                                    class="hover:bg-accent hover:text-accent-foreground relative flex cursor-default items-center rounded-sm px-2 py-1.5 text-sm outline-none select-none"
                                    @select="state.startReplyToMessage(message)"
                                >
                                    Reply
                                </ContextMenuItem>
                                <ContextMenuSeparator v-if="!message.isYou && message.id && !message.pending" class="bg-border -mx-1 my-1 h-px" />
                                <ContextMenuItem
                                    v-if="message.isYou && message.id && !message.pending"
                                    class="hover:bg-accent hover:text-accent-foreground relative flex cursor-default items-center rounded-sm px-2 py-1.5 text-sm outline-none select-none"
                                    @select="state.startThreadEdit(message)"
                                >
                                    Edit
                                </ContextMenuItem>
                                <ContextMenuSeparator v-if="message.isYou && message.id && !message.pending" class="bg-border -mx-1 my-1 h-px" />
                                <ContextMenuItem
                                    v-if="message.isYou && message.id && !message.pending"
                                    class="text-destructive hover:bg-accent hover:text-destructive relative flex cursor-default items-center rounded-sm px-2 py-1.5 text-sm outline-none select-none"
                                    @select="state.deleteThreadMessage(message)"
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

        <div class="border-muted-foreground/10 bg-background/80 border-t px-4 py-3 backdrop-blur">
            <div v-if="state.threadEditError" class="text-destructive mb-2 text-xs">{{ state.threadEditError }}</div>
            <ShiftEditor
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
        </div>
    </div>
</template>
