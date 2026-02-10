import type { Editor } from '@tiptap/core';
import { Extension } from '@tiptap/core';
import axiosDefault, { type AxiosInstance } from 'axios';
import { uploadChunkedFile, type UploadEndpoints } from '../lib/chunkedUpload';

declare const route: undefined | ((name: string, params?: Record<string, unknown>) => string);

export interface ImageUploadOptions {
    // Return the temp identifier used by the backend
    getTempIdentifier: () => string;
    // Callback for non-image files
    onNonImageFile: (file: File) => void;
    // Axios instance to use
    axios?: AxiosInstance | typeof axiosDefault;
    // Optional override for chunked endpoints (SDK)
    uploadEndpoints?: UploadEndpoints;
    // Optional temp URL resolver (SDK)
    resolveTempUrl?: (data: any) => string;
}

function createUploadId() {
    return `upload-${Math.random().toString(36).slice(2)}-${Date.now()}`;
}

function renderProgressTile(percent: number, label = 'Uploading...'): string {
    const w = 200,
        h = 200;
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d') as CanvasRenderingContext2D | null;
    if (!ctx) return '';
    const safeLabel = String(label || 'Uploading...');
    const isError = /fail|error/i.test(safeLabel);
    const accent = isError ? '#ef4444' : '#3b82f6';

    ctx.fillStyle = '#f8fafc';
    ctx.fillRect(0, 0, w, h);
    ctx.strokeStyle = '#e2e8f0';
    ctx.strokeRect(0.5, 0.5, w - 1, h - 1);

    // Label
    ctx.fillStyle = isError ? '#b91c1c' : '#334155';
    ctx.font = '12px sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText(safeLabel, w / 2, 22);

    // Image icon frame
    const frameX = 60,
        frameY = 46,
        frameW = 80,
        frameH = 60;
    ctx.strokeStyle = '#cbd5e1';
    ctx.strokeRect(frameX, frameY, frameW, frameH);
    ctx.fillStyle = '#cbd5e1';
    ctx.beginPath();
    ctx.moveTo(frameX + 8, frameY + frameH - 8);
    ctx.lineTo(frameX + 32, frameY + 28);
    ctx.lineTo(frameX + 52, frameY + frameH - 8);
    ctx.closePath();
    ctx.fill();
    ctx.beginPath();
    ctx.moveTo(frameX + 28, frameY + frameH - 8);
    ctx.lineTo(frameX + 48, frameY + 40);
    ctx.lineTo(frameX + 72, frameY + frameH - 8);
    ctx.closePath();
    ctx.fill();
    ctx.beginPath();
    ctx.arc(frameX + 62, frameY + 18, 5, 0, Math.PI * 2);
    ctx.fill();

    // Progress bar
    ctx.fillStyle = '#e5e7eb';
    const pbX = 20,
        pbY = 130,
        pbW = w - 40,
        pbH = 10;
    ctx.fillRect(pbX, pbY, pbW, pbH);
    const pct = Math.max(0, Math.min(100, Math.round(percent)));
    const pw = Math.max(0, Math.min(pbW, Math.round((pct / 100) * pbW)));
    ctx.fillStyle = accent;
    ctx.fillRect(pbX, pbY, pw, pbH);
    ctx.fillStyle = '#475569';
    ctx.font = '12px sans-serif';
    ctx.fillText(`${pct}%`, w / 2, pbY + pbH + 18);
    return canvas.toDataURL('image/png');
}

function findImagePosByTitle(editor: Editor, title: string): number | null {
    const state = editor?.state;
    if (!state) return null;
    let found: number | null = null;
    state.doc.descendants((node: any, pos: number) => {
        if (node.type?.name === 'image' && node.attrs?.title === title) {
            found = pos;
            return false;
        }
        return true;
    });
    return found;
}

function insertUploadPlaceholderImage(editor: Editor, uploadId: string, filename: string) {
    // Defer to next tick to avoid dispatching while other transactions are applying
    setTimeout(() => {
        const state = editor?.state;
        const $from = state?.selection?.$from;
        const before = $from?.nodeBefore;
        const after = $from?.nodeAfter;
        const isText = (n: any) => n && n.type && n.type.name === 'text';

        const chain = editor.chain().focus();
        if (isText(before)) chain.setHardBreak();
        chain.insertContent({ type: 'image', attrs: { src: renderProgressTile(0), alt: filename, title: uploadId } });
        if (isText(after)) chain.setHardBreak();
        chain.run();
    }, 0);
}

function buildTempUrl(data: any, resolveTempUrl?: (data: any) => string): string {
    if (resolveTempUrl) return resolveTempUrl(data);
    if (data && data.url) return data.url as string;
    if (data && data.path) {
        try {
            const m = String(data.path).match(/^temp_attachments\/([^/]+)\/(.+)$/);
            if (m && typeof route === 'function') {
                return route('attachments.temp', { temp: m[1], filename: m[2] }) as string;
            }
        } catch {}
    }
    return '';
}

