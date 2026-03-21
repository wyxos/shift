import type { AxiosInstance } from 'axios';
import { computed, reactive, ref, type ComputedRef, type Ref } from 'vue';
import { MAX_UPLOAD_BYTES, uploadChunkedFile, type UploadEndpoints } from '../../lib/chunkedUpload';
import type { AttachmentItem } from './types';

declare const route: undefined | ((name: string, params?: Record<string, unknown>) => string);

type UseShiftEditorAttachmentsOptions = {
    axiosClient: ComputedRef<AxiosInstance | typeof import('axios').default>;
    tempIdentifier: Ref<string>;
    uploadEndpoints?: UploadEndpoints;
    removeTempUrl?: string;
};

export function useShiftEditorAttachments(options: UseShiftEditorAttachmentsOptions) {
    const attachments = ref<AttachmentItem[]>([]);
    const fileInput = ref<HTMLInputElement | null>(null);

    const isUploadingAttachments = computed(() => attachments.value.some((attachment) => attachment.status === 'uploading'));

    function formatBytes(bytes: number): string {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        if (i === 0) return `${bytes} ${sizes[i]}`;
        return `${(bytes / Math.pow(k, i)).toFixed(1)} ${sizes[i]}`;
    }

    function resolveRemoveTempUrl(): string | null {
        if (options.removeTempUrl) return options.removeTempUrl;
        if (typeof route === 'function') {
            return route('attachments.remove-temp') as string;
        }
        return null;
    }

    async function removeAttachment(att: AttachmentItem) {
        try {
            const removeUrl = resolveRemoveTempUrl();
            if (att.path && removeUrl) {
                await options.axiosClient.value.delete(removeUrl, { params: { path: att.path } });
            }
        } catch {
            // ignore
        } finally {
            attachments.value = attachments.value.filter((attachment) => attachment.id !== att.id);
        }
    }

    function createUploadId() {
        return `upload-${Math.random().toString(36).slice(2)}-${Date.now()}`;
    }

    async function uploadAttachment(file: File) {
        const id = createUploadId();
        const att = reactive<AttachmentItem>({
            id,
            name: file.name,
            size: file.size,
            type: file.type || 'application/octet-stream',
            progress: 0,
            status: 'uploading',
        });
        attachments.value.push(att);

        try {
            if (file.size > MAX_UPLOAD_BYTES) {
                throw new Error('File exceeds 40MB limit');
            }
            const data = await uploadChunkedFile({
                file,
                tempIdentifier: options.tempIdentifier.value,
                onProgress: (percent) => {
                    att.progress = Math.max(att.progress, percent);
                },
                axiosInstance: options.axiosClient.value,
                endpoints: options.uploadEndpoints,
            });
            att.status = 'done';
            att.progress = 100;
            att.path = data.path;
        } catch {
            att.status = 'error';
        }
    }

    function openFilePicker() {
        fileInput.value?.click();
    }

    function onFileChosen(event: Event) {
        const files = (event.target as HTMLInputElement).files;
        if (!files?.length) return;

        Array.from(files).forEach(uploadAttachment);
        (event.target as HTMLInputElement).value = '';
    }

    function resetAttachments() {
        attachments.value = [];
    }

    return {
        attachments,
        fileInput,
        formatBytes,
        isUploadingAttachments,
        onFileChosen,
        openFilePicker,
        removeAttachment,
        resetAttachments,
        uploadAttachment,
    };
}
