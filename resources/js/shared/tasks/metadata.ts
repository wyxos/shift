export function pickFirstNonEmptyString(candidates: unknown[]): string | null {
    for (const value of candidates) {
        if (typeof value === 'string' && value.trim()) return value.trim();
    }
    return null;
}

export function getTaskCreatorEmail(task: any): string | null {
    return pickFirstNonEmptyString([
        task?.submitter?.email,
        task?.submitter_email,
        task?.creator?.email,
        task?.creator_email,
        task?.created_by?.email,
        task?.created_by_email,
        task?.user?.email,
        task?.user_email,
    ]);
}

export function getTaskCreatorName(task: any): string | null {
    return pickFirstNonEmptyString([
        task?.submitter?.name,
        task?.submitter_name,
        task?.creator?.name,
        task?.creator_name,
        task?.created_by?.name,
        task?.created_by_name,
        task?.user?.name,
        task?.user_name,
    ]);
}

export function formatEnvironmentLabel(value: string): string {
    const trimmed = value.trim();
    if (!trimmed) return '';
    if (/^[a-z0-9_-]+$/.test(trimmed)) {
        return trimmed
            .replace(/[_-]+/g, ' ')
            .replace(/\s+/g, ' ')
            .replace(/\b\w/g, (letter) => letter.toUpperCase());
    }
    return trimmed;
}

export function getTaskEnvironment(task: any): string | null {
    const environment = pickFirstNonEmptyString([
        task?.environment,
        task?.task_environment,
        task?.target_environment,
        task?.for_environment,
        task?.metadata?.environment,
        task?.submitter?.environment,
        task?.creator?.environment,
        task?.created_by?.environment,
        task?.user?.environment,
    ]);
    if (!environment) return null;
    const label = formatEnvironmentLabel(environment);
    return label || null;
}
