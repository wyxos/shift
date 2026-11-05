import type { SentAttachment } from '@/shared/components/shift-editor/types';
import axios, { type AxiosResponse, type InternalAxiosRequestConfig } from 'axios';
import type { QaAttachment } from './imageMessageCases';

type Upload = { name: string; type: string; chunks: Blob[]; url?: string };

function dataUrl(blob: Blob): Promise<string> {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result));
        reader.onerror = () => reject(new Error('Unable to read this local fixture.'));
        reader.readAsDataURL(blob);
    });
}

export function createImageMessageTransport() {
    const uploads = new Map<string, Upload>();
    let sequence = 0;
    const endpoints = {
        init: '/qa-fixture/upload/init',
        status: '/qa-fixture/upload/status',
        chunk: '/qa-fixture/upload/chunk',
        complete: '/qa-fixture/upload/complete',
    };
    const removeUrl = '/qa-fixture/upload/remove';
    const client = axios.create({
        adapter: async (config: InternalAxiosRequestConfig): Promise<AxiosResponse> => {
            let data: unknown;
            const body = typeof config.data === 'string' ? JSON.parse(config.data) : config.data;
            if (config.url === endpoints.init && config.method === 'post') {
                const id = `fixture-${++sequence}`;
                uploads.set(id, { name: body.filename, type: body.mime_type || 'application/octet-stream', chunks: [] });
                data = {
                    upload_id: id,
                    chunk_size: 5 * 1024 * 1024,
                    total_chunks: Math.ceil(body.size / (5 * 1024 * 1024)),
                    max_bytes: 40 * 1024 * 1024,
                };
            } else if (config.url === endpoints.status && config.method === 'get') {
                data = { uploaded_chunks: [], total_chunks: 1, chunk_size: 5 * 1024 * 1024 };
            } else if (config.url === endpoints.chunk && config.method === 'post' && body instanceof FormData) {
                const upload = uploads.get(String(body.get('upload_id')));
                const chunk = body.get('chunk');
                if (!upload || !(chunk instanceof Blob)) throw new Error('Unknown local upload.');
                upload.chunks[Number(body.get('chunk_index'))] = chunk;
                data = {};
            } else if (config.url === endpoints.complete && config.method === 'post') {
                const upload = uploads.get(body.upload_id);
                if (!upload) throw new Error('Unknown local upload.');
                upload.url = await dataUrl(new Blob(upload.chunks, { type: upload.type }));
                data = { path: body.upload_id, url: upload.url, original_filename: upload.name };
            } else if (config.url === removeUrl && config.method === 'delete') {
                uploads.delete(String(config.params?.path));
                data = {};
            } else {
                throw new Error('The QA sandbox blocks unsupported requests.');
            }
            return { data, status: 200, statusText: 'OK', headers: {}, config };
        },
    });
    function mapAttachments(attachments: SentAttachment[]): QaAttachment[] {
        return attachments.flatMap((attachment) => {
            const upload = uploads.get(attachment.path ?? '');
            return upload?.url ? [{ id: attachment.path!, url: upload.url, original_filename: upload.name, mime_type: upload.type }] : [];
        });
    }
    return { client, endpoints, removeUrl, mapAttachments };
}
