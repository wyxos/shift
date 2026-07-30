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

export type MentionCandidate = {
    kind: 'internal' | 'external';
    id: number | string;
    name: string;
    email?: string | null;
    isCollaborator: boolean;
};

export type MentionIdentity = Pick<MentionCandidate, 'kind' | 'id'>;
