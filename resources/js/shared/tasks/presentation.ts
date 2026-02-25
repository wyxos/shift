export type TaskFilterOption = {
    value: string;
    label: string;
    selectedClass: string;
    unselectedClass: string;
};

export type SortByOption = {
    value: string;
    label: string;
};

const STATUS_OPTIONS: TaskFilterOption[] = [
    {
        value: 'pending',
        label: 'Pending',
        selectedClass: 'border-amber-300 bg-amber-100 text-amber-900 hover:bg-amber-200',
        unselectedClass: 'border-amber-300/60 text-amber-900 hover:bg-amber-50',
    },
    {
        value: 'in-progress',
        label: 'In Progress',
        selectedClass: 'border-sky-300 bg-sky-100 text-sky-900 hover:bg-sky-200',
        unselectedClass: 'border-sky-300/60 text-sky-900 hover:bg-sky-50',
    },
    {
        value: 'awaiting-feedback',
        label: 'Awaiting Feedback',
        selectedClass: 'border-indigo-300 bg-indigo-100 text-indigo-900 hover:bg-indigo-200',
        unselectedClass: 'border-indigo-300/60 text-indigo-900 hover:bg-indigo-50',
    },
    {
        value: 'completed',
        label: 'Completed',
        selectedClass: 'border-emerald-300 bg-emerald-100 text-emerald-900 hover:bg-emerald-200',
        unselectedClass: 'border-emerald-300/60 text-emerald-900 hover:bg-emerald-50',
    },
    {
        value: 'closed',
        label: 'Closed',
        selectedClass: 'border-slate-300 bg-slate-100 text-slate-700 hover:bg-slate-200',
        unselectedClass: 'border-slate-300/60 text-slate-700 hover:bg-slate-50',
    },
];

const PRIORITY_OPTIONS: TaskFilterOption[] = [
    {
        value: 'low',
        label: 'Low',
        selectedClass: 'border-cyan-300 bg-cyan-100 text-cyan-900 hover:bg-cyan-200',
        unselectedClass: 'border-cyan-300/60 text-cyan-900 hover:bg-cyan-50',
    },
    {
        value: 'medium',
        label: 'Medium',
        selectedClass: 'border-fuchsia-300 bg-fuchsia-100 text-fuchsia-900 hover:bg-fuchsia-200',
        unselectedClass: 'border-fuchsia-300/60 text-fuchsia-900 hover:bg-fuchsia-50',
    },
    {
        value: 'high',
        label: 'High',
        selectedClass: 'border-rose-300 bg-rose-100 text-rose-900 hover:bg-rose-200',
        unselectedClass: 'border-rose-300/60 text-rose-900 hover:bg-rose-50',
    },
];

const SORT_BY_OPTIONS: SortByOption[] = [
    { value: 'updated_at', label: 'Updated At' },
    { value: 'created_at', label: 'Created At' },
    { value: 'priority', label: 'Priority' },
];

const STATUS_BADGE_CLASS_MAP: Record<string, string> = {
    pending: 'border-amber-300 bg-amber-100 text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/20 dark:text-amber-100',
    'in-progress': 'border-sky-300 bg-sky-100 text-sky-900 dark:border-sky-500/40 dark:bg-sky-500/20 dark:text-sky-100',
    'awaiting-feedback': 'border-indigo-300 bg-indigo-100 text-indigo-900 dark:border-indigo-500/40 dark:bg-indigo-500/20 dark:text-indigo-100',
    completed: 'border-emerald-300 bg-emerald-100 text-emerald-900 dark:border-emerald-500/40 dark:bg-emerald-500/20 dark:text-emerald-100',
    closed: 'border-slate-300 bg-slate-100 text-slate-700 dark:border-slate-500/40 dark:bg-slate-500/20 dark:text-slate-200',
};

const PRIORITY_BADGE_CLASS_MAP: Record<string, string> = {
    low: 'border-cyan-300 bg-cyan-100 text-cyan-900 dark:border-cyan-500/40 dark:bg-cyan-500/20 dark:text-cyan-100',
    medium: 'border-fuchsia-300 bg-fuchsia-100 text-fuchsia-900 dark:border-fuchsia-500/40 dark:bg-fuchsia-500/20 dark:text-fuchsia-100',
    high: 'border-rose-300 bg-rose-100 text-rose-900 dark:border-rose-500/40 dark:bg-rose-500/20 dark:text-rose-100',
};

export const DEFAULT_SORT_BY = 'updated_at';

export function getStatusOptions(options: { includeClosed?: boolean } = {}): TaskFilterOption[] {
    if (options.includeClosed === false) {
        return STATUS_OPTIONS.filter((option) => option.value !== 'closed');
    }

    return [...STATUS_OPTIONS];
}

export function getPriorityOptions(): TaskFilterOption[] {
    return [...PRIORITY_OPTIONS];
}

export function getSortByOptions(): SortByOption[] {
    return [...SORT_BY_OPTIONS];
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

export function getStatusBadgeClass(status: string): string {
    return (
        STATUS_BADGE_CLASS_MAP[status] ?? 'border-zinc-300 bg-zinc-100 text-zinc-800 dark:border-zinc-500/40 dark:bg-zinc-500/20 dark:text-zinc-100'
    );
}

export function getPriorityBadgeClass(priority: string): string {
    return (
        PRIORITY_BADGE_CLASS_MAP[priority] ??
        'border-zinc-300 bg-zinc-100 text-zinc-800 dark:border-zinc-500/40 dark:bg-zinc-500/20 dark:text-zinc-100'
    );
}

export function getStatusLabel(value: string, statusOptions: Pick<TaskFilterOption, 'value' | 'label'>[] = STATUS_OPTIONS): string {
    return statusOptions.find((option) => option.value === value)?.label ?? value;
}

export function getPriorityLabel(value: string, priorityOptions: Pick<TaskFilterOption, 'value' | 'label'>[] = PRIORITY_OPTIONS): string {
    return priorityOptions.find((option) => option.value === value)?.label ?? value;
}
