import { Extension } from '@tiptap/core';

const replyClass = 'shift-reply';

export default Extension.create({
    name: 'replyQuote',

    addGlobalAttributes() {
        return [
            {
                types: ['blockquote'],
                attributes: {
                    replyTo: {
                        default: null,
                        parseHTML: (element) => element.getAttribute('data-reply-to'),
                        renderHTML: (attributes) => {
                            if (!attributes.replyTo) return {};
                            return { 'data-reply-to': String(attributes.replyTo) };
                        },
                    },
                    quoteClass: {
                        default: null,
                        parseHTML: (element) => (element.classList.contains(replyClass) ? replyClass : null),
                        renderHTML: (attributes) => {
                            if (attributes.quoteClass !== replyClass) return {};
                            return { class: replyClass };
                        },
                    },
                },
            },
        ];
    },
});
