import { ref, computed, nextTick } from 'vue';
import axios from 'axios';
import { marked } from 'marked';
import type { Ref } from 'vue';

interface Message {
    id: number;
    sender: string;
    content: string;
    timestamp: string;
    isCurrentUser: boolean;
    attachments: any[];
    created_at?: string;
}

interface ThreadFile {
    path: string;
    original_filename: string;
    url?: string;
}

export function useTaskThreads(taskId: number) {
    // Thread state
    const activeTab: Ref<'internal' | 'external'> = ref('internal');
    const internalMessages: Ref<Message[]> = ref([]);
    const externalMessages: Ref<Message[]> = ref([]);
    const internalNewMessage: Ref<string> = ref('Type a message...');
    const externalNewMessage: Ref<string> = ref('Type a message...');

    // Refs for message containers to enable autoscrolling
    const internalMessagesContainer: Ref<HTMLElement | null> = ref(null);
    const externalMessagesContainer: Ref<HTMLElement | null> = ref(null);

    // Thread attachment state
    const internalThreadTempIdentifier: Ref<string> = ref(Date.now().toString() + '_internal_thread');
    const externalThreadTempIdentifier: Ref<string> = ref(Date.now().toString() + '_external_thread');
    const internalThreadAttachments: Ref<ThreadFile[]> = ref([]);
    const externalThreadAttachments: Ref<ThreadFile[]> = ref([]);
    const isThreadUploading: Ref<boolean> = ref(false);
    const threadUploadError: Ref<string> = ref('');

    // Drag and drop state
    const isDraggingInternal: Ref<boolean> = ref(false);
    const isDraggingExternal: Ref<boolean> = ref(false);

    // Computed property to get the current thread temp identifier based on active tab
    const currentThreadTempIdentifier = computed(() => {
        return activeTab.value === 'internal' ? internalThreadTempIdentifier.value : externalThreadTempIdentifier.value;
    });

    // Function to render markdown content
    function renderMarkdown(content: string): string {
        return marked(content);
    }

    // Function to scroll message container to the bottom
    const scrollToBottom = (container: HTMLElement | null): void => {
        if (container) {
            nextTick(() => {
                setTimeout(() => {
                    container.scrollTop = container.scrollHeight;
                }, 50);
            });
        }
    };

    // Drag event handlers
    const handleDragOver = (event: DragEvent, type: 'internal' | 'external'): void => {
        event.preventDefault();
        if (type === 'internal') {
            isDraggingInternal.value = true;
        } else {
            isDraggingExternal.value = true;
        }
    };

    const handleDragLeave = (event: DragEvent, type: 'internal' | 'external'): void => {
        event.preventDefault();
        if (type === 'internal') {
            isDraggingInternal.value = false;
        } else {
            isDraggingExternal.value = false;
        }
    };

    const handleDrop = (event: DragEvent, type: 'internal' | 'external'): void => {
        event.preventDefault();
        activeTab.value = type;

        if (type === 'internal') {
            isDraggingInternal.value = false;
        } else {
            isDraggingExternal.value = false;
        }

        handleThreadFileUpload(event);
    };

    // Handle thread file upload
    const handleThreadFileUpload = (event: Event | DragEvent): void => {
        const files = (event.target as HTMLInputElement)?.files || (event as DragEvent).dataTransfer?.files;
        if (!files?.length) return;

        for (let i = 0; i < files.length; i++) {
            uploadThreadFile(files[i]);
        }

        // Clear the file input if it's a file input element
        if ((event.target as HTMLInputElement)?.value !== undefined) {
            (event.target as HTMLInputElement).value = '';
        }
    };

    // Upload a thread file
    const uploadThreadFile = async (file: File): Promise<void> => {
        isThreadUploading.value = true;
        threadUploadError.value = '';

        const formData = new FormData();
        formData.append('file', file);
        formData.append('temp_identifier', currentThreadTempIdentifier.value);

        try {
            const response = await axios.post(route('attachments.upload'), formData, {
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
            });

            if (activeTab.value === 'internal') {
                internalThreadAttachments.value.push(response.data);
            } else {
                externalThreadAttachments.value.push(response.data);
            }
            isThreadUploading.value = false;
        } catch (error: any) {
            isThreadUploading.value = false;
            threadUploadError.value = error.response?.data?.message || 'Error uploading file';
            console.error('Thread upload error:', error);
        }
    };

    // Remove a thread attachment
    const removeThreadAttachment = async (file: ThreadFile): Promise<void> => {
        try {
            await axios.delete(route('attachments.remove-temp'), {
                params: { path: file.path },
            });

            if (activeTab.value === 'internal') {
                internalThreadAttachments.value = internalThreadAttachments.value.filter((f) => f.path !== file.path);
            } else {
                externalThreadAttachments.value = externalThreadAttachments.value.filter((f) => f.path !== file.path);
            }
        } catch (error) {
            console.error('Error removing thread attachment:', error);
        }
    };

    // Function to send a new message
    const sendMessage = async (event?: Event, opts?: { tempIdentifierOverride?: string }): Promise<void> => {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }

        const messageContent = activeTab.value === 'internal' ? internalNewMessage.value : externalNewMessage.value;
        const currentAttachments = activeTab.value === 'internal' ? internalThreadAttachments.value : externalThreadAttachments.value;

        // Allow external editor (ShiftEditor) to force a temp identifier even if we didn't track attachments here
        const tempIdToSend = opts?.tempIdentifierOverride ?? (currentAttachments.length > 0 ? currentThreadTempIdentifier.value : null);

        if (!messageContent.trim() && !tempIdToSend) return;

        try {
            const response = await axios.post(route('task-threads.store', { task: taskId }), {
                content: messageContent,
                type: activeTab.value,
                temp_identifier: tempIdToSend,
            });

            const message: Message = {
                id: response.data.thread.id,
                sender: response.data.thread.sender_name,
                content: response.data.thread.content,
                timestamp: new Date(response.data.thread.created_at).toLocaleTimeString([], {
                    hour: '2-digit',
                    minute: '2-digit',
                }),
                isCurrentUser: response.data.thread.is_current_user,
                attachments: response.data.thread.attachments || [],
                created_at: response.data.thread.created_at,
            };

            if (activeTab.value === 'internal') {
                internalMessages.value.push(message);
                internalNewMessage.value = '';
                internalThreadAttachments.value = [];
                internalThreadTempIdentifier.value = Date.now().toString() + '_internal_thread';
                scrollToBottom(internalMessagesContainer.value);
            } else {
                externalMessages.value.push(message);
                externalNewMessage.value = '';
                externalThreadAttachments.value = [];
                externalThreadTempIdentifier.value = Date.now().toString() + '_external_thread';
                scrollToBottom(externalMessagesContainer.value);
            }
        } catch (error) {
            console.error('Error sending message:', error);
            alert('Failed to send message. Please try again.');
        }
    };

    // Function to check if a message is older than 1 minute
    const isMessageDeletable = (createdAt?: string): boolean => {
        if (!createdAt) return false;

        const messageDate = new Date(createdAt);
        const now = new Date();
        const diffInMinutes = (now.getTime() - messageDate.getTime()) / (1000 * 60);

        return diffInMinutes <= 1;
    };

    // Function to delete a message
    const deleteMessage = async (messageId: number, messageType: 'internal' | 'external'): Promise<void> => {
        if (!confirm('Are you sure you want to delete this message?')) {
            return;
        }

        try {
            await axios.delete(
                route('task-threads.destroy', {
                    task: taskId,
                    thread: messageId,
                }),
            );

            if (messageType === 'internal') {
                internalMessages.value = internalMessages.value.filter((message) => message.id !== messageId);
            } else {
                externalMessages.value = externalMessages.value.filter((message) => message.id !== messageId);
            }
        } catch (error: any) {
            console.error('Error deleting message:', error);
            if (error.response?.data?.error === 'Messages can only be deleted within 1 minute of creation') {
                alert('Messages can only be deleted within 1 minute of creation.');
            } else {
                alert('Failed to delete message. Please try again.');
            }
        }
    };

    // Load task threads from the server
    const loadTaskThreads = async (): Promise<void> => {
        try {
            const response = await axios.get(route('task-threads.index', { task: taskId }));

            if (response.data.internal && Array.isArray(response.data.internal)) {
                internalMessages.value = response.data.internal.map((thread: any): Message => ({
                    id: thread.id,
                    sender: thread.sender_name,
                    content: thread.content,
                    timestamp: new Date(thread.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
                    isCurrentUser: thread.is_current_user,
                    attachments: thread.attachments || [],
                    created_at: thread.created_at,
                }));
                scrollToBottom(internalMessagesContainer.value);
            }

            if (response.data.external && Array.isArray(response.data.external)) {
                externalMessages.value = response.data.external.map((thread: any): Message => ({
                    id: thread.id,
                    sender: thread.sender_name,
                    content: thread.content,
                    timestamp: new Date(thread.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
                    isCurrentUser: thread.is_current_user,
                    attachments: thread.attachments || [],
                    created_at: thread.created_at,
                }));
                scrollToBottom(externalMessagesContainer.value);
            }
        } catch (error) {
            console.error('Error loading task threads:', error);
        }
    };

    return {
        // State
        activeTab,
        internalMessages,
        externalMessages,
        internalNewMessage,
        externalNewMessage,
        internalMessagesContainer,
        externalMessagesContainer,
        internalThreadAttachments,
        externalThreadAttachments,
        isThreadUploading,
        threadUploadError,
        isDraggingInternal,
        isDraggingExternal,
        // expose identifiers so external editor can reuse
        internalThreadTempIdentifier,
        externalThreadTempIdentifier,

        // Methods
        renderMarkdown,
        handleDragOver,
        handleDragLeave,
        handleDrop,
        handleThreadFileUpload,
        removeThreadAttachment,
        sendMessage,
        isMessageDeletable,
        deleteMessage,
        loadTaskThreads,
    };
}
