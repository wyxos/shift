import { createEditorLowlight } from '../components/shift-editor/codeHighlighting';

export type SourceToken = { text: string; classes: string[] };
export type SourceHighlight = { language: string; lines: SourceToken[][] };
type SyntaxNode = { type: string; value?: string; properties?: { className?: unknown }; children?: SyntaxNode[] };

const lowlight = createEditorLowlight();
const languages: Record<string, { name: string; grammar: string }> = {
    php: { name: 'PHP', grammar: 'php' },
    ts: { name: 'TypeScript', grammar: 'typescript' },
    tsx: { name: 'TypeScript', grammar: 'typescript' },
    mts: { name: 'TypeScript', grammar: 'typescript' },
    cts: { name: 'TypeScript', grammar: 'typescript' },
    js: { name: 'JavaScript', grammar: 'javascript' },
    jsx: { name: 'JavaScript', grammar: 'javascript' },
    mjs: { name: 'JavaScript', grammar: 'javascript' },
    cjs: { name: 'JavaScript', grammar: 'javascript' },
    json: { name: 'JSON', grammar: 'json' },
    css: { name: 'CSS', grammar: 'css' },
    scss: { name: 'CSS', grammar: 'css' },
    html: { name: 'HTML', grammar: 'html' },
    htm: { name: 'HTML', grammar: 'html' },
    xml: { name: 'XML', grammar: 'xml' },
    svg: { name: 'XML', grammar: 'xml' },
    py: { name: 'Python', grammar: 'python' },
};

function vueNodes(code: string): SyntaxNode[] {
    const sections = /(<(script|style)\b[^>]*>)([\s\S]*?)(<\/\2\s*>|$)/gi;
    const nodes: SyntaxNode[] = [];
    let offset = 0;
    let match: RegExpExecArray | null;
    while ((match = sections.exec(code))) {
        nodes.push(...lowlight.highlight('xml', code.slice(offset, match.index) + match[1]).children);
        const grammar = match[2].toLowerCase() === 'style' ? 'css' : /\blang\s*=\s*["']tsx?["']/i.test(match[1]) ? 'typescript' : 'javascript';
        nodes.push(...lowlight.highlight(grammar, match[3]).children);
        nodes.push(...lowlight.highlight('xml', match[4]).children);
        offset = match.index + match[0].length;
    }
    if (offset === 0) {
        const markup = /^\s*<\/?(?:template|[a-z][\w-]*)(?:\s|>|\/)/im.test(code);
        return lowlight.highlight(markup ? 'xml' : 'typescript', code).children;
    }
    nodes.push(...lowlight.highlight('xml', code.slice(offset)).children);
    return nodes;
}

function lineTokens(nodes: SyntaxNode[]): SourceToken[][] {
    const lines: SourceToken[][] = [[]];
    const visit = (node: SyntaxNode, inherited: string[] = []): void => {
        if (node.type === 'text') {
            const parts = (node.value ?? '').split('\n');
            parts.forEach((part, index) => {
                if (index > 0) lines.push([]);
                if (part !== '') lines[lines.length - 1].push({ text: part, classes: inherited });
            });
            return;
        }
        const classNames = node.properties?.className;
        const classes = Array.isArray(classNames)
            ? classNames.filter((value): value is string => typeof value === 'string' && /^(?:hljs-[\w-]+|language-[\w-]+|[a-z]+_)$/.test(value))
            : [];
        for (const child of node.children ?? []) visit(child, [...inherited, ...classes]);
    };
    nodes.forEach((node) => visit(node));
    return lines;
}

function vueLanguage(code: string): string {
    const script = code.match(/<script\b([^>]*)>/i);
    if (script) return /\blang\s*=\s*["']tsx?["']/i.test(script[1]) ? 'Vue · TypeScript' : 'Vue · JavaScript';
    if (/^\s*<style\b/im.test(code)) return 'Vue · CSS';
    if (/^\s*<\/?(?:template|[a-z][\w-]*)(?:\s|>|\/)/im.test(code)) return 'Vue · HTML';
    return 'Vue · TypeScript';
}

export function highlightSource(file: string | null | undefined, sourceLines: string[]): SourceHighlight {
    const code = sourceLines.join('\n');
    const extension = file?.split(/[?#]/)[0].split('.').at(-1)?.toLowerCase() ?? '';
    const language = extension === 'vue' ? vueLanguage(code) : (languages[extension]?.name ?? 'Plain text');
    const plain = (): SourceHighlight => ({ language, lines: sourceLines.map((text) => [{ text, classes: [] }]) });
    if (code.length > 50000) return plain();
    try {
        const nodes =
            extension === 'vue' ? vueNodes(code) : languages[extension] ? lowlight.highlight(languages[extension].grammar, code).children : null;
        return nodes ? { language, lines: lineTokens(nodes) } : plain();
    } catch {
        return plain();
    }
}
