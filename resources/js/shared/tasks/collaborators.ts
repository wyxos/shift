export type CollaboratorOption = {
    id: number | string;
    name: string;
    email: string | null;
    environment?: string | null;
};

export type TaskCollaboratorSelection = {
    internal: CollaboratorOption[];
    external: CollaboratorOption[];
};

export function emptyTaskCollaborators(): TaskCollaboratorSelection {
    return {
        internal: [],
        external: [],
    };
}

export function normalizeTaskCollaborators(value?: Partial<TaskCollaboratorSelection> | null): TaskCollaboratorSelection {
    return {
        internal: Array.isArray(value?.internal) ? [...value.internal] : [],
        external: Array.isArray(value?.external) ? [...value.external] : [],
    };
}

export function collaboratorKey(id: number | string): string {
    return String(id);
}

export function externalCollaboratorKey(collaborator: CollaboratorOption): string {
    return JSON.stringify([collaborator.environment ?? null, collaboratorKey(collaborator.id)]);
}

export function collaboratorsEqual(left?: Partial<TaskCollaboratorSelection> | null, right?: Partial<TaskCollaboratorSelection> | null): boolean {
    const leftNormalized = normalizeTaskCollaborators(left);
    const rightNormalized = normalizeTaskCollaborators(right);

    const compare = (first: CollaboratorOption[], second: CollaboratorOption[], key: (item: CollaboratorOption) => string) => {
        const firstKeys = first.map(key).sort();
        const secondKeys = second.map(key).sort();

        return JSON.stringify(firstKeys) === JSON.stringify(secondKeys);
    };

    return (
        compare(leftNormalized.internal, rightNormalized.internal, (item) => collaboratorKey(item.id)) &&
        compare(leftNormalized.external, rightNormalized.external, externalCollaboratorKey)
    );
}
