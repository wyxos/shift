import ShiftEditor from '@/components/ShiftEditor.vue';
import Input from '@/components/ui/input/Input.vue';
import { Select } from '@/components/ui/select';
import TaskCreateForm from '@shared/components/TaskCreateForm.vue';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { nextTick } from 'vue';

function expectBorderOnlyFocus(classes: string[]) {
    expect(classes).toContain('focus-visible:border-ring');
    expect(classes.filter((className) => /^focus-visible:ring/.test(className))).toEqual([]);
    expect(classes.filter((className) => className.includes('ring-offset'))).toEqual([]);
    expect(classes.filter((className) => /^focus-visible:(shadow|drop-shadow|blur)/.test(className))).toEqual([]);
    expect(classes.filter((className) => /^shadow/.test(className))).toEqual([]);
    expect(classes).not.toContain('transition-[color,box-shadow]');
}

function expectBorderOnlyFocusSource(source: string) {
    expect(source).toContain('focus-visible:border-ring');
    expect(source).not.toMatch(/focus-visible:ring/);
    expect(source).not.toMatch(/ring-offset/);
    expect(source).not.toMatch(/focus-visible:(shadow|drop-shadow|blur)/);
    expect(source).not.toMatch(/\bshadow-(xs|sm|md|lg|xl|2xl)\b/);
    expect(source).not.toContain('transition-[color,box-shadow]');
}

function styleRule(source: string, selector: string) {
    const escapedSelector = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return source.match(new RegExp(`${escapedSelector}\\s*\\{([\\s\\S]*?)\\n\\}`))?.[1] ?? '';
}

describe('field focus styles', () => {
    it('keeps the input primitive focus treatment to a border change', () => {
        const wrapper = mount(Input);

        expectBorderOnlyFocus(wrapper.get('input').classes());
    });

    it('keeps select trigger and search field focus treatment to a border change', async () => {
        const wrapper = mount(Select, {
            attachTo: document.body,
            props: {
                modelValue: null,
                options: [
                    { value: 1, label: 'Atlas Commerce' },
                    { value: 2, label: 'Cedar Labs' },
                ],
                searchable: true,
                testId: 'project-select',
            },
        });

        expectBorderOnlyFocus(wrapper.get('[data-testid="project-select"]').classes());

        await wrapper.get('[data-testid="project-select"]').trigger('click');
        await nextTick();

        const searchInput = document.body.querySelector('[data-testid="project-select-search"]') as HTMLInputElement;
        expectBorderOnlyFocus(Array.from(searchInput.classList));

        wrapper.unmount();
    });

    it('keeps shared task title fields aligned with the primitive focus treatment', () => {
        const wrapper = mount(TaskCreateForm, {
            props: {
                modelValue: {
                    title: '',
                    priority: 'medium',
                    description: '',
                },
                tempIdentifier: 'temp-test-id',
            },
            global: {
                stubs: {
                    ButtonGroup: { template: '<div />' },
                    ShiftEditor: { template: '<div />' },
                },
            },
        });

        expectBorderOnlyFocus(wrapper.get('[data-testid="create-task-title"]').classes());
    });

    it('keeps portal task edit title fields aligned with the primitive focus treatment', () => {
        const source = readFileSync(join(process.cwd(), 'resources/js/components/tasks/index/TaskEditSheet.vue'), 'utf8');
        const titleInputIndex = source.indexOf('data-testid="task-edit-title"');
        const titleInputSource = source.slice(Math.max(0, titleInputIndex - 800), titleInputIndex + 300);

        expect(titleInputIndex).toBeGreaterThan(-1);
        expectBorderOnlyFocusSource(titleInputSource);
        expect(titleInputSource).toContain('border-input');
        expect(titleInputSource).not.toContain('border-transparent');
    });

    it('keeps the shared rich editor neutral until focus changes only the border', () => {
        const source = readFileSync(join(process.cwd(), 'resources/js/shared/components/ShiftEditor.vue'), 'utf8');
        const baseRule = styleRule(source, '.ProseMirror');
        const focusedRule = styleRule(source, '.tiptap.is-focused .ProseMirror');

        expect(ShiftEditor).toBeTruthy();
        expect(baseRule).toContain('border-color: var(--input);');
        expect(baseRule).not.toMatch(/border-blue-\d+/);
        expect(baseRule).not.toMatch(/\bring-/);
        expect(baseRule).not.toMatch(/\bshadow-/);

        expect(focusedRule).toContain('border-color: var(--ring);');
        expect(focusedRule).not.toMatch(/\bring-/);
        expect(focusedRule).not.toMatch(/\bshadow-/);
    });

    it('keeps editor and rendered rich content paragraph spacing aligned', () => {
        const source = readFileSync(join(process.cwd(), 'resources/css/app.css'), 'utf8');

        expect(source).toMatch(/\.ProseMirror > p \+ p,\s*\.shift-rich > p \+ p\s*\{[\s\S]*?margin-top:\s*0\.75rem;/);
    });

    it('keeps editor and rendered rich content code blocks aligned', () => {
        const source = readFileSync(join(process.cwd(), 'resources/css/app.css'), 'utf8');

        expect(source).toMatch(/\.ProseMirror pre,\s*\.shift-rich pre\s*\{[\s\S]*?padding:\s*0\.5rem 0\.625rem;/);
        expect(source).toMatch(/\.ProseMirror pre,\s*\.shift-rich pre\s*\{[\s\S]*?border-radius:\s*0\.25rem;/);
        expect(source).toMatch(/\.ProseMirror pre,\s*\.shift-rich pre\s*\{[\s\S]*?overflow-x:\s*auto;/);
        expect(source).toMatch(/\.ProseMirror pre,\s*\.shift-rich pre\s*\{[\s\S]*?background:\s*#e5e7eb;/);
        expect(source).toMatch(/\.ProseMirror pre code,\s*\.shift-rich pre code\s*\{[\s\S]*?white-space:\s*pre;/);
        expect(source).toMatch(/\.ProseMirror pre code\.hljs,\s*\.shift-rich pre code\.hljs\s*\{[\s\S]*?padding:\s*0;/);
    });

    it('keeps shared rich editor code blocks compact', () => {
        const source = readFileSync(join(process.cwd(), 'resources/js/shared/components/ShiftEditor.vue'), 'utf8');
        const preRule = styleRule(source, '.tiptap pre');

        expect(preRule).toContain('border-radius: 0.25rem;');
        expect(preRule).toContain('padding: 0.5rem 0.625rem;');
    });
});
