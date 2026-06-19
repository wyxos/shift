import type { CollaboratorOption, TaskCollaboratorSelection } from './collaborators';

export type Task = {
    id: number;
    project_id?: number | null;
    project?: TaskProjectSummary | null;
    title: string;
    type?: 'task' | 'app_error' | string;
    type_label?: string;
    status: string;
    requirement_status?: string | null;
    priority: string;
    phase?: 'task' | 'requirement' | string;
    finalized?: boolean;
    batch?: RequirementBatchSummary | null;
    environment?: string | null;
    created_at?: string | null;
    updated_at?: string | null;
    can_comment?: boolean;
    can_delete?: boolean;
    can_edit_task?: boolean;
    can_edit_requirement?: boolean;
    can_finalize_requirement?: boolean;
};

export type TaskProjectSummary = {
    id: number;
    name: string;
};

export type RequirementBatchSummary = {
    id: number;
    title?: string | null;
    created_at?: string | null;
    total_items: number;
    requirement_items: number;
    ready_items?: number;
    finalized_items: number;
    can_finalize_requirement?: boolean;
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
    submitted_title?: string | null;
    submitted_description?: string | null;
    finalized_at?: string | null;
    attachments?: TaskAttachment[];
    is_owner?: boolean;
    can_comment?: boolean;
    can_delete?: boolean;
    can_edit_task?: boolean;
    can_edit_requirement?: boolean;
    can_finalize_requirement?: boolean;
    can_manage_collaborators?: boolean;
    internal_collaborators?: CollaboratorOption[];
    external_collaborators?: CollaboratorOption[];
    error_signature?: string | null;
    error_source?: string | null;
    error_environment?: string | null;
    error_release?: string | null;
    error_git_sha?: string | null;
    error_exception_class?: string | null;
    error_name?: string | null;
    error_culprit_file?: string | null;
    error_culprit_line?: number | null;
    error_culprit_function?: string | null;
    error_occurrences_count?: number | null;
    error_first_seen_at?: string | null;
    error_last_seen_at?: string | null;
};

export type TaskErrorOccurrence = {
    id: number;
    number: number;
    source: string;
    environment?: string | null;
    release?: string | null;
    git_sha?: string | null;
    exception_class?: string | null;
    error_name?: string | null;
    message?: string | null;
    culprit?: {
        file?: string | null;
        line?: number | null;
        function?: string | null;
    };
    request?: {
        method?: string | null;
        url?: string | null;
        path?: string | null;
        referrer?: string | null;
        query?: Record<string, unknown> | null;
        body?: Record<string, unknown> | null;
    };
    occurred_at?: string | null;
    received_at?: string | null;
    created_at?: string | null;
    payload?: Record<string, unknown>;
    stacktrace?: {
        frames?: TaskErrorStackFrame[];
    };
    context?: Record<string, unknown>;
    user?: Record<string, unknown>;
    metadata?: Record<string, unknown>;
};

export type TaskErrorStackFrame = {
    file?: string | null;
    line?: number | null;
    function?: string | null;
    in_app?: boolean | null;
    context?: {
        start_line?: number | null;
        lines?: Array<{
            number: number;
            text: string;
            active?: boolean | null;
        }>;
    } | null;
};

export type TaskErrorOccurrencePagination = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from?: number | null;
    to?: number | null;
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
    organisation_id?: number | string | null;
    project_id?: number | string | null;
    type?: string | null;
    sort_by?: string | null;
};

export type TaskIndexEditSnapshot = {
    title: string;
    priority: string;
    status: string;
    requirement_status: string;
    description: string;
    environment: string | null;
    collaborators: TaskCollaboratorSelection;
};

export type TaskIndexOpenEditOptions = {
    updateHistory?: boolean;
};
