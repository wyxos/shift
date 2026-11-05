import { imageTileLayout, imageTiles } from '@shared/tasks/image-tile';
import { describe, expect, it } from 'vitest';

describe('shared/tasks/image-tile', () => {
    it('fits wide images in their full proportions instead of cropping', () => {
        expect(imageTileLayout(1600, 400)).toEqual({ width: 612, height: 153 });
        expect(imageTileLayout(2400, 120)).toEqual({ width: 612, height: 30.6 });
    });

    it('uses a square tile for tiny, square and portrait sources without enlarging their pixels', () => {
        for (const [width, height] of [
            [64, 64],
            [160, 160],
            [800, 800],
            [2400, 2400],
            [400, 1600],
        ]) {
            expect(imageTileLayout(width, height)).toEqual({ width: 180, height: 180 });
        }
    });

    it('lays out existing and newly loaded images without cropping or upscaling', async () => {
        const root = document.createElement('div');
        const image = document.createElement('img');
        image.className = 'editor-tile';
        Object.defineProperties(image, {
            complete: { value: true },
            naturalWidth: { value: 1600 },
            naturalHeight: { value: 400 },
        });
        root.append(image);
        (imageTiles.mounted as (root: HTMLElement) => void)(root);
        expect(image.style.width).toBe('612px');
        expect(image.style.aspectRatio).toBe('612 / 153');
        expect(image.style.objectFit).toBe('scale-down');
        expect(image.style.padding).toBe('0px');
        const tiny = document.createElement('img');
        tiny.className = 'editor-tile';
        Object.defineProperties(tiny, {
            complete: { value: true },
            naturalWidth: { value: 64 },
            naturalHeight: { value: 64 },
        });
        root.append(tiny);
        await Promise.resolve();
        expect(tiny.style.width).toBe('180px');
        expect(tiny.style.objectFit).toBe('scale-down');
        (imageTiles.unmounted as (root: HTMLElement) => void)(root);
    });
});
