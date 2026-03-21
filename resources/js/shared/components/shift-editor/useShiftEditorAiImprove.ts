import type { AxiosInstance } from 'axios';
import { ref, type ComputedRef, type Ref } from 'vue';
import type { ProtectedFragment } from './types';

type UseShiftEditorAiImproveOptions = {
    axiosClient: ComputedRef<AxiosInstance | typeof import('axios').default>;
    editor: Ref<any>;
    isUploading: ComputedRef<boolean>;
    resolveAiImproveUrl: () => string | null;
    getAiContext: () => string;
    onAccept: (html: string) => void;
};

function createProtectedToken(index: number): string {
    return `[[SHIFT_KEEP_${Date.now()}_${index}_${Math.random().toString(36).slice(2, 8)}]]`;
}

function prepareHtmlForAi(html: string): { preparedHtml: string; protectedFragments: ProtectedFragment[] } {
    if (typeof DOMParser === 'undefined') {
        return { preparedHtml: html, protectedFragments: [] };
    }

    const parser = new DOMParser();
    const doc = parser.parseFromString(`<body>${html}</body>`, 'text/html');
    const protectedFragments: ProtectedFragment[] = [];

    const protectedNodes = doc.body.querySelectorAll('img, blockquote.shift-reply, blockquote[data-reply-to]');
    protectedNodes.forEach((node, index) => {
        const token = createProtectedToken(index);
        protectedFragments.push({ token, html: node.outerHTML });
        node.replaceWith(doc.createTextNode(token));
    });

    return {
        preparedHtml: doc.body.innerHTML,
        protectedFragments,
    };
}

function restoreProtectedFragments(html: string, protectedFragments: ProtectedFragment[]): { restoredHtml: string; missingTokens: string[] } {
    let restoredHtml = html;
    const missingTokens: string[] = [];

    protectedFragments.forEach((fragment) => {
        if (!restoredHtml.includes(fragment.token)) {
            missingTokens.push(fragment.token);
            return;
        }

        restoredHtml = restoredHtml.split(fragment.token).join(fragment.html);
    });

    return { restoredHtml, missingTokens };
}

export function useShiftEditorAiImprove(options: UseShiftEditorAiImproveOptions) {
    const aiImproving = ref(false);
    const aiError = ref('');
    const aiPreviewOpen = ref(false);
    const aiPreviewHtml = ref('');

    async function improveWithAi() {
        if (aiImproving.value || options.isUploading.value) return;

        const improveUrl = options.resolveAiImproveUrl();
        if (!improveUrl) {
            aiError.value = 'AI improvement endpoint is not configured.';
            return;
        }

        const currentHtml = options.editor.value?.getHTML() ?? '';
        if (!currentHtml.trim()) {
            aiError.value = 'Write a message before using AI improvement.';
            return;
        }

        aiError.value = '';
        aiImproving.value = true;

        const { preparedHtml, protectedFragments } = prepareHtmlForAi(currentHtml);

        try {
            const context = options.getAiContext().trim();
            const response = await options.axiosClient.value.post(improveUrl, {
                html: preparedHtml,
                protected_tokens: protectedFragments.map((fragment) => fragment.token),
                context: context || undefined,
            });

            const improvedHtml = String(response.data?.improved_html ?? '').trim();
            if (!improvedHtml) {
                throw new Error('AI returned an empty rewrite.');
            }

            const { restoredHtml, missingTokens } = restoreProtectedFragments(improvedHtml, protectedFragments);
            if (missingTokens.length > 0) {
                throw new Error('AI response omitted protected rich content. No changes were applied.');
            }

            aiPreviewHtml.value = restoredHtml;
            aiPreviewOpen.value = true;
        } catch (error: any) {
            aiError.value = error?.response?.data?.error || error?.message || 'Failed to improve message with AI.';
        } finally {
            aiImproving.value = false;
        }
    }

    function rejectAiImprove() {
        aiPreviewOpen.value = false;
        aiPreviewHtml.value = '';
    }

    function acceptAiImprove() {
        if (!aiPreviewHtml.value) return;
        options.editor.value?.commands.setContent(aiPreviewHtml.value, false);
        options.onAccept(options.editor.value?.getHTML() ?? aiPreviewHtml.value);
        aiPreviewOpen.value = false;
        aiPreviewHtml.value = '';
    }

    return {
        acceptAiImprove,
        aiError,
        aiImproving,
        aiPreviewHtml,
        aiPreviewOpen,
        improveWithAi,
        rejectAiImprove,
    };
}
