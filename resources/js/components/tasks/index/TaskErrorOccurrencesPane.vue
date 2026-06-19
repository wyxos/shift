<script setup lang="ts">
import { Button } from '@/components/ui/button';
import type { TaskErrorOccurrence, TaskErrorStackFrame } from '@/shared/tasks/types';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    state: any;
}>();

const state = props.state;
const selectedOccurrenceId = ref<number | null>(null);
const expandedFrameKeys = ref<Set<string>>(new Set());
const occurrences = computed<TaskErrorOccurrence[]>(() => (Array.isArray(state.errorOccurrences) ? state.errorOccurrences : []));
const occurrencePagination = computed(() => state.errorOccurrencesPagination ?? null);
const selectedOccurrence = computed<TaskErrorOccurrence | null>(() => {
    if (occurrences.value.length === 0) return null;

    return occurrences.value.find((occurrence) => occurrence.id === selectedOccurrenceId.value) ?? occurrences.value[0];
});
const occurrenceRangeLabel = computed(() => {
    const pagination = occurrencePagination.value;

    if (!pagination || pagination.total === 0) {
        return 'No occurrences';
    }

    return `Showing ${pagination.from ?? 0}-${pagination.to ?? 0} of ${pagination.total}`;
});
const canGoToPreviousOccurrences = computed(() => {
    const pagination = occurrencePagination.value;

    return Boolean(pagination && pagination.current_page > 1);
});
const canGoToNextOccurrences = computed(() => {
    const pagination = occurrencePagination.value;

    return Boolean(pagination && pagination.current_page < pagination.last_page);
});

const selectOccurrence = (occurrence: TaskErrorOccurrence) => {
    selectedOccurrenceId.value = occurrence.id;
};

const fetchOccurrencePage = (page: number) => {
    const taskId = state.editTask?.id;

    if (!taskId || state.errorOccurrencesLoading) {
        return;
    }

    void state.fetchErrorOccurrences(taskId, page);
};

const occurrenceName = (occurrence: TaskErrorOccurrence | null) => {
    const name = occurrence?.exception_class ?? occurrence?.error_name ?? 'Error';
    const parts = name.split('\\');

    return parts[parts.length - 1] || name;
};

const formatTime = (value?: string | null) => {
    if (!value) return 'Unknown';

    return value.replace('T', ' ').slice(0, 16);
};

const sourceLocationLabel = (occurrence: TaskErrorOccurrence | null) => {
    const file = occurrence?.culprit?.file;

    if (!file) return 'Unknown';

    return `${file}${occurrence?.culprit?.line ? `:${occurrence.culprit.line}` : ''}`;
};

const requestLabel = (occurrence: TaskErrorOccurrence) => {
    const method = occurrence.request?.method;
    const url = occurrence.request?.url;

    return [method, url].filter(Boolean).join(' ') || 'Unknown';
};

const hasObjectValues = (value: unknown) => {
    return typeof value === 'object' && value !== null && Object.keys(value as Record<string, unknown>).length > 0;
};

const stackFrames = (occurrence: TaskErrorOccurrence | null): TaskErrorStackFrame[] => {
    const frames = occurrence?.stacktrace?.frames;

    return Array.isArray(frames) ? frames : [];
};

const frameLocation = (frame: TaskErrorStackFrame) => {
    const file = frame.file || '[unknown file]';

    return `${file}${frame.line ? `:${frame.line}` : ''}`;
};

const frameContextLines = (frame: TaskErrorStackFrame) => {
    const lines = frame.context?.lines;

    return Array.isArray(lines) ? lines : [];
};

const hasFrameContext = (frame: TaskErrorStackFrame) => frameContextLines(frame).length > 0;

const frameExpansionKey = (occurrence: TaskErrorOccurrence | null, index: number) => `${occurrence?.id ?? 'none'}-${index}`;

const isFrameContextExpanded = (occurrence: TaskErrorOccurrence | null, index: number) => {
    return expandedFrameKeys.value.has(frameExpansionKey(occurrence, index));
};

const toggleFrameContext = (occurrence: TaskErrorOccurrence | null, index: number) => {
    const key = frameExpansionKey(occurrence, index);
    const next = new Set(expandedFrameKeys.value);

    if (next.has(key)) {
        next.delete(key);
    } else {
        next.add(key);
    }

    expandedFrameKeys.value = next;
};

const formatJson = (value: unknown) => {
    if (!hasObjectValues(value)) {
        return '{}';
    }

    return JSON.stringify(value, null, 2);
};

watch(
    () => occurrences.value.map((occurrence) => occurrence.id).join(','),
    () => {
        if (occurrences.value.length === 0) {
            selectedOccurrenceId.value = null;
            return;
        }

        if (!occurrences.value.some((occurrence) => occurrence.id === selectedOccurrenceId.value)) {
            selectedOccurrenceId.value = occurrences.value[0].id;
        }
    },
    { immediate: true },
);
</script>

