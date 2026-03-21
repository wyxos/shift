import type { CollaboratorOption, TaskCollaboratorSelection } from './collaborators';

export type Task = {
    id: number;
    title: string;
    status: string;
    priority: string;
    environment?: string | null;
    created_at?: string | null;
    updated_at?: string | null;
};

export type TaskPaginator = {
    data: Task[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

export type TaskAttachment = {
    id: number;
    original_filename: string;
    url?: string;
    path?: string;
};

export type TaskDetail = Task & {
    project_id: number;
    description?: string;
    created_at?: string;
    submitter?: { name?: string; email?: string } | null;
    environment?: string | null;
    attachments?: TaskAttachment[];
    is_owner?: boolean;
    can_manage_collaborators?: boolean;
    internal_collaborators?: CollaboratorOption[];
    external_collaborators?: CollaboratorOption[];
};

export type ThreadMessage = {
    clientId: string;
    id?: number;
    author: string;
    time: string;
    content: string;
    isYou?: boolean;
    pending?: boolean;
    failed?: boolean;
    attachments?: TaskAttachment[];
};

export type TaskIndexFilters = {
    status?: string[] | string | null;
    priority?: string[] | string | null;
    search?: string | null;
    environment?: string | null;
    sort_by?: string | null;
};

export type TaskIndexEditSnapshot = {
    title: string;
    priority: string;
    status: string;
    description: string;
    environment: string | null;
    collaborators: TaskCollaboratorSelection;
};

export type TaskIndexOpenEditOptions = {
    updateHistory?: boolean;
};
