/** Add presentation-only rows after rich HTML has been sanitized. Never rewrite editor DOM. */
export function groupMessageImages(root: ParentNode): void {
    root.querySelectorAll('img').forEach((image) => {
        image.classList.add('editor-tile');
        image.tabIndex = 0;
        image.setAttribute('role', 'button');
        image.setAttribute('aria-label', `Open image ${image.alt || 'attachment'}`);
    });
    const parents = new Set(Array.from(root.querySelectorAll('img'), (image) => image.parentNode).filter(Boolean));
    for (const parent of parents) {
        if (!parent) continue;
        let run: HTMLSpanElement | null = null;
        for (const node of Array.from(parent.childNodes)) {
            if (node instanceof HTMLImageElement) {
                if (!run) {
                    run = document.createElement('span');
                    run.className = 'shift-image-run';
                    parent.insertBefore(run, node);
                }
                run.append(node);
            } else if (node.nodeType === Node.TEXT_NODE && !node.textContent?.trim()) {
                // Whitespace between images does not introduce a row break.
                if (run && node.nextSibling instanceof HTMLImageElement) node.remove();
            } else {
                run = null;
            }
        }
    }
}
