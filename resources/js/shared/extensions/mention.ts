import { mergeAttributes, Node } from '@tiptap/core';

export default Node.create({
    name: 'shiftMention',
    group: 'inline',
    inline: true,
    atom: true,
    selectable: false,

    addAttributes() {
        return {
            kind: {
                default: null,
                parseHTML: (element) => element.getAttribute('data-mention-kind'),
            },
            identity: {
                default: null,
                parseHTML: (element) => element.getAttribute('data-mention-id'),
            },
            label: {
                default: null,
                parseHTML: (element) => (element.textContent ?? '').replace(/^@/, ''),
            },
        };
    },

    parseHTML() {
        return [{ tag: 'span[data-shift-mention="true"]' }];
    },

    renderHTML({ node, HTMLAttributes }) {
        const label = String(node.attrs.label ?? '').trim();

        return [
            'span',
            mergeAttributes(HTMLAttributes, {
                class: 'shift-mention',
                'data-shift-mention': 'true',
                'data-mention-kind': String(node.attrs.kind ?? ''),
                'data-mention-id': String(node.attrs.identity ?? ''),
            }),
            `@${label}`,
        ];
    },

    renderText({ node }) {
        return `@${String(node.attrs.label ?? '').trim()}`;
    },
});
