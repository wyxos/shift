import type { ObjectDirective } from 'vue';

const TILE_SIZE = 200;
const MIN_VISIBLE_SIDE = TILE_SIZE * 0.4;
const MIN_SMALL_SIDE = TILE_SIZE * 0.8;
const SMALL_PADDING = (TILE_SIZE - MIN_SMALL_SIDE) / 2;
const CROP_PADDING = (TILE_SIZE - MIN_VISIBLE_SIDE) / 2;

export type ImageTileLayout = {
    fit: 'contain' | 'cover' | 'none';
    padding: string;
};

export function imageTileLayout(width: number, height: number): ImageTileLayout {
    const longest = Math.max(width, height);
    const shortest = Math.min(width, height);

    if (longest <= TILE_SIZE) {
        return longest < MIN_SMALL_SIDE ? { fit: 'contain', padding: `${SMALL_PADDING}px` } : { fit: 'none', padding: '0px' };
    }

    if ((shortest / longest) * TILE_SIZE < MIN_VISIBLE_SIDE) {
        return width > height ? { fit: 'cover', padding: `${CROP_PADDING}px 0px` } : { fit: 'cover', padding: `0px ${CROP_PADDING}px` };
    }

    return { fit: 'contain', padding: '0px' };
}

function layoutImage(image: HTMLImageElement): void {
    if (!image.complete || !image.naturalWidth || !image.naturalHeight) return;

    const source = image.currentSrc || image.src;
    if (laidOutImages.get(image) === source) return;

    const layout = imageTileLayout(image.naturalWidth, image.naturalHeight);
    image.style.width = `${TILE_SIZE}px`;
    image.style.height = `${TILE_SIZE}px`;
    image.style.maxWidth = '100%';
    image.style.boxSizing = 'border-box';
    image.style.objectFit = layout.fit;
    image.style.objectPosition = layout.fit === 'cover' && source ? focusPosition(image) : 'center';
    image.style.padding = layout.padding;
    laidOutImages.set(image, source);
}

type TileObserver = {
    observer: MutationObserver;
    onLoad: (event: Event) => void;
};

const observers = new WeakMap<HTMLElement, TileObserver>();
const laidOutImages = new WeakMap<HTMLImageElement, string>();

function focusPosition(image: HTMLImageElement): string {
    const wide = image.naturalWidth > image.naturalHeight;
    const longest = Math.max(image.naturalWidth, image.naturalHeight);
    const shortest = Math.min(image.naturalWidth, image.naturalHeight);
    const fallback = 'center';

    // A geometric center crop can be empty for screenshots with controls at one edge.
    // Find the most detailed crop while keeping the chosen part centered in the tile.
    try {
        const canvas = document.createElement('canvas');
        const scale = Math.min(1, 512 / longest);
        canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
        const context = canvas.getContext('2d', { willReadFrequently: true });
        if (!context) return fallback;

        context.drawImage(image, 0, 0, canvas.width, canvas.height);
        const pixels = context.getImageData(0, 0, canvas.width, canvas.height).data;
        const length = wide ? canvas.width : canvas.height;
        const depth = wide ? canvas.height : canvas.width;
        const visible = Math.min(length, Math.max(1, Math.round(shortest * (TILE_SIZE / MIN_VISIBLE_SIDE) * scale)));
        const scores = new Float64Array(length);

        for (let position = 0; position < length; position += 1) {
            for (let cross = 0; cross < depth; cross += 1) {
                const index = (wide ? cross * canvas.width + position : position * canvas.width + cross) * 4;
                const red = pixels[index];
                const green = pixels[index + 1];
                const blue = pixels[index + 2];
                const opacity = pixels[index + 3] / 255;
                const chroma = Math.max(red, green, blue) - Math.min(red, green, blue);
                const darkness = 255 - (red + green + blue) / 3;
                scores[position] += (chroma * 2 + darkness * 0.2) * opacity;
            }
        }

        let windowScore = 0;
        for (let index = 0; index < visible; index += 1) windowScore += scores[index];
        const centerStart = Math.round((length - visible) / 2);
        let centerScore = 0;
        let bestScore = -1;
        let bestStart = centerStart;

        for (let start = 0; start <= length - visible; start += 1) {
            if (start > 0) windowScore += scores[start + visible - 1] - scores[start - 1];
            if (start === centerStart) centerScore = windowScore;
            if (
                windowScore > bestScore + 0.001 ||
                (Math.abs(windowScore - bestScore) <= 0.001 && Math.abs(start - centerStart) < Math.abs(bestStart - centerStart))
            ) {
                bestScore = windowScore;
                bestStart = start;
            }
        }

        if (bestScore < 1 || bestScore < centerScore * 1.1) return fallback;

        const focus = ((bestStart + visible / 2) / length) * longest;
        const scaledLength = (longest / shortest) * MIN_VISIBLE_SIDE;
        const offset = (focus * (MIN_VISIBLE_SIDE / shortest) - TILE_SIZE / 2) / (scaledLength - TILE_SIZE);
        const percent = `${Math.round(Math.max(0, Math.min(1, offset)) * 100)}%`;
        return wide ? `${percent} center` : `center ${percent}`;
    } catch {
        // Cross-origin images cannot be sampled; keep their geometric center.
        return fallback;
    }
}

function refreshImages(root: HTMLElement): void {
    root.querySelectorAll<HTMLImageElement>('img.editor-tile').forEach(layoutImage);
}

export const imageTiles: ObjectDirective<HTMLElement> = {
    mounted(root) {
        const onLoad = (event: Event) => {
            if (event.target instanceof HTMLImageElement && event.target.classList.contains('editor-tile')) {
                layoutImage(event.target);
            }
        };
        const observer = new MutationObserver(() => refreshImages(root));

        root.addEventListener('load', onLoad, true);
        observer.observe(root, { childList: true, subtree: true, attributes: true, attributeFilter: ['src'] });
        observers.set(root, { observer, onLoad });
        refreshImages(root);
    },
    updated(root) {
        refreshImages(root);
    },
    unmounted(root) {
        const current = observers.get(root);
        if (!current) return;

        current.observer.disconnect();
        root.removeEventListener('load', current.onLoad, true);
        observers.delete(root);
    },
};
