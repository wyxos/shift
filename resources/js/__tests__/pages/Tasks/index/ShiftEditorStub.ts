import { vi } from 'vitest';
import { h } from 'vue';

export const shiftEditorConfirmMentionAdditionMock = vi.fn();
export const ShiftEditorStub = {
    props: ['modelValue', 'sendable', 'sendDisabled'],
    emits: ['update:modelValue', 'send', 'uploading', 'mention-query', 'mention-add-request', 'slash-command'],
    methods: {
        confirmMentionAddition(candidate: unknown) {
            shiftEditorConfirmMentionAdditionMock(candidate);
        },
    },
    render() {
        const previewText = String((this as any).modelValue ?? '').replace(/<[^>]+>/g, '');
        const payload = () => ({
            html: (this as any).modelValue ?? '<p>hello</p>',
            attachments: [],
            mentions: [],
            addCollaborators: [],
        });

        return h('div', { ...(this as any).$attrs, class: 'shift-editor-stub' }, [
            h('textarea', {
                'data-testid': 'stub-editor-input',
                value: (this as any).modelValue,
                onInput: (event: Event) => (this as any).$emit('update:modelValue', (event.target as HTMLTextAreaElement).value),
            }),
            h('div', { 'data-testid': 'stub-editor-preview' }, previewText),
            h(
                'button',
                {
                    type: 'button',
                    'data-testid': 'stub-mention-add-request',
                    onClick: () =>
                        (this as any).$emit('mention-add-request', {
                            kind: 'internal',
                            id: 31,
                            name: 'New Collaborator',
                            isCollaborator: false,
                        }),
                },
                'request mention addition',
            ),
            (this as any).$slots['before-send']?.(),
            (this as any).sendable === false
                ? null
                : h(
                      'button',
                      {
                          type: 'button',
                          'data-testid': 'stub-send',
                          disabled: (this as any).sendDisabled,
                          onClick: () => (this as any).$emit('send', payload()),
                      },
                      'send',
                  ),
        ]);
    },
};
