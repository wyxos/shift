import type { useTaskIndexThreadState } from './useTaskIndexThreadState';

type TaskIndexThreadState = ReturnType<typeof useTaskIndexThreadState>;

export function taskIndexThreadBindings(thread: TaskIndexThreadState) {
    return {
        cancelThreadEdit: thread.cancelThreadEdit,
        commentsScrollRef: thread.commentsScrollRef,
        contextMenuMessageId: thread.contextMenuMessageId,
        contextMenuSelectionText: thread.contextMenuSelectionText,
        copyEntireMessage: thread.copyEntireMessage,
        copySelectedMessage: thread.copySelectedMessage,
        deleteThreadMessage: thread.deleteThreadMessage,
        handleReplyReferenceClick: thread.handleReplyReferenceClick,
        handleThreadSend: thread.handleThreadSend,
        lightboxAlt: thread.lightboxAlt,
        lightboxOpen: thread.lightboxOpen,
        lightboxSrc: thread.lightboxSrc,
        onCommentContextMenuOpen: thread.onCommentContextMenuOpen,
        onCommentsMediaLoadCapture: thread.onCommentsMediaLoadCapture,
        onGlobalClickCapture: thread.onGlobalClickCapture,
        onGlobalDblClickCapture: thread.onGlobalDblClickCapture,
        onGlobalKeyDownCapture: thread.onGlobalKeyDownCapture,
        onMessageDblClick: thread.onMessageDblClick,
        onMessageTouchEnd: thread.onMessageTouchEnd,
        onRichContentClick: thread.onRichContentClick,
        scrollCommentsToBottomSoon: thread.scrollCommentsToBottomSoon,
        shouldShowCopySelection: thread.shouldShowCopySelection,
        startReplyToMessage: thread.startReplyToMessage,
        startThreadEdit: thread.startThreadEdit,
        threadAiContext: thread.threadAiContext,
        threadComposerHtml: thread.threadComposerHtml,
        threadComposerRef: thread.threadComposerRef,
        threadComposerUploading: thread.threadComposerUploading,
        threadEditError: thread.threadEditError,
        threadEditSaving: thread.threadEditSaving,
        threadEditingId: thread.threadEditingId,
        threadError: thread.threadError,
        threadLoading: thread.threadLoading,
        threadMessages: thread.threadMessages,
        threadSending: thread.threadSending,
        threadTempIdentifier: thread.threadTempIdentifier,
    };
}
