import { imageTileLayout, imageTiles } from '@shared/tasks/image-tile';
import { describe, expect, it } from 'vitest';

describe('shared/tasks/image-tile', () => {
    it('fits large images and crops only when the fitted short side falls below 40%', () => {
        expect(imageTileLayout(800, 500)).toEqual({ fit: 'contain', padding: '0px' });
        expect(imageTileLayout(500, 200)).toEqual({ fit: 'contain', padding: '0px' });
        expect(imageTileLayout(866, 50)).toEqual({ fit: 'cover', padding: '60px 0px' });
        expect(imageTileLayout(50, 866)).toEqual({ fit: 'cover', padding: '0px 60px' });
    });

    it('keeps near-size images native and scales smaller images up to 80% of the tile', () => {
        expect(imageTileLayout(180, 170)).toEqual({ fit: 'none', padding: '0px' });
        expect(imageTileLayout(160, 150)).toEqual({ fit: 'none', padding: '0px' });
        expect(imageTileLayout(100, 90)).toEqual({ fit: 'contain', padding: '20px' });
    });

    it('lays out existing and newly inserted editor images from their intrinsic dimensions', async () => {
        const root = document.createElement('div');
        const existing = document.createElement('img');
        existing.className = 'editor-tile';
        Object.defineProperties(existing, {
            complete: { value: true },
            naturalWidth: { value: 866 },
            naturalHeight: { value: 50 },
        });
        root.append(existing);

        (imageTiles.mounted as (root: HTMLElement) => void)(root);
        expect(existing.style.objectFit).toBe('cover');
        expect(existing.style.padding).toBe('60px 0px');

        const next = document.createElement('img');
        next.className = 'editor-tile';
        Object.defineProperties(next, {
            complete: { value: true },
            naturalWidth: { value: 100 },
            naturalHeight: { value: 90 },
        });
        root.append(next);
        await Promise.resolve();

        expect(next.style.objectFit).toBe('contain');
        expect(next.style.padding).toBe('20px');
        (imageTiles.unmounted as (root: HTMLElement) => void)(root);
    });
});
