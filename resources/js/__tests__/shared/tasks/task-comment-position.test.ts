import TaskCommentMenu from '@/shared/components/tasks/TaskCommentMenu.vue';
import { mount } from '@vue/test-utils';
import { afterEach, expect, it, vi } from 'vitest';

let cleanup = () => {};
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
});

it('repositions an active palette as a clipped message scrolls in both directions', async () => {
    const history = document.createElement('div');
    history.className = 'task-comments-history';
    document.body.append(history);
    let top = -100;
    let height = 600;
    history.getBoundingClientRect = () => ({ top: 160, bottom: 800, height: 640 }) as DOMRect;
    const wrapper = mount(TaskCommentMenu, {
        attachTo: history,
        props: { label: 'Comment actions', triggerId: 'actions' },
        slots: { default: '<div>Long message</div>', 'quick-actions': '<div class="task-comment-reaction-palette">👍</div>' },
    });
    cleanup = () => wrapper.unmount();
    const menu = wrapper.element as HTMLElement;
    const palette = menu.querySelector<HTMLElement>('.task-comment-reaction-palette')!;
    menu.getBoundingClientRect = () => ({ top, bottom: top + height, height }) as DOMRect;
    palette.getBoundingClientRect = () => ({ height: 38 }) as DOMRect;
    await wrapper.trigger('pointerenter');
    const paletteTop = () => top + Number.parseFloat(menu.style.getPropertyValue('--quick-actions-offset'));
    expect(paletteTop()).toBeGreaterThanOrEqual(160);
    top = 120;
    history.dispatchEvent(new Event('scroll'));
    expect(paletteTop()).toBeLessThanOrEqual(168);
    top = -180;
    history.dispatchEvent(new Event('scroll'));
    expect(paletteTop()).toBeGreaterThanOrEqual(160);
    expect(paletteTop()).toBeLessThanOrEqual(168);
    top = -700;
    history.dispatchEvent(new Event('scroll'));
    expect(menu.style.getPropertyValue('--quick-actions-visibility')).toBe('hidden');
    top = 300;
    height = 32;
    window.dispatchEvent(new Event('resize'));
    expect(menu.style.getPropertyValue('--quick-actions-visibility')).toBe('visible');
    expect(paletteTop()).toBe(262);
    await wrapper.trigger('focusin');
    await wrapper.trigger('pointerleave');
    top = 400;
    history.dispatchEvent(new Event('scroll'));
    expect(paletteTop()).toBe(362);
    await wrapper.trigger('focusout', { relatedTarget: document.body });
    const offset = menu.style.getPropertyValue('--quick-actions-offset');
    top = 500;
    history.dispatchEvent(new Event('scroll'));
    expect(menu.style.getPropertyValue('--quick-actions-offset')).toBe(offset);
});
