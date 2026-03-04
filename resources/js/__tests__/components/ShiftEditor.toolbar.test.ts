import ShiftEditor from '@/components/ShiftEditor.vue';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';

// Basic route helper
beforeEach(() => {
    (global as any).route = (name: string) => {
        if (name === 'attachments.upload-init') return '/attachments/upload-init';
        if (name === 'attachments.upload-status') return '/attachments/upload-status';
        if (name === 'attachments.upload-chunk') return '/attachments/upload-chunk';
        if (name === 'attachments.upload-complete') return '/attachments/upload-complete';
        if (name === 'attachments.remove-temp') return '/attachments/remove-temp';
        if (name === 'ai.improve') return '/ai/improve';
        return '/';
    };
    postMock.mockReset();
    getMock.mockReset();
    deleteMock.mockReset();
});

// Mock axios
const postMock = vi.fn();
const getMock = vi.fn();
const deleteMock = vi.fn();
vi.mock('axios', () => ({
    default: {
        post: (...a: any[]) => postMock(...a),
        get: (...a: any[]) => getMock(...a),
        delete: (...a: any[]) => deleteMock(...a),
    },
}));

// Ensure emoji-picker web component is available
import 'emoji-picker-element';

describe('ShiftEditor toolbar', () => {
    it('inserts emoji into the editor via emoji picker', async () => {
        const wrapper = mount(ShiftEditor);
        await nextTick();
        // open emoji
        await wrapper.get('[data-testid="toolbar-emoji"]').trigger('click');
        await nextTick();
        const picker = wrapper.get('[data-testid="emoji-picker"]').element;
        // dispatch custom event
        picker.dispatchEvent(new CustomEvent('emoji-click', { detail: { unicode: '😀' } }));
        await nextTick();
        // Expect emoji in editor
        const text = wrapper.find('.ProseMirror').text();
        expect(text).toContain('😀');
    });

    it('adds files via attachment icon to attachments list (image and non-image)', async () => {
        postMock.mockImplementation((url: string) => {
            if (url === '/attachments/upload-init') {
                return Promise.resolve({ data: { upload_id: 'u1', chunk_size: 5, total_chunks: 1, max_bytes: 41943040 } });
            }
            if (url === '/attachments/upload-chunk') {
                return Promise.resolve({ data: { ok: true } });
            }
            if (url === '/attachments/upload-complete') {
                return Promise.resolve({ data: { path: 'temp_attachments/ATT/test.bin' } });
            }
            return Promise.resolve({ data: {} });
        });
        getMock.mockResolvedValue({ data: { uploaded_chunks: [], total_chunks: 1, chunk_size: 5 } });
        const wrapper = mount(ShiftEditor);
        await nextTick();

        const fileInput = wrapper.get('[data-testid="file-input"]').element as HTMLInputElement;
        // add an image and a non-image
        const img = new File([new Uint8Array([1])], 'photo.png', { type: 'image/png' });
        const doc = new File([new Uint8Array([2, 3])], 'doc.txt', { type: 'text/plain' });

        Object.defineProperty(fileInput, 'files', { value: [img, doc] });
        await wrapper.get('[data-testid="toolbar-attachment"]').trigger('click');
        // directly fire change event since we mocked files
        fileInput.dispatchEvent(new Event('change'));
        await nextTick();

        const items = wrapper.findAll('[data-testid="attachment-item"]');
        // two items present (ordering not guaranteed)
        expect(items.length).toBe(2);
        const names = items.map((i) => i.text());
        expect(names.some((t) => t.includes('photo.png'))).toBe(true);
        expect(names.some((t) => t.includes('doc.txt'))).toBe(true);
    });

    it('emits send with HTML and resets editor content', async () => {
        const wrapper = mount(ShiftEditor);
        await nextTick();
        const ed: any = (wrapper.vm as any).editor;
        ed?.commands.setContent('<p>Hello world</p>');
        await nextTick();

        await wrapper.get('[data-testid="toolbar-send"]').trigger('click');
        await nextTick();

        const emitted = wrapper.emitted('send');
        expect(emitted && emitted[0] && emitted[0][0].html).toContain('Hello world');

        // content is cleared
        const text = wrapper.find('.ProseMirror').text().trim();
        expect(text).toBe('');
    });

    it('submits on Enter and keeps Shift+Enter for newline', async () => {
        const wrapper = mount(ShiftEditor);
        await nextTick();
        const ed: any = (wrapper.vm as any).editor;
        ed?.commands.setContent('<p>Hello world</p>');
        await nextTick();

        await wrapper.find('.ProseMirror').trigger('keydown', { key: 'Enter' });
        await nextTick();

        const firstSend = wrapper.emitted('send');
        expect(firstSend?.length ?? 0).toBe(1);

        ed?.commands.setContent('<p>Second line</p>');
        await nextTick();
        await wrapper.find('.ProseMirror').trigger('keydown', { key: 'Enter', shiftKey: true });
        await nextTick();

        const afterShiftEnter = wrapper.emitted('send');
        expect(afterShiftEnter?.length ?? 0).toBe(1);
    });

    it('does not submit on Enter while inside a list item', async () => {
        const wrapper = mount(ShiftEditor);
        await nextTick();
        const ed: any = (wrapper.vm as any).editor;

        ed?.commands.setContent('<ul><li>First</li></ul>');
        await nextTick();

        await wrapper.find('.ProseMirror').trigger('keydown', { key: 'Enter' });
        await nextTick();

        const emitted = wrapper.emitted('send');
        expect(emitted?.length ?? 0).toBe(0);
    });

    it('preserves reply quote metadata through editor roundtrip', async () => {
        const wrapper = mount(ShiftEditor);
        await nextTick();
        const ed: any = (wrapper.vm as any).editor;

        ed?.commands.setContent('<blockquote class="shift-reply" data-reply-to="42"><p>Replying</p><p>Quoted</p></blockquote><p></p>');
        await nextTick();

        const html = ed?.getHTML() ?? '';
        expect(html).toContain('data-reply-to="42"');
        expect(html).toContain('class="shift-reply"');
        expect(html).toContain('Quoted');
    });

    it('opens AI drawer and applies accepted improvements while keeping protected rich content', async () => {
        postMock.mockImplementation((url: string, payload: any) => {
            if (url === '/ai/improve') {
                const [firstToken, secondToken] = payload.protected_tokens ?? [];

                return Promise.resolve({
                    data: {
                        improved_html: `<p>This message is clearer.</p><p>${firstToken}</p><p>${secondToken}</p>`,
                    },
                });
            }

            return Promise.resolve({ data: {} });
        });

        const wrapper = mount(ShiftEditor);
        await nextTick();
        const ed: any = (wrapper.vm as any).editor;
        ed?.commands.setContent(
            '<p>plese fix this sentence</p><blockquote class="shift-reply" data-reply-to="42"><p>Original quote</p></blockquote><p><img src="/attachments/1/download" class="editor-tile"></p>',
        );
        await nextTick();

        await wrapper.get('[data-testid="toolbar-ai-improve"]').trigger('click');
        await nextTick();

        expect(wrapper.find('[data-testid="ai-improve-drawer"]').exists()).toBe(true);
        await wrapper.get('[data-testid="ai-improve-accept"]').trigger('click');
        await nextTick();

        const updatedHtml = ed?.getHTML() ?? '';
        expect(updatedHtml).toContain('This message is clearer.');
        expect(updatedHtml).toContain('data-reply-to="42"');
        expect(updatedHtml).toContain('src="/attachments/1/download"');
    });

    it('keeps original editor content when AI suggestion is rejected', async () => {
        postMock.mockImplementation((url: string) => {
            if (url === '/ai/improve') {
                return Promise.resolve({
                    data: {
                        improved_html: '<p>This is an improved rewrite.</p>',
                    },
                });
            }

            return Promise.resolve({ data: {} });
        });

        const wrapper = mount(ShiftEditor);
        await nextTick();
        const ed: any = (wrapper.vm as any).editor;
        ed?.commands.setContent('<p>Original content stays.</p>');
        await nextTick();

        await wrapper.get('[data-testid="toolbar-ai-improve"]').trigger('click');
        await nextTick();
        expect(wrapper.find('[data-testid="ai-improve-drawer"]').exists()).toBe(true);

        await wrapper.get('[data-testid="ai-improve-reject"]').trigger('click');
        await nextTick();

        const unchangedHtml = ed?.getHTML() ?? '';
        expect(unchangedHtml).toContain('Original content stays.');
    });

    it('sends provided thread context to AI improvement endpoint', async () => {
        postMock.mockImplementation((url: string) => {
            if (url === '/ai/improve') {
                return Promise.resolve({
                    data: {
                        improved_html: '<p>Updated from context.</p>',
                    },
                });
            }

            return Promise.resolve({ data: {} });
        });

        const wrapper = mount(ShiftEditor, {
            props: {
                aiContext: 'Recent thread context (oldest to newest):\n1. Alice: Need status update.',
            },
        });
        await nextTick();

        const ed: any = (wrapper.vm as any).editor;
        ed?.commands.setContent('<p>status pls</p>');
        await nextTick();

        await wrapper.get('[data-testid="toolbar-ai-improve"]').trigger('click');
        await nextTick();

        expect(postMock).toHaveBeenCalledWith(
            '/ai/improve',
            expect.objectContaining({
                context: 'Recent thread context (oldest to newest):\n1. Alice: Need status update.',
            }),
        );
    });
});
