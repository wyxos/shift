import TaskErrorSourceContext from '@/components/tasks/index/TaskErrorSourceContext.vue';
import { highlightSource } from '@/shared/tasks/source-highlighting';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const texts = (result: ReturnType<typeof highlightSource>) => result.lines.map((line) => line.map((token) => token.text).join(''));

describe('source context highlighting', () => {
    it.each([
        ['app/Service.php', 'PHP', '$result = true;'],
        ['src/store.ts', 'TypeScript', 'const title: string = "Example";'],
        ['src/client.js', 'JavaScript', 'const ready = true;'],
        ['config.json', 'JSON', '{ "enabled": true }'],
        ['styles.css', 'CSS', 'body { color: red; }'],
        ['index.html', 'HTML', '<main>Example</main>'],
        ['view.xml', 'XML', '<entry>Example</entry>'],
        ['worker.py', 'Python', 'return True'],
        ['notes.txt', 'Plain text', 'Text without a known source language.'],
    ])('recognizes %s without altering source text', (file, language, code) => {
        const result = highlightSource(file, [code]);
        expect(result.language).toBe(language);
        expect(texts(result)).toEqual([code]);
    });

    it('recognizes Vue script excerpts as TypeScript and template excerpts as HTML', () => {
        const script = highlightSource('sdk/ui/src/TaskList.vue', ['const taskName = task.title.trim();']);
        expect(script.language).toBe('Vue · TypeScript');
        expect(script.lines[0].some((token) => token.classes.includes('hljs-keyword'))).toBe(true);
        const template = highlightSource('sdk/ui/src/TaskList.vue', ['<template>', '  <div>{{ title }}</div>', '</template>']);
        expect(template.language).toBe('Vue · HTML');
        expect(texts(template)).toEqual(['<template>', '  <div>{{ title }}</div>', '</template>']);
    });

    it('retains syntax state across numbered lines and Vue sections', () => {
        const lines = [
            '<script setup lang="ts">',
            '/* A multiline comment',
            '   continues here. */',
            'const title: string = "Hello";',
            '</script>',
            '<template><div>{{ title }}</div></template>',
        ];
        const result = highlightSource('Example.vue', lines);
        expect(result.language).toBe('Vue · TypeScript');
        expect(texts(result)).toEqual(lines);
        expect(result.lines[1].some((token) => token.classes.includes('hljs-comment'))).toBe(true);
        expect(result.lines[2].some((token) => token.classes.includes('hljs-comment'))).toBe(true);
        expect(result.lines[3].some((token) => token.classes.includes('hljs-keyword'))).toBe(true);
        expect(result.lines[5].some((token) => token.classes.includes('hljs-name'))).toBe(true);
    });

    it('renders code as safe text and syntax spans while preserving line numbers and the active row', () => {
        const hostile = 'const html = "<img src=x onerror=alert(1)><script>alert(1)</script>";';
        const wrapper = mount(TaskErrorSourceContext, {
            props: {
                frame: {
                    file: 'snippet.ts',
                    context: {
                        start_line: 20,
                        lines: [
                            { number: 20, text: hostile, active: true },
                            { number: 21, text: '' },
                        ],
                    },
                },
            },
        });
        expect(wrapper.get('[data-testid="error-source-language"]').text()).toBe('TypeScript');
        expect(wrapper.get('[data-source-line="20"]').attributes('data-active')).toBe('true');
        expect(wrapper.get('[data-source-line="20"] code').text()).toBe(hostile);
        expect(wrapper.findAll('[data-source-line]')).toHaveLength(2);
        expect(wrapper.get('[data-source-line="21"]').text()).toBe('21');
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('script').exists()).toBe(false);
        expect(wrapper.findAll('.hljs-keyword').length).toBeGreaterThan(0);
        expect(wrapper.get('code').classes()).toContain('whitespace-pre-wrap');
    });

    it('keeps unrecognized or oversized source readable without guessing or parsing it', () => {
        const unknown = '<svg onload=alert(1)>A plain source excerpt.</svg>';
        const result = highlightSource('unknown.data', [unknown]);
        expect(result.language).toBe('Plain text');
        expect(result.lines[0]).toEqual([{ text: unknown, classes: [] }]);
        const large = 'const value = true;'.repeat(3000);
        expect(highlightSource('large.ts', [large]).lines[0]).toEqual([{ text: large, classes: [] }]);
    });
});
