export type AttachmentItem = {
    id: string;
    name: string;
    size: number;
    type: string;
    progress: number;
    status: 'uploading' | 'done' | 'error';
    path?: string;
    uploadId?: string;
};

export type SentAttachment = Pick<AttachmentItem, 'name' | 'size' | 'type' | 'path' | 'status' | 'progress'>;

export type ProtectedFragment = {
    token: string;
    html: string;
};