<template>
    <div class="shift-scrollbar flex-1 overflow-auto px-4 py-4" data-testid="error-occurrences-panel">
        <div v-if="state.errorOccurrencesLoading" class="text-muted-foreground py-6 text-center text-sm">Loading occurrences...</div>
        <div v-else-if="state.errorOccurrencesError" class="text-destructive py-6 text-center text-sm">{{ state.errorOccurrencesError }}</div>
        <div v-else-if="occurrences.length === 0" class="text-muted-foreground py-6 text-center text-sm">No occurrences recorded.</div>
        <div v-else class="space-y-5">
            <div class="border-muted-foreground/10 overflow-hidden rounded-md border" data-testid="error-occurrences-table">
                <table class="w-full text-sm">
                    <thead class="bg-muted/30 text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium">Occurrence</th>
                            <th class="hidden px-3 py-2 text-left font-medium md:table-cell">Event</th>
                            <th class="hidden px-3 py-2 text-left font-medium lg:table-cell">Source</th>
                            <th class="px-3 py-2 text-left font-medium">Seen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="occurrence in occurrences"
                            :key="occurrence.id"
                            :aria-selected="selectedOccurrence?.id === occurrence.id"
                            :class="selectedOccurrence?.id === occurrence.id ? 'bg-muted/40' : 'hover:bg-muted/20'"
                            :data-testid="`error-occurrence-row-${occurrence.id}`"
                            class="border-muted-foreground/10 focus-visible:ring-ring cursor-pointer border-t transition-colors focus-visible:ring-2 focus-visible:outline-none focus-visible:ring-inset"
                            role="button"
                            tabindex="0"
                            @click="selectOccurrence(occurrence)"
                            @keydown.enter.prevent="selectOccurrence(occurrence)"
                            @keydown.space.prevent="selectOccurrence(occurrence)"
                        >
                            <td class="px-3 py-2 align-top">
                                <span class="text-foreground block text-left text-sm font-medium"> Occurrence #{{ occurrence.number }} </span>
                                <div class="text-muted-foreground mt-1 text-xs md:hidden">
                                    {{ occurrenceName(occurrence) }}
                                </div>
                            </td>
                            <td class="hidden px-3 py-2 align-top md:table-cell">
                                <div class="text-foreground font-medium">{{ occurrenceName(occurrence) }}</div>
                                <div class="text-muted-foreground mt-1 line-clamp-2 text-xs">{{ occurrence.message || 'No message' }}</div>
                            </td>
                            <td class="hidden max-w-[18rem] px-3 py-2 align-top lg:table-cell">
                                <div class="text-muted-foreground truncate font-mono text-xs">{{ sourceLocationLabel(occurrence) }}</div>
                            </td>
                            <td class="px-3 py-2 align-top">
                                <div class="text-muted-foreground text-xs">{{ formatTime(occurrence.received_at || occurrence.created_at) }}</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="occurrencePagination && occurrencePagination.last_page > 1"
                class="text-muted-foreground flex flex-col gap-2 text-xs sm:flex-row sm:items-center sm:justify-between"
            >
                <span data-testid="error-occurrences-range">{{ occurrenceRangeLabel }}</span>
                <div class="flex items-center gap-2">
                    <Button
                        data-testid="error-occurrences-previous"
                        size="sm"
                        type="button"
                        variant="outline"
                        :disabled="!canGoToPreviousOccurrences || state.errorOccurrencesLoading"
                        @click="fetchOccurrencePage(occurrencePagination.current_page - 1)"
                    >
                        Previous
                    </Button>
                    <Button
                        data-testid="error-occurrences-next"
                        size="sm"
                        type="button"
                        variant="outline"
                        :disabled="!canGoToNextOccurrences || state.errorOccurrencesLoading"
                        @click="fetchOccurrencePage(occurrencePagination.current_page + 1)"
                    >
                        Next
                    </Button>
                </div>
            </div>

            <div v-if="selectedOccurrence" class="bg-background/25 space-y-4 rounded-md px-1 py-1" data-testid="error-occurrence-stack">
                <div class="flex flex-col gap-3 border-b pb-4 md:flex-row md:items-start md:justify-between">
                    <div class="min-w-0">
                        <div class="text-foreground text-sm font-semibold">
                            Occurrence #{{ selectedOccurrence.number }} · {{ occurrenceName(selectedOccurrence) }}
                        </div>
                        <p class="text-muted-foreground mt-1 text-sm">{{ selectedOccurrence.message || 'No message captured.' }}</p>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-2 text-xs">
                        <span class="bg-muted text-muted-foreground rounded px-2 py-1" data-testid="error-occurrence-source-badge">
                            {{ selectedOccurrence.source }}
                        </span>
                        <span
                            v-if="selectedOccurrence.git_sha || selectedOccurrence.release"
                            class="bg-muted text-muted-foreground rounded px-2 py-1"
                        >
                            {{ selectedOccurrence.git_sha || selectedOccurrence.release }}
                        </span>
                    </div>
                </div>

                <div class="grid gap-4 py-4 lg:grid-cols-2">
                    <div class="space-y-1">
                        <div class="text-muted-foreground text-xs font-medium">Source</div>
                        <div class="text-foreground font-mono text-xs break-all">{{ sourceLocationLabel(selectedOccurrence) }}</div>
                    </div>
                    <div class="space-y-2" data-testid="error-occurrence-request-details">
                        <div class="text-muted-foreground text-xs font-medium">Request</div>
                        <div class="text-foreground text-xs break-all">{{ requestLabel(selectedOccurrence) }}</div>
                        <div v-if="selectedOccurrence.request?.referrer" class="text-muted-foreground text-xs break-all">
                            Referrer: {{ selectedOccurrence.request.referrer }}
                        </div>
                        <div v-if="hasObjectValues(selectedOccurrence.request?.query)" class="space-y-1">
                            <div class="text-muted-foreground text-xs font-medium">Query</div>
                            <pre class="shift-scrollbar bg-muted/40 text-foreground max-h-44 overflow-auto rounded-md p-3 text-xs">{{
                                formatJson(selectedOccurrence.request?.query)
                            }}</pre>
                        </div>
                        <div v-if="hasObjectValues(selectedOccurrence.request?.body)" class="space-y-1">
                            <div class="text-muted-foreground text-xs font-medium">Body</div>
                            <pre class="shift-scrollbar bg-muted/40 text-foreground max-h-44 overflow-auto rounded-md p-3 text-xs">{{
                                formatJson(selectedOccurrence.request?.body)
                            }}</pre>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="text-foreground mb-2 text-sm font-semibold">Stack trace</div>
                    <ol v-if="stackFrames(selectedOccurrence).length" class="border-muted-foreground/10 overflow-hidden rounded-md border">
                        <li
                            v-for="(frame, index) in stackFrames(selectedOccurrence)"
                            :key="`${frame.file}-${frame.line}-${frame.function}-${index}`"
                            class="border-muted-foreground/10 grid gap-2 border-t px-3 py-2 first:border-t-0 md:grid-cols-[minmax(0,1fr)_auto]"
                        >
                            <div class="min-w-0">
                                <div class="text-foreground truncate font-mono text-xs">
                                    {{ frame.function || '(anonymous)' }}
                                </div>
                                <div class="text-muted-foreground mt-1 font-mono text-xs break-all">{{ frameLocation(frame) }}</div>
                                <button
                                    v-if="hasFrameContext(frame)"
                                    class="text-muted-foreground hover:text-foreground mt-2 text-left text-xs font-medium"
                                    :data-testid="`error-stack-frame-context-${index}`"
                                    type="button"
                                    @click="toggleFrameContext(selectedOccurrence, index)"
                                >
                                    {{ isFrameContextExpanded(selectedOccurrence, index) ? 'Hide source context' : 'Show source context' }}
                                </button>
                                <div
                                    v-if="hasFrameContext(frame) && isFrameContextExpanded(selectedOccurrence, index)"
                                    class="shift-scrollbar border-muted-foreground/10 bg-muted/25 mt-2 max-h-80 overflow-auto rounded-md border py-2 font-mono text-xs"
                                    :data-testid="`error-stack-frame-context-lines-${index}`"
                                >
                                    <div
                                        v-for="line in frameContextLines(frame)"
                                        :key="line.number"
                                        :class="line.active ? 'bg-primary/10 text-foreground' : 'text-muted-foreground'"
                                        class="grid grid-cols-[4rem_minmax(0,1fr)] gap-3 px-3 py-0.5"
                                    >
                                        <span class="text-right select-none">{{ line.number }}</span>
                                        <code class="min-w-0 break-words whitespace-pre-wrap">{{ line.text || ' ' }}</code>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-start gap-2">
                                <span
                                    v-if="frame.in_app"
                                    class="rounded bg-emerald-100 px-2 py-0.5 text-[11px] text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-100"
                                >
                                    in app
                                </span>
                                <span class="text-muted-foreground font-mono text-[11px]">#{{ index + 1 }}</span>
                            </div>
                        </li>
                    </ol>
                    <div v-else class="text-muted-foreground rounded-md border border-dashed p-3 text-sm">No stack frames captured.</div>
                </div>

                <details class="mt-4">
                    <summary class="text-muted-foreground cursor-pointer text-xs font-medium">Raw occurrence payload</summary>
                    <pre class="shift-scrollbar bg-muted/40 text-foreground mt-2 max-h-72 overflow-auto rounded-md p-3 text-xs">{{
                        formatJson(selectedOccurrence.payload)
                    }}</pre>
                </details>
            </div>
        </div>
    </div>
</template>
