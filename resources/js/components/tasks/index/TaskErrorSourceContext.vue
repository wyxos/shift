<script setup lang="ts">
import type { TaskErrorStackFrame } from '@/shared/tasks/types';
import { highlightSource } from '@shared/tasks/source-highlighting';
import { computed } from 'vue';

const props = defineProps<{ frame: TaskErrorStackFrame }>();
const sourceLines = computed(() => props.frame.context?.lines ?? []);
const highlighted = computed(() =>
    highlightSource(
        props.frame.file,
        sourceLines.value.map((line) => line.text),
    ),
);
</script>

<template>
    <div class="source-context border-muted-foreground/10 bg-muted/25 mt-2 min-w-0 overflow-hidden rounded-md border">
        <div class="border-muted-foreground/10 text-muted-foreground border-b px-3 py-2 text-xs font-medium" data-testid="error-source-language">
            {{ highlighted.language }}
        </div>
        <div class="shift-scrollbar max-h-80 overflow-auto py-2 font-mono text-xs leading-relaxed">
            <div
                v-for="(line, index) in sourceLines"
                :key="`${line.number}-${index}`"
                :class="line.active ? 'bg-primary/10 text-foreground' : 'text-muted-foreground'"
                :data-source-line="line.number"
                :data-active="line.active || undefined"
                class="grid grid-cols-[2.5rem_minmax(0,1fr)] gap-2 px-3 py-0.5"
            >
                <span aria-hidden="true" class="text-muted-foreground text-right select-none">{{ line.number }}</span>
                <code class="text-foreground min-w-0 [overflow-wrap:anywhere] whitespace-pre-wrap [tab-size:4]"
                    ><template v-for="(token, tokenIndex) in highlighted.lines[index]" :key="tokenIndex"
                        ><span :class="token.classes">{{ token.text }}</span></template
                    ><template v-if="!line.text"> </template
                ></code>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Reuse the shared editor's GitHub syntax palette without changing other code blocks. */
.source-context {
    --syntax-keyword: #d73a49;
    --syntax-entity: #6f42c1;
    --syntax-constant: #005cc5;
    --syntax-string: #032f62;
    --syntax-variable: #e36209;
    --syntax-comment: #6a737d;
    --syntax-tag: #22863a;
    --syntax-bullet: #735c0f;
}
.dark .source-context {
    --syntax-keyword: #ff7b72;
    --syntax-entity: #d2a8ff;
    --syntax-constant: #79c0ff;
    --syntax-string: #a5d6ff;
    --syntax-variable: #ffa657;
    --syntax-comment: #8b949e;
    --syntax-tag: #7ee787;
    --syntax-bullet: #f2cc60;
}
.source-context :deep(.hljs-doctag),
.source-context :deep(.hljs-keyword),
.source-context :deep(.hljs-template-tag),
.source-context :deep(.hljs-template-variable),
.source-context :deep(.hljs-type),
.source-context :deep(.hljs-variable.language_) {
    color: var(--syntax-keyword);
}
.source-context :deep(.hljs-title) {
    color: var(--syntax-entity);
}
.source-context :deep(.hljs-attr),
.source-context :deep(.hljs-attribute),
.source-context :deep(.hljs-literal),
.source-context :deep(.hljs-meta),
.source-context :deep(.hljs-number),
.source-context :deep(.hljs-operator),
.source-context :deep(.hljs-variable),
.source-context :deep(.hljs-selector-attr),
.source-context :deep(.hljs-selector-class),
.source-context :deep(.hljs-selector-id) {
    color: var(--syntax-constant);
}
.source-context :deep(.hljs-string),
.source-context :deep(.hljs-regexp) {
    color: var(--syntax-string);
}
.source-context :deep(.hljs-built_in),
.source-context :deep(.hljs-symbol) {
    color: var(--syntax-variable);
}
.source-context :deep(.hljs-comment),
.source-context :deep(.hljs-code),
.source-context :deep(.hljs-formula) {
    color: var(--syntax-comment);
}
.source-context :deep(.hljs-name),
.source-context :deep(.hljs-quote),
.source-context :deep(.hljs-selector-tag),
.source-context :deep(.hljs-selector-pseudo) {
    color: var(--syntax-tag);
}
.source-context :deep(.hljs-section) {
    color: var(--syntax-constant);
    font-weight: bold;
}
.source-context :deep(.hljs-bullet) {
    color: var(--syntax-bullet);
}
.source-context :deep(.hljs-subst) {
    color: var(--foreground);
}
.source-context :deep(.hljs-emphasis) {
    color: var(--foreground);
    font-style: italic;
}
.source-context :deep(.hljs-strong) {
    color: var(--foreground);
    font-weight: bold;
}
</style>
