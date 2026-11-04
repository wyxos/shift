import { Node } from '@tiptap/core';
import { DOMSerializer } from '@tiptap/pm/model';

export default Node.create({
    name: 'replyQuote',
    group: 'block',
    content: 'block+',
    atom: true,
    isolating: true,

    addAttributes() {
        return {
            replyTo: {
                default: null,
                parseHTML: (element) => element.getAttribute('data-reply-to'),
                renderHTML: ({ replyTo }) => (replyTo ? { 'data-reply-to': String(replyTo) } : {}),
            },
        };
    },

    parseHTML() {
        return [{ tag: 'blockquote.shift-reply, blockquote[data-reply-to]', priority: 60 }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['blockquote', { ...HTMLAttributes, class: 'shift-reply' }, 0];
    },

    addNodeView() {
        const serializer = DOMSerializer.fromSchema(this.editor.schema);

        return ({ editor, node, getPos, HTMLAttributes }) => {
            const quote = document.createElement('blockquote');
            quote.className = 'shift-reply';
            quote.setAttribute('contenteditable', 'false');
            if (HTMLAttributes['data-reply-to']) quote.dataset.replyTo = HTMLAttributes['data-reply-to'];
            quote.append(serializer.serializeFragment(node.content));

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'shift-reply-remove';
            remove.title = 'Remove quote';
            remove.setAttribute('aria-label', 'Remove quote');
            remove.innerHTML =
                '<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m18 6-12 12M6 6l12 12"/></svg>';
            remove.addEventListener('click', () => {
                const from = getPos();
                if (typeof from !== 'number') return;

                editor
                    .chain()
                    .deleteRange({ from, to: from + node.nodeSize })
                    .focus()
                    .run();
            });
            quote.append(remove);

            return {
                dom: quote,
                stopEvent: (event) => remove.contains(event.target as globalThis.Node),
                ignoreMutation: () => true,
            };
        };
    },
});
