import { emptyTaskCollaborators } from '@/shared/tasks/collaborators';

export const editMobilePaneOptions = [
    { value: 'details', label: 'Details' },
    { value: 'comments', label: 'Comments' },
];

export function defaultTaskEditForm() {
    return {
        title: '',
        priority: 'medium',
        status: 'pending',
        description: '',
        environment: null as string | null,
        collaborators: emptyTaskCollaborators(),
    };
}
