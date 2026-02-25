export type HistoryMode = 'push' | 'replace';

export function getTaskIdFromQuery(taskQueryParam: string = 'task'): number | null {
    if (typeof window === 'undefined') return null;
    const raw = new URLSearchParams(window.location.search).get(taskQueryParam);
    if (!raw) return null;
    const taskId = Number.parseInt(raw, 10);
    return Number.isFinite(taskId) && taskId > 0 ? taskId : null;
}

export function syncTaskQuery(taskId: number | null, mode: HistoryMode = 'push', taskQueryParam: string = 'task'): void {
    if (typeof window === 'undefined') return;
    const url = new URL(window.location.href);
    if (taskId === null) {
        url.searchParams.delete(taskQueryParam);
    } else {
        url.searchParams.set(taskQueryParam, String(taskId));
    }
    const next = `${url.pathname}${url.search}${url.hash}`;
    const current = `${window.location.pathname}${window.location.search}${window.location.hash}`;
    if (next === current) return;
    const historyMethod = mode === 'replace' ? 'replaceState' : 'pushState';
    window.history[historyMethod](window.history.state, '', next);
}
