import { renderRichContent } from '../../tasks/rich-content';

export function resolveEditorContent(value?: string): string {
    const content = String(value ?? '');
    return content.trim() ? renderRichContent(content) : '';
}

export function isInRichBlockNeedingEnter(editorInstance: any): boolean {
    const from = editorInstance?.state?.selection?.$from;
    if (!from) return false;

    for (let depth = from.depth; depth >= 0; depth -= 1) {
        const typeName = from.node(depth)?.type?.name;
        if (typeName === 'listItem' || typeName === 'codeBlock' || typeName === 'blockquote') return true;
    }

    return false;
}
