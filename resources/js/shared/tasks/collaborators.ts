export type CollaboratorOption = {
    id: number | string;
    name: string;
    email: string | null;
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

export function collaboratorsEqual(left?: Partial<TaskCollaboratorSelection> | null, right?: Partial<TaskCollaboratorSelection> | null): boolean {
    const leftNormalized = normalizeTaskCollaborators(left);
    const rightNormalized = normalizeTaskCollaborators(right);

    const compare = (first: CollaboratorOption[], second: CollaboratorOption[]) => {
        const firstKeys = first.map((item) => collaboratorKey(item.id)).sort();
        const secondKeys = second.map((item) => collaboratorKey(item.id)).sort();

        return JSON.stringify(firstKeys) === JSON.stringify(secondKeys);
    };

    return compare(leftNormalized.internal, rightNormalized.internal) && compare(leftNormalized.external, rightNormalized.external);
}
