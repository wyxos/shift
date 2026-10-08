<script setup lang="ts">
import { Button } from '@/components/ui/button';
import type { TaskErrorOccurrence, TaskErrorStackFrame } from '@/shared/tasks/types';
import { ChevronDown } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    state: any;
}>();

const state = props.state;
const expandedOccurrenceId = ref<number | null>(null);
const expandedFrameKeys = ref<Set<string>>(new Set());
// The API paginates by received_at DESC, id DESC. Preserve its order so page boundaries stay consistent.
const occurrences = computed<TaskErrorOccurrence[]>(() => (Array.isArray(state.errorOccurrences) ? state.errorOccurrences : []));
const occurrencePagination = computed(() => state.errorOccurrencesPagination ?? null);
const occurrenceRangeLabel = computed(() => {
    const pagination = occurrencePagination.value;

    if (!pagination || pagination.total === 0) {
        return 'No events';
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

const toggleOccurrence = (occurrence: TaskErrorOccurrence) => {
    expandedOccurrenceId.value = expandedOccurrenceId.value === occurrence.id ? null : occurrence.id;
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
            expandedOccurrenceId.value = null;
            return;
        }

        if (!occurrences.value.some((occurrence) => occurrence.id === expandedOccurrenceId.value)) {
            expandedOccurrenceId.value = occurrences.value[0].id;
        }
    },
    { immediate: true },
);
</script>

<template>
    <div class="shift-scrollbar flex-1 overflow-auto px-4 py-4" data-testid="error-occurrences-panel">
        <div v-if="state.errorOccurrencesLoading" class="text-muted-foreground py-6 text-center text-sm">Loading events...</div>
        <div v-else-if="state.errorOccurrencesError" class="text-destructive py-6 text-center text-sm">{{ state.errorOccurrencesError }}</div>
        <div v-else-if="occurrences.length === 0" class="text-muted-foreground py-6 text-center text-sm">No events recorded.</div>
        <div v-else class="space-y-5">
            <div class="text-muted-foreground text-xs font-medium" data-testid="error-events-sort">Newest first</div>
            <div class="space-y-2" data-testid="error-events-list">
                <div
                    v-for="(occurrence, index) in occurrences"
                    :key="occurrence.id"
                    class="border-border/60 bg-background/30 overflow-hidden rounded-lg border transition-colors duration-150 motion-reduce:transition-none"
                    :class="expandedOccurrenceId === occurrence.id ? 'border-border bg-muted/20' : 'hover:bg-muted/10'"
                    :data-testid="`error-occurrence-row-${occurrence.id}`"
                >
                    <button
                        :id="`error-event-trigger-${occurrence.id}`"
                        :aria-controls="`error-event-details-${occurrence.id}`"
                        :aria-expanded="expandedOccurrenceId === occurrence.id"
                        class="focus-visible:ring-ring flex w-full items-start gap-3 px-4 py-3 text-left transition-colors duration-150 focus-visible:ring-2 focus-visible:outline-none focus-visible:ring-inset motion-reduce:transition-none sm:gap-4 sm:px-5"
                        type="button"
                        @click="toggleOccurrence(occurrence)"
                    >
                        <span class="mt-0.5 flex shrink-0 flex-col items-start gap-1">
                            <span class="bg-muted text-muted-foreground rounded px-2 py-1 text-xs font-medium">Event #{{ occurrence.number }}</span>
                            <span
                                v-if="index === 0 && (!occurrencePagination || occurrencePagination.current_page === 1)"
                                class="bg-primary/10 text-primary rounded px-2 py-0.5 text-[11px] font-medium"
                                data-testid="error-event-latest"
                            >
                                Latest
                            </span>
                        </span>
                        <span class="min-w-0 flex-1 space-y-1">
                            <span class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                <span class="text-foreground min-w-0 text-sm font-semibold break-all">{{ occurrenceName(occurrence) }}</span>
                                <span class="text-muted-foreground shrink-0 text-xs">{{
                                    formatTime(occurrence.received_at || occurrence.created_at)
                                }}</span>
                            </span>
                            <span class="text-muted-foreground line-clamp-2 block text-xs break-all">{{
                                occurrence.message || 'No message captured.'
                            }}</span>
                            <span class="text-muted-foreground block truncate font-mono text-[11px]" :title="sourceLocationLabel(occurrence)">
                                {{ sourceLocationLabel(occurrence) }}
                            </span>
                        </span>
                        <ChevronDown
                            aria-hidden="true"
                            class="text-muted-foreground mt-1 size-4 shrink-0 transition-transform duration-200 motion-reduce:transition-none"
                            :class="expandedOccurrenceId === occurrence.id ? 'rotate-180' : ''"
                        />
                    </button>
                    <Transition name="event-details">
                        <div
                            v-if="expandedOccurrenceId === occurrence.id"
                            :id="`error-event-details-${occurrence.id}`"
                            :aria-labelledby="`error-event-trigger-${occurrence.id}`"
                            role="region"
                        >
                            <div class="border-border/50 space-y-5 border-t px-4 py-5 sm:px-5" data-testid="error-occurrence-stack">
                                <div class="border-border/50 flex min-w-0 flex-col gap-3 border-b pb-4 md:flex-row md:items-start md:justify-between">
                                    <p class="text-foreground min-w-0 text-sm break-all">{{ occurrence.message || 'No message captured.' }}</p>
                                    <div class="flex min-w-0 flex-wrap gap-2 text-xs md:shrink-0">
                                        <span class="bg-muted text-muted-foreground rounded px-2 py-1" data-testid="error-occurrence-source-badge">
                                            {{ occurrence.source }}
                                        </span>
                                        <span
                                            v-if="occurrence.git_sha || occurrence.release"
                                            class="bg-muted text-muted-foreground max-w-full rounded px-2 py-1 break-all"
                                        >
                                            {{ occurrence.git_sha || occurrence.release }}
                                        </span>
                                    </div>
                                </div>

                                <div class="grid gap-5 py-1 lg:grid-cols-2">
                                    <div class="min-w-0 space-y-1">
                                        <div class="text-muted-foreground text-xs font-medium">Source</div>
                                        <div class="text-foreground font-mono text-xs break-all">{{ sourceLocationLabel(occurrence) }}</div>
                                    </div>
                                    <div class="min-w-0 space-y-2" data-testid="error-occurrence-request-details">
                                        <div class="text-muted-foreground text-xs font-medium">Request</div>
                                        <div class="text-foreground text-xs break-all">{{ requestLabel(occurrence) }}</div>
                                        <div v-if="occurrence.request?.referrer" class="text-muted-foreground text-xs break-all">
                                            Referrer: {{ occurrence.request.referrer }}
                                        </div>
                                        <details v-if="hasObjectValues(occurrence.request?.query)" class="min-w-0 space-y-1">
                                            <summary
                                                class="text-muted-foreground focus-visible:ring-ring cursor-pointer rounded text-xs font-medium focus-visible:ring-2 focus-visible:outline-none"
                                            >
                                                Query
                                            </summary>
                                            <pre
                                                class="shift-scrollbar bg-muted/40 text-foreground max-h-44 max-w-full overflow-auto rounded-md p-3 text-xs"
                                                >{{ formatJson(occurrence.request?.query) }}</pre>
                                        </details>
                                        <details v-if="hasObjectValues(occurrence.request?.body)" class="min-w-0 space-y-1">
                                            <summary
                                                class="text-muted-foreground focus-visible:ring-ring cursor-pointer rounded text-xs font-medium focus-visible:ring-2 focus-visible:outline-none"
                                            >
                                                Body
                                            </summary>
                                            <pre
                                                class="shift-scrollbar bg-muted/40 text-foreground max-h-44 max-w-full overflow-auto rounded-md p-3 text-xs"
                                                >{{ formatJson(occurrence.request?.body) }}</pre>
                                        </details>
                                    </div>
                                </div>

                                <div>
                                    <div class="text-foreground mb-2 text-sm font-semibold">Stack trace</div>
                                    <ol v-if="stackFrames(occurrence).length" class="border-muted-foreground/10 overflow-hidden rounded-md border">
                                        <li
                                            v-for="(frame, index) in stackFrames(occurrence)"
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
                                                    class="text-muted-foreground hover:text-foreground focus-visible:ring-ring mt-2 rounded text-left text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none"
                                                    :data-testid="`error-stack-frame-context-${index}`"
                                                    type="button"
                                                    @click="toggleFrameContext(occurrence, index)"
                                                >
                                                    {{ isFrameContextExpanded(occurrence, index) ? 'Hide source context' : 'Show source context' }}
                                                </button>
                                                <div
                                                    v-if="hasFrameContext(frame) && isFrameContextExpanded(occurrence, index)"
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
                                    <div v-else class="text-muted-foreground rounded-md border border-dashed p-3 text-sm">
                                        No stack frames captured.
                                    </div>
                                </div>

                                <details class="mt-4">
                                    <summary
                                        class="text-muted-foreground focus-visible:ring-ring cursor-pointer rounded text-xs font-medium focus-visible:ring-2 focus-visible:outline-none"
                                    >
                                        Raw event payload
                                    </summary>
                                    <pre
                                        class="shift-scrollbar bg-muted/40 text-foreground mt-2 max-h-72 max-w-full overflow-auto rounded-md p-3 text-xs"
                                        >{{ formatJson(occurrence.payload) }}</pre>
                                </details>
                            </div>
                        </div>
                    </Transition>
                </div>
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
        </div>
    </div>
</template>

<style scoped>
.event-details-enter-active,
.event-details-leave-active {
    transition:
        opacity 150ms ease,
        transform 150ms ease;
}

.event-details-enter-from,
.event-details-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}

@media (prefers-reduced-motion: reduce) {
    .event-details-enter-active,
    .event-details-leave-active {
        transition-duration: 0.01ms;
    }
}
</style>
