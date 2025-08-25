import { createApp, h, nextTick } from 'vue'

export function svgToDataUrl(svg: SVGElement): string {
  const markup = new XMLSerializer().serializeToString(svg);
  return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(markup)}`;
}

export function renderTileToDataUrl(Component: any, props: Record<string, any> = {}): Promise<string> {
  return new Promise((resolve) => {
    const container = document.createElement('div');
    // Ensure not visible
    container.style.position = 'fixed';
    container.style.left = '-10000px';
    container.style.top = '-10000px';
    document.body.appendChild(container);

    const app = createApp({
      render() {
        return h(Component, { ...props });
      },
      mounted() {
        nextTick(() => {
          const svg = container.querySelector('svg');
          const dataUrl = svg ? svgToDataUrl(svg as SVGElement) : '';
          app.unmount();
          container.remove();
          resolve(dataUrl);
        });
      },
    });

    app.mount(container);
  });
}

