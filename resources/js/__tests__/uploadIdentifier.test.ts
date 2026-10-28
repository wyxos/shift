import { createUploadIdentifier } from '@shared/lib/uploadIdentifier';
import { describe, expect, it, vi } from 'vitest';

describe('temporary upload identifiers', () => {
    it('uses 128 bits of browser randomness without requiring secure-context randomUUID', () => {
        const bytes = new Uint8Array(Array.from({ length: 16 }, (_, index) => index));
        const getRandomValues = vi.fn((target: Uint8Array) => {
            target.set(bytes);
            return target;
        });
        vi.stubGlobal('crypto', { getRandomValues });
        try {
            expect(createUploadIdentifier()).toBe('000102030405060708090a0b0c0d0e0f');
            expect(getRandomValues).toHaveBeenCalledOnce();
            expect(getRandomValues.mock.calls[0][0]).toHaveLength(16);
        } finally {
            vi.unstubAllGlobals();
        }
    });
});
