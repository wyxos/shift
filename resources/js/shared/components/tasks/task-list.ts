export type TaskListBatch = {
    id: number;
    title?: string | null;
    created_at?: string | null;
    total_items: number;
    requirement_items: number;
    ready_items?: number;
    finalized_items: number;
    can_finalize_requirement?: boolean;
};

export type TaskListRow = {
    id: number;
    project_id?: number | null;
    project?: {
        id: number;
        name: string;
    } | null;
    title: string;
    type?: string | null;
    type_label?: string | null;
    status: string;
    requirement_status?: string | null;
    priority: string;
    phase?: string | null;
    finalized?: boolean | null;
    finalized_at?: string | null;
    environment?: string | null;
    created_at?: string | null;
    updated_at?: string | null;
    batch_id?: number | null;
    batch_title?: string | null;
    batch?: TaskListBatch | null;
    can_delete?: boolean;
    can_finalize_requirement?: boolean;
};

export type RequirementGroup = {
    key: string;
    batch: TaskListRow['batch'];
    tasks: TaskListRow[];
};

export function groupRequirementTasks(tasks: TaskListRow[], itemLabel: string) {
    if (itemLabel !== 'requirements') return [];

    const groups = new Map<string, RequirementGroup>();

    tasks.forEach((task) => {
        const batch = taskBatch(task);
        const key = batch?.id ? `batch-${batch.id}` : 'ungrouped';
        const existing = groups.get(key);

        if (existing) {
            existing.tasks.push(task);
            return;
        }

        groups.set(key, { key, batch, tasks: [task] });
    });

    return Array.from(groups.values());
}

export function taskProjectLabel(task: TaskListRow) {
    const name = task.project?.name?.trim() || '';
    if (!name) return null;

    return name.replace(/^Requirement\s+/i, '').trim() || name;
}

export function requirementPackTitle(batch: TaskListRow['batch']) {
    if (batch?.title) return batch.title;
    if (batch?.id) return `Requirement group #${batch.id}`;
    return 'Ungrouped requirements';
}

export function requirementPackMeta(batch: TaskListRow['batch'], tasks: TaskListRow[]) {
    const total = batch?.total_items || tasks.length;
    const pending = batch?.requirement_items || tasks.filter((task) => task.phase === 'requirement').length;
    const ready = batch?.ready_items ?? tasks.filter((task) => task.requirement_status === 'ready-to-finalize').length;
    const finalized = batch?.finalized_items ?? Math.max(total - pending, 0);

    return `${total} ${total === 1 ? 'item' : 'items'} · ${pending} open · ${ready} ready · ${finalized} finalized`;
}

export function requirementState(task: TaskListRow) {
    if (isFinalizedRequirement(task)) return 'finalized';

    return task.requirement_status || 'submitted';
}

export function canDeleteTask(task: TaskListRow) {
    return task.can_delete === true;
}

export function canFinalizeRequirementPack(group: RequirementGroup) {
    if (!group.batch?.id || (group.batch.ready_items ?? 0) <= 0) return false;
    if (typeof group.batch.can_finalize_requirement === 'boolean') return group.batch.can_finalize_requirement;

    const openRequirements = group.tasks.filter((task) => !isFinalizedRequirement(task));

    return openRequirements.length > 0 && openRequirements.every(canFinalizeRequirement);
}

export function requirementPackId(group: Pick<RequirementGroup, 'batch'>) {
    return group.batch?.id ?? null;
}

function taskBatch(task: TaskListRow) {
    if (task.batch) return task.batch;
    if (!task.batch_id) return null;

    return {
        id: task.batch_id,
        title: task.batch_title ?? null,
        created_at: null,
        total_items: 0,
        requirement_items: 0,
        ready_items: 0,
        finalized_items: 0,
    };
}

function isFinalizedRequirement(task: TaskListRow) {
    return task.finalized === true || (task.phase !== undefined && task.phase !== null && task.phase !== 'requirement');
}

function canFinalizeRequirement(task: TaskListRow) {
    return task.can_finalize_requirement === true && task.requirement_status === 'ready-to-finalize';
}
