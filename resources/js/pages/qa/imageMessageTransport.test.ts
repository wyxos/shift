import { uploadChunkedFile } from '@/shared/lib/chunkedUpload';
import { describe, expect, it } from 'vitest';
import { createImageMessageTransport } from './imageMessageTransport';

describe('image-message QA transport', () => {
    it('keeps selected file bytes in memory and maps the upload into a sent attachment', async () => {
        const transport = createImageMessageTransport();
        const file = new File(['synthetic image bytes'], 'fixture.png', { type: 'image/png' });
        const result = await uploadChunkedFile({
            file,
            tempIdentifier: 'local-qa',
            axiosInstance: transport.client,
            endpoints: transport.endpoints,
        });
        expect(result.url).toBe(`data:image/png;base64,${btoa('synthetic image bytes')}`);
        expect(
            transport.mapAttachments([{ name: file.name, size: file.size, type: file.type, path: result.path, status: 'done', progress: 100 }]),
        ).toEqual([{ id: result.path, url: result.url, original_filename: file.name, mime_type: file.type }]);
        await transport.client.delete(transport.removeUrl, { params: { path: result.path } });
        expect(
            transport.mapAttachments([{ name: file.name, size: file.size, type: file.type, path: result.path, status: 'done', progress: 100 }]),
        ).toEqual([]);
    });

    it('rejects every unsupported operation instead of falling through to the network', async () => {
        const transport = createImageMessageTransport();
        await expect(transport.client.post('/tasks/1/threads', { content: 'never sent' })).rejects.toThrow('blocks unsupported requests');
        await expect(transport.client.get('https://example.com')).rejects.toThrow('blocks unsupported requests');
        await expect(transport.client.get(transport.endpoints.init)).rejects.toThrow('blocks unsupported requests');
    });
});
