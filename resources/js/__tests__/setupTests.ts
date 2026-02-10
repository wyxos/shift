// Global test setup for Vitest (jsdom)

// Silence unknown custom elements like <emoji-picker>
if (typeof window !== 'undefined' && 'customElements' in window) {
    try {
        if (!customElements.get('emoji-picker')) {
            customElements.define('emoji-picker', class extends HTMLElement {});
        }
    } catch {
        // ignore
    }
}
