import { ref } from 'vue';
import axios from 'axios';
import type { Ref } from 'vue';

interface AttachmentFile {
    path: string;
    original_filename: string;
    url?: string;
}

interface ExistingAttachment {
    id: number;
    original_filename: string;
    url: string;
}

export function useTaskAttachments(initialAttachments: ExistingAttachment[] = []) {
    // Generate a unique identifier for temporary files
    const tempIdentifier: Ref<string> = ref(Date.now().toString());
    const uploadedFiles: Ref<AttachmentFile[]> = ref([]);
    const existingAttachments: Ref<ExistingAttachment[]> = ref([...initialAttachments]);
    const deletedAttachmentIds: Ref<number[]> = ref([]);
    const isUploading: Ref<boolean> = ref(false);
    const uploadError: Ref<string> = ref('');

    // Function to truncate long filenames, showing part of the start and end
    function truncateFilename(filename: string, maxLength: number = 30): string {
        if (!filename || filename.length <= maxLength) {
            return filename;
        }

        const extension = filename.lastIndexOf('.') > 0 ? filename.substring(filename.lastIndexOf('.')) : '';
        const nameWithoutExtension = filename.substring(0, filename.length - extension.length);

        // Calculate how many characters to keep from start and end
        const startChars = Math.floor((maxLength - 3 - extension.length) / 2);
        const endChars = Math.ceil((maxLength - 3 - extension.length) / 2);

        return nameWithoutExtension.substring(0, startChars) + '...' + nameWithoutExtension.substring(nameWithoutExtension.length - endChars) + extension;
    }

    // Handle file upload
    const handleFileUpload = (event: Event): void => {
        const files = (event.target as HTMLInputElement)?.files;
        if (!files?.length) return;

        for (let i = 0; i < files.length; i++) {
            uploadFile(files[i]);
        }

        // Clear the file input
        (event.target as HTMLInputElement).value = '';
    };

    // Upload a single file
    const uploadFile = async (file: File): Promise<void> => {
        isUploading.value = true;
        uploadError.value = '';

        const formData = new FormData();
        formData.append('file', file);
        formData.append('temp_identifier', tempIdentifier.value);

        try {
            const response = await axios.post(route('attachments.upload'), formData, {
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
            });

            uploadedFiles.value.push(response.data);
            isUploading.value = false;
        } catch (error: any) {
            isUploading.value = false;
            uploadError.value = error.response?.data?.message || 'Error uploading file';
            console.error('Upload error:', error);
        }
    };

    // Load temporary files
    const loadTempFiles = async (): Promise<void> => {
        try {
            const response = await axios.get(route('attachments.list-temp'), {
                params: { temp_identifier: tempIdentifier.value },
            });

            uploadedFiles.value = response.data.files;
        } catch (error) {
            console.error('Error loading temp files:', error);
        }
    };

    // Remove a temporary file
    const removeFile = async (file: AttachmentFile): Promise<void> => {
        try {
            await axios.delete(route('attachments.remove-temp'), {
                params: { path: file.path },
            });

            // Remove from the list
            uploadedFiles.value = uploadedFiles.value.filter((f) => f.path !== file.path);
        } catch (error) {
            console.error('Error removing file:', error);
        }
    };

    // Delete an existing attachment
    const deleteAttachment = (attachment: ExistingAttachment): void => {
        // Add to deleted attachments list
        deletedAttachmentIds.value.push(attachment.id);
        // Remove from the displayed list
        existingAttachments.value = existingAttachments.value.filter((a) => a.id !== attachment.id);
    };

    return {
        // State
        tempIdentifier,
        uploadedFiles,
        existingAttachments,
        deletedAttachmentIds,
        isUploading,
        uploadError,

        // Methods
        truncateFilename,
        handleFileUpload,
        removeFile,
        deleteAttachment,
        loadTempFiles,
    };
}
