import type { ObjectDirective } from 'vue';

const TILE_SIZE = 180;
const MAX_WIDE_WIDTH = 612;

export type ImageTileLayout = { width: number; height: number };

export function imageTileLayout(width: number, height: number): ImageTileLayout {
    if (width <= height || height <= 0) return { width: TILE_SIZE, height: TILE_SIZE };
    const tileWidth = Math.min(MAX_WIDE_WIDTH, Math.max(TILE_SIZE, (width / height) * TILE_SIZE));
    return { width: tileWidth, height: tileWidth * (height / width) };
}

function layoutImage(image: HTMLImageElement): void {
    if (!image.complete || !image.naturalWidth || !image.naturalHeight) return;
    const layout = imageTileLayout(image.naturalWidth, image.naturalHeight);
    image.style.width = `${layout.width}px`;
    image.style.height = 'auto';
    image.style.aspectRatio = `${layout.width} / ${layout.height}`;
    image.style.maxWidth = '100%';
    image.style.boxSizing = 'border-box';
    image.style.objectFit = 'scale-down';
    image.style.objectPosition = 'center';
    image.style.padding = '0px';
}

type TileObserver = {
    observer: MutationObserver;
    onLoad: (event: Event) => void;
};

const observers = new WeakMap<HTMLElement, TileObserver>();
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
