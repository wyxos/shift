export type ProjectEnvironmentOption = {
    key: string;
    label: string;
    url: string;
};

export type TaskProjectOption = {
    id: number;
    name: string;
    environments: ProjectEnvironmentOption[];
};

export function projectEnvironmentOptions(projects: TaskProjectOption[], projectId: number | null | undefined): ProjectEnvironmentOption[] {
    if (projectId === null || projectId === undefined) {
        return [];
    }

    return projects.find((project) => project.id === projectId)?.environments ?? [];
}