async function uploadImage(
    editor: Editor,
    file: File,
    opts: Required<Pick<ImageUploadOptions, 'getTempIdentifier'>> & {
        axios: AxiosInstance | typeof axiosDefault;
        uploadEndpoints?: UploadEndpoints;
        resolveTempUrl?: (data: any) => string;
    },
) {
    const uploadId = createUploadId();
    insertUploadPlaceholderImage(editor, uploadId, file.name);

    const updateProgress = (percent: number) => {
        const pos = findImagePosByTitle(editor, uploadId);
        if (pos != null) {
            const { state, dispatch } = (editor as any).view;
            const imageType = state.schema.nodes.image;
            const node = state.doc.nodeAt(pos);
            if (node) {
                const tr = state.tr.setNodeMarkup(pos, imageType, { ...node.attrs, src: renderProgressTile(percent) }, node.marks);
                setTimeout(() => dispatch(tr), 0);
            }
        }
    };

    await uploadChunkedFile({
        file,
        tempIdentifier: opts.getTempIdentifier(),
        axiosInstance: opts.axios,
        endpoints: opts.uploadEndpoints,
        onProgress: updateProgress,
    })
        .then((data) => {
            const finalUrl: string = buildTempUrl(data, opts.resolveTempUrl);
            if (!finalUrl) return;

            let done = false;
            const finishSwap = () => {
                done = true;
            };
            const trySwap = () => {
                if (done) return;
                const pos = findImagePosByTitle(editor, uploadId);
                if (pos != null) {
                    const { state, dispatch } = (editor as any).view;
                    const imageType = state.schema.nodes.image;
                    const node = state.doc.nodeAt(pos);
                    if (node) {
                        const tr = state.tr.setNodeMarkup(pos, imageType, { ...node.attrs, src: finalUrl, title: '' }, node.marks);
                        setTimeout(() => dispatch(tr), 0);
                        finishSwap();
                    }
                }
            };
            const ImgCtor: any = (globalThis as any).Image;
            const img = new ImgCtor();
            const timer: any = setTimeout(() => {
                trySwap();
            }, 2000);
            const wrappedSwap = () => {
                trySwap();
                clearTimeout(timer);
            };
            img.onload = wrappedSwap;
            img.onerror = wrappedSwap;
            img.src = finalUrl;
        })
        .catch(() => {
            const pos = findImagePosByTitle(editor, uploadId);
            if (pos != null) {
                const { state, dispatch } = (editor as any).view;
                const imageType = state.schema.nodes.image;
                const node = state.doc.nodeAt(pos);
                if (node) {
                    const tr = state.tr.setNodeMarkup(
                        pos,
                        imageType,
                        { ...node.attrs, src: renderProgressTile(0, 'Upload failed'), title: '' },
                        node.marks,
                    );
                    setTimeout(() => dispatch(tr), 0);
                }
            }
        });
}

export const ImageUpload = Extension.create<ImageUploadOptions>({
    name: 'image-upload',

    addOptions() {
        return {
            getTempIdentifier: () => Date.now().toString(),
            onNonImageFile: () => {},
            axios: axiosDefault,
            uploadEndpoints: undefined,
            resolveTempUrl: undefined,
        };
    },

    addCommands() {
        return {
            insertFiles:
                (files: File[]) =>
                ({ editor }: { editor: Editor }) => {
                    const arr = Array.from(files || []);
                    arr.forEach((f) => {
                        if (f.type && f.type.startsWith('image/')) {
                            uploadImage(editor, f, {
                                getTempIdentifier: this.options.getTempIdentifier,
                                axios: this.options.axios!,
                                uploadEndpoints: this.options.uploadEndpoints,
                                resolveTempUrl: this.options.resolveTempUrl,
                            });
                        } else {
                            this.options.onNonImageFile(f);
                        }
                    });
                    return true;
                },
            typeText:
                (text: string) =>
                ({ editor }: { editor: Editor }) => {
                    const state = editor.state;
                    const $from = state.selection.$from;
                    const before = ($from as any).nodeBefore;
                    const after = ($from as any).nodeAfter;
                    const isNextToImage = (n: any) => n && n.type && n.type.name === 'image';
                    if (isNextToImage(before) || isNextToImage(after)) {
                        setTimeout(() => editor.chain().focus().setHardBreak().insertContent(text).run(), 0);
                        return true;
                    }
                    return false;
                },
        };
    },

    addProseMirrorPlugins() {
        // We expose commands only; UI events are wired in the Vue component
        return [] as any;
    },
});

export default ImageUpload;
