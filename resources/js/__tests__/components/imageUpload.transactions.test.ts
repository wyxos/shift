import ImageUpload from '@/shared/extensions/imageUpload';
import type { ChunkedUploadResult } from '@/shared/lib/chunkedUpload';
import { Editor } from '@tiptap/core';
import Image from '@tiptap/extension-image';
import type { Node } from '@tiptap/pm/model';
import StarterKit from '@tiptap/starter-kit';
import { afterEach, expect, it, vi } from 'vitest';

const upload = vi.hoisted(() => vi.fn());
vi.mock('@/shared/lib/chunkedUpload', () => ({ uploadChunkedFile: upload }));

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

function fixture() {
    vi.useFakeTimers();
    const labels: string[] = [];
    const context = {
        fillRect: vi.fn(),
        strokeRect: vi.fn(),
        beginPath: vi.fn(),
        moveTo: vi.fn(),
        lineTo: vi.fn(),
        closePath: vi.fn(),
        fill: vi.fn(),
        arc: vi.fn(),
        fillText: (text: string) => labels.push(text),
    };
    // This canvas stub implements only the drawing calls used by upload tiles.
    vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(context as unknown as CanvasRenderingContext2D);
    vi.spyOn(HTMLCanvasElement.prototype, 'toDataURL').mockImplementation(() => `data:image/png;base64,${btoa(labels.slice(-2).join('|'))}`);
    vi.stubGlobal(
        'Image',
        class {
            onload: (() => void) | null = null;
            set src(_value: string) {
                this.onload?.();
            }
        },
    );
    const controls: {
        progress?: (percent: number) => void;
        complete?: (result: ChunkedUploadResult) => void;
        fail?: (error: Error) => void;
    } = {};
    upload.mockReset();
    upload.mockImplementation((options: { onProgress?: (percent: number) => void }) => {
        controls.progress = options.onProgress;
        return new Promise<ChunkedUploadResult>((resolve, reject) => {
            controls.complete = resolve;
            controls.fail = reject;
        });
    });
    const editor = new Editor({ extensions: [StarterKit, Image.configure({ inline: true }), ImageUpload], content: '<p>Draft</p>' });
    editor.commands.insertFiles([new File(['image'], 'editor.png', { type: 'image/png' })]);
    expect(upload).toHaveBeenCalledOnce();
    return { editor, controls };
}

function uploadedNode(editor: Editor) {
    let image: { node: Node; pos: number } | undefined;
    editor.state.doc.descendants((node, pos) => {
        if (node.type.name === 'image') image = { node, pos };
    });
    if (!image) throw new Error('Expected the upload image to remain in the document');
    return image;
}

it.each(['progress', 'completion', 'failure'] as const)('keeps edits made before a deferred image %s update', async (phase) => {
    const { editor, controls } = fixture();
    vi.advanceTimersByTime(0);
    try {
        if (phase === 'progress') controls.progress?.(50);
        if (phase === 'completion')
            controls.complete?.({ path: 'temp_attachments/proof/editor.png', original_filename: 'editor.png', url: '/uploaded-editor.png' });
        if (phase === 'failure') controls.fail?.(new Error('Upload failed'));
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();

        editor.commands.insertContent('Typed during upload');
        const current = uploadedNode(editor);
        editor.view.dispatch(
            editor.state.tr.setNodeMarkup(current.pos, current.node.type, { ...current.node.attrs, alt: 'Preserved alt' }, current.node.marks),
        );
        expect(() => vi.advanceTimersByTime(0)).not.toThrow();
        expect(editor.getText()).toContain('Typed during upload');
        const { node } = uploadedNode(editor);
        expect(node.attrs.alt).toBe('Preserved alt');
        if (phase === 'progress') {
            expect(node.attrs.src).toBe(`data:image/png;base64,${btoa('Uploading...|50%')}`);
            expect(node.attrs.title).toMatch(/^upload-/);
        } else {
            expect(node.attrs.title).toBe('');
            expect(node.attrs.src).toBe(phase === 'completion' ? '/uploaded-editor.png' : `data:image/png;base64,${btoa('Upload failed|0%')}`);
            controls.progress?.(80);
            vi.advanceTimersByTime(0);
            expect(uploadedNode(editor).node.attrs.src).toBe(node.attrs.src);
        }
    } finally {
        editor.destroy();
    }
});

it.each(['removed', 'destroyed'] as const)('ignores a deferred update when its image has been %s', (state) => {
    const { editor, controls } = fixture();
    vi.advanceTimersByTime(0);
    try {
        controls.progress?.(50);
        if (state === 'removed') editor.commands.clearContent(false);
        else editor.destroy();
        expect(() => vi.advanceTimersByTime(0)).not.toThrow();
        if (state === 'removed') expect(editor.getJSON()).toEqual({ type: 'doc', content: [{ type: 'paragraph' }] });
    } finally {
        if (!editor.isDestroyed) editor.destroy();
    }
});

it('ignores initial placeholder insertion after the editor is destroyed', () => {
    const { editor } = fixture();
    editor.destroy();
    expect(() => vi.advanceTimersByTime(0)).not.toThrow();
});

it('ignores deferred adjacent-image typing after the editor is destroyed', () => {
    const { editor } = fixture();
    vi.advanceTimersByTime(0);
    const image = uploadedNode(editor);
    editor.commands.setTextSelection(image.pos + image.node.nodeSize);
    expect(editor.commands.typeText('After image')).toBe(true);
    editor.destroy();
    expect(() => vi.advanceTimersByTime(0)).not.toThrow();
});
