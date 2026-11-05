import { renderThreadContent } from '@shared/tasks/rich-content';
import { describe, expect, it } from 'vitest';

const image = '<img src="/image.png" alt="Example">';
function render(content: string) {
    const root = document.createElement('div');
    root.innerHTML = renderThreadContent(content);
    return root;
}
describe('message image grouping', () => {
    it('groups consecutive images with whitespace into a single wrapping row', () => {
        const root = render(`<p>${image} \n ${image}${image}</p>`);
        expect(root.querySelectorAll('.shift-image-run')).toHaveLength(1);
        expect(root.querySelector('.shift-image-run')?.querySelectorAll('img')).toHaveLength(3);
    });
    it('preserves explicit hard breaks and paragraph boundaries', () => {
        const root = render(`<p>${image}<br>${image}</p><p>${image}</p>`);
        expect(root.querySelectorAll('.shift-image-run')).toHaveLength(3);
        expect(root.querySelector('br')).not.toBeNull();
        expect(root.children).toHaveLength(2);
    });
    it('keeps captions in place and treats each intervening caption as a row boundary', () => {
        const root = render(`<p>Before${image}<strong>Caption</strong>${image}After</p>`);
        expect(root.querySelectorAll('.shift-image-run')).toHaveLength(2);
        expect(root.querySelector('p')?.childNodes[0].textContent).toBe('Before');
        expect(root.querySelector('strong')?.previousElementSibling?.querySelector('img')).not.toBeNull();
        expect(root.querySelector('strong')?.nextElementSibling?.querySelector('img')).not.toBeNull();
        expect(root.textContent).toBe('BeforeCaptionAfter');
    });
    it('normalizes legacy images after sanitization without trusting stored layout classes', () => {
        const root = render(`<p class="evil">${image}<img src="javascript:alert(1)" onerror="alert(1)"></p>`);
        expect(root.querySelectorAll('img.editor-tile')).toHaveLength(1);
        expect(root.querySelector('.evil')).toBeNull();
        expect(root.querySelector('[onerror]')).toBeNull();
    });
});

it('normalizes bare legacy images and captions at the document fragment boundary', () => {
    const root = render(`${image}Caption${image}${image}`);
    expect(root.querySelectorAll('.shift-image-run')).toHaveLength(2);
    expect(root.querySelectorAll('.shift-image-run')[1].querySelectorAll('img')).toHaveLength(2);
    expect(root.childNodes[1].textContent).toBe('Caption');
});
