import { Archive, ArrowDown, ArrowUp, CheckCircle2, Circle, CircleDashed, CircleX, Clock3, Equal, MessageCircle, PauseCircle } from 'lucide-vue-next';

export type TaskFilterOption = {
    value: string;
    label: string;
};

export type SortByOption = {
    value: string;
    label: string;
};

export type TaskTypeOption = TaskFilterOption;

const STATUS_OPTIONS: TaskFilterOption[] = [
    { value: 'pending', label: 'Pending' },
    { value: 'in-progress', label: 'In Progress' },
    { value: 'awaiting-feedback', label: 'Awaiting Feedback' },
    { value: 'on-hold', label: 'On Hold' },
    { value: 'completed', label: 'Completed' },
    { value: 'closed', label: 'Closed' },
];

const REQUIREMENT_STATUS_OPTIONS: TaskFilterOption[] = [
    { value: 'submitted', label: 'Submitted' },
    { value: 'in-review', label: 'In Review' },
    { value: 'awaiting-feedback', label: 'Awaiting Feedback' },
    { value: 'ready-to-finalize', label: 'Ready' },
    { value: 'parked', label: 'Parked' },
    { value: 'declined', label: 'Declined' },
];

const PRIORITY_OPTIONS: TaskFilterOption[] = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
];

const SORT_BY_OPTIONS: SortByOption[] = [
    { value: 'updated_at', label: 'Updated At' },
    { value: 'created_at', label: 'Created At' },
    { value: 'priority', label: 'Priority' },
];

const TYPE_FILTER_OPTIONS: TaskTypeOption[] = [
    { value: 'all', label: 'All' },
    { value: 'tasks', label: 'Tasks' },
    { value: 'app_errors', label: 'App errors' },
];

const TASK_STATE_ICONS = {
    pending: CircleDashed,
    'in-progress': Clock3,
    'awaiting-feedback': MessageCircle,
    'on-hold': PauseCircle,
    completed: CheckCircle2,
    closed: Archive,
    low: ArrowDown,
    medium: Equal,
    high: ArrowUp,
    submitted: CircleDashed,
    'in-review': Clock3,
    'ready-to-finalize': CheckCircle2,
    parked: PauseCircle,
    declined: CircleX,
    finalized: Archive,
};

export function getTaskStateIcon(value: string) {
    return TASK_STATE_ICONS[value as keyof typeof TASK_STATE_ICONS] ?? Circle;
}

export const DEFAULT_SORT_BY = 'updated_at';
export const DEFAULT_TASK_TYPE_FILTER = 'all';

export function getStatusOptions(options: { includeClosed?: boolean } = {}): TaskFilterOption[] {
    if (options.includeClosed === false) {
        return STATUS_OPTIONS.filter((option) => option.value !== 'closed');
    }

    return [...STATUS_OPTIONS];
}

export function getPriorityOptions(): TaskFilterOption[] {
    return [...PRIORITY_OPTIONS];
}

export function getRequirementStatusOptions(): TaskFilterOption[] {
    return [...REQUIREMENT_STATUS_OPTIONS];
}

export function getSortByOptions(): SortByOption[] {
    return [...SORT_BY_OPTIONS];
}

export function getTaskTypeOptions(): TaskTypeOption[] {
    return [...TYPE_FILTER_OPTIONS];
}

export function getDefaultStatuses(statusOptions: Pick<TaskFilterOption, 'value'>[], excluded: string[] = ['completed', 'closed']): string[] {
    const excludedSet = new Set(excluded);
    return statusOptions.filter((option) => !excludedSet.has(option.value)).map((option) => option.value);
}

export function normalizeStringList(value: unknown): string[] {
    if (Array.isArray(value)) return value.map(String).filter((item) => item.trim().length > 0);
    if (typeof value === 'string' && value.trim().length > 0) return [value.trim()];
    return [];
}

export function getTaskTypeLabel(type?: string | null, label?: string | null): string {
    if (type === 'app_error') {
        return 'error';
    }

    return label?.trim() || 'Task';
}

export function getStatusLabel(value: string, statusOptions: Pick<TaskFilterOption, 'value' | 'label'>[] = STATUS_OPTIONS): string {
    return statusOptions.find((option) => option.value === value)?.label ?? value;
}

export function getRequirementStatusLabel(
    value: string,
    statusOptions: Pick<TaskFilterOption, 'value' | 'label'>[] = REQUIREMENT_STATUS_OPTIONS,
): string {
    if (value === 'finalized') return 'Finalized';

    return statusOptions.find((option) => option.value === value)?.label ?? value;
}

export function getPriorityLabel(value: string, priorityOptions: Pick<TaskFilterOption, 'value' | 'label'>[] = PRIORITY_OPTIONS): string {
    return priorityOptions.find((option) => option.value === value)?.label ?? value;
}
