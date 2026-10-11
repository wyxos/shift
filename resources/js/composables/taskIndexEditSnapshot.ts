import { collaboratorsEqual } from '@/shared/tasks/collaborators';
import type { TaskDetail, TaskIndexEditSnapshot } from '@/shared/tasks/types';

export function currentTaskSnapshot(form: TaskIndexEditSnapshot): TaskIndexEditSnapshot {
    return {
        title: form.title,
        priority: form.priority,
        status: form.status,
        requirement_status: form.requirement_status,
        description: form.description,
        environment: form.environment,
        collaborators: {
            internal: form.collaborators.internal.map((collaborator) => ({ ...collaborator })),
            external: form.collaborators.external.map((collaborator) => ({ ...collaborator })),
        },
    };
}

export function hasPendingTaskChanges(form: TaskIndexEditSnapshot, snapshot: TaskIndexEditSnapshot | null, deletedAttachmentIds: number[]) {
    if (!snapshot) return false;
    if (form.title !== snapshot.title) return true;
    if (form.priority !== snapshot.priority) return true;
    if (form.status !== snapshot.status) return true;
    if (form.requirement_status !== snapshot.requirement_status) return true;
    if ((form.description ?? '') !== (snapshot.description ?? '')) return true;
    if ((form.environment ?? '') !== (snapshot.environment ?? '')) return true;
    if (!collaboratorsEqual(form.collaborators, snapshot.collaborators)) return true;
    return deletedAttachmentIds.length > 0;
}

export function hasCollaboratorManagementChanges(form: TaskIndexEditSnapshot, snapshot: TaskIndexEditSnapshot | null) {
    if (!snapshot) return false;
    if ((form.environment ?? '') !== (snapshot.environment ?? '')) return true;
    return !collaboratorsEqual(form.collaborators, snapshot.collaborators);
}

export function withTaskSnapshot(task: TaskDetail, snapshot: TaskIndexEditSnapshot): TaskDetail {
    return {
        ...task,
        title: snapshot.title,
        status: snapshot.status,
        requirement_status: snapshot.requirement_status,
        priority: snapshot.priority,
        environment: snapshot.environment,
    };
}
