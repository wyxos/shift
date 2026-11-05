<script setup lang="ts">
import { ImageLightbox } from '@/components/ui/image-lightbox';
import ShiftEditor from '@/shared/components/ShiftEditor.vue';
import type { SentAttachment } from '@/shared/components/shift-editor/types';
import TaskCommentContent from '@/shared/components/tasks/TaskCommentContent.vue';
import TaskMessageAttachments from '@/shared/components/tasks/TaskMessageAttachments.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { imageMessageCases, type QaAttachment } from './imageMessageCases';
import { createImageMessageTransport } from './imageMessageTransport';

const props = defineProps<{ scenario: string }>();
const active = computed(() => imageMessageCases.find((item) => item.id === props.scenario) ?? imageMessageCases[0]);
const drafts = reactive<Record<string, string>>({});
const sent = reactive<Record<string, { content: string; attachments: QaAttachment[] }[]>>({});
const own = ref(true);
const headerRef = ref<HTMLElement | null>(null);
const headerHeight = ref(80);
let headerObserver: ResizeObserver | undefined;
onMounted(() => {
    headerObserver = new ResizeObserver(([entry]) => {
        headerHeight.value = entry.target.getBoundingClientRect().height;
    });
    if (headerRef.value) headerObserver.observe(headerRef.value);
});
onBeforeUnmount(() => headerObserver?.disconnect());
const transport = createImageMessageTransport();
const preview = reactive({ open: false, src: '', alt: '' });

function openImage(event: MouseEvent): void {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const image = target.closest('img') ?? target.closest('[data-message-image]')?.querySelector('img');
    if (!(image instanceof HTMLImageElement)) return;
    event.preventDefault();
    preview.src = image.currentSrc || image.src;
    preview.alt = image.alt || 'Image';
    preview.open = true;
}

function send(payload: { html: string; attachments: SentAttachment[] }): void {
    const id = active.value.id;
    (sent[id] ??= []).push({ content: payload.html, attachments: transport.mapAttachments(payload.attachments) });
    drafts[id] = '';
}
</script>

<template>
    <Head title="Image messages QA" />
    <div class="bg-background text-foreground min-h-screen">
        <header ref="headerRef" class="border-border bg-background/95 sticky top-0 z-20 border-b px-4 py-3 backdrop-blur sm:px-6">
            <div class="mx-auto flex max-w-[1440px] flex-wrap items-center justify-between gap-2">
                <div>
                    <h1 class="text-lg font-semibold">Image messages QA</h1>
                    <p class="text-muted-foreground text-xs">Local synthetic fixtures · Teams reference · Real SHIFT components</p>
                </div>
                <div class="flex gap-1 rounded-md border p-1" role="group" aria-label="Message appearance">
                    <button
                        type="button"
                        class="min-h-9 rounded px-3 text-sm transition-colors motion-reduce:transition-none"
                        :class="own ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'"
                        :aria-pressed="own"
                        @click="own = true"
                    >
                        Sent
                    </button>
                    <button
                        type="button"
                        class="min-h-9 rounded px-3 text-sm transition-colors motion-reduce:transition-none"
                        :class="!own ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'"
                        :aria-pressed="!own"
                        @click="own = false"
                    >
                        Received
                    </button>
                </div>
            </div>
        </header>
        <div class="mx-auto grid max-w-[1440px] md:grid-cols-[240px_minmax(0,1fr)]">
            <nav
                aria-label="Image message cases"
                :style="{ top: `${headerHeight}px`, maxHeight: `calc(100dvh - ${headerHeight}px)` }"
                class="border-border bg-background sticky z-10 flex gap-1 overflow-x-auto border-b p-2 md:flex-col md:self-start md:overflow-y-auto md:border-r md:border-b-0 md:p-3"
            >
                <Link
                    v-for="item in imageMessageCases"
                    :key="item.id"
                    :href="`/qa/image-messages/${item.id}`"
                    preserve-state
                    :aria-current="active.id === item.id ? 'page' : undefined"
                    class="shrink-0 rounded-md px-3 py-2 text-sm transition-colors motion-reduce:transition-none md:shrink"
                    :class="active.id === item.id ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'"
                >
                    <span class="mr-2 font-mono text-xs opacity-70">{{ item.id }}</span
                    >{{ item.title }}
                </Link>
            </nav>
            <main class="min-w-0 p-4 sm:p-6" :data-qa-case="active.id">
                <p class="text-primary mb-1 font-mono text-xs">{{ active.id }} · {{ active.dimensions }}</p>
                <h2 class="text-2xl font-semibold">{{ active.title }}</h2>
                <p class="text-muted-foreground mt-2 max-w-3xl text-sm" data-testid="expected-behaviour">{{ active.expected }}</p>
                <section aria-label="Rendered message" class="border-border bg-muted/20 mt-6 min-w-0 rounded-lg border p-3 sm:p-6">
                    <div class="flex min-w-0" :class="own ? 'justify-end' : 'justify-start'">
                        <div
                            class="w-fit max-w-full rounded-lg px-3 py-2 text-sm shadow-sm"
                            :class="
                                own
                                    ? 'bg-primary text-primary-foreground rounded-br-md'
                                    : 'bg-background text-foreground border-border rounded-bl-md border'
                            "
                            data-testid="qa-message"
                        >
                            <TaskCommentContent v-if="active.content" :key="active.id" :content="active.content" @click="openImage" />
                            <TaskMessageAttachments :attachments="active.attachments" :own="own" @image-click="openImage" />
                        </div>
                    </div>
                </section>
                <section class="mt-8" aria-label="Interactive editor sandbox" data-testid="qa-editor">
                    <h3 class="font-semibold">Try the editor</h3>
                    <p class="text-muted-foreground mt-1 mb-3 max-w-3xl text-sm">
                        Paste images, type captions, use Shift+Enter, or attach a file. Send adds a local preview below. Uploads stay in this page's
                        memory; nothing is sent to a task or server. Case navigation preserves drafts and attachments; reload clears sandbox data.
                    </p>
                    <KeepAlive>
                        <ShiftEditor
                            :key="active.id"
                            v-model="drafts[active.id]"
                            :enable-ai-improve="false"
                            :axios-instance="transport.client"
                            :upload-endpoints="transport.endpoints"
                            :remove-temp-url="transport.removeUrl"
                            :resolve-temp-url="(data: { url: string }) => data.url"
                            accessible-label="Image QA message editor"
                            :touch-friendly="true"
                            @send="send"
                        />
                    </KeepAlive>
                    <div class="mt-4 grid gap-3" aria-live="polite">
                        <div
                            v-for="(message, index) in sent[active.id] ?? []"
                            :key="index"
                            class="border-border rounded-lg border p-3"
                            data-testid="qa-sent-message"
                        >
                            <TaskCommentContent :content="message.content" @click="openImage" />
                            <TaskMessageAttachments :attachments="message.attachments" @image-click="openImage" />
                        </div>
                    </div>
                </section>
                <p class="text-muted-foreground mt-8 text-xs">
                    Static examples verify rendering. The sandbox verifies editor interactions with a memory-only upload adapter. Real server upload,
                    persistence, and access control need the application integration tests.
                </p>
            </main>
        </div>
        <ImageLightbox v-model:open="preview.open" :src="preview.src" :alt="preview.alt" />
    </div>
</template>
