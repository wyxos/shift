import TaskReportContext from '@shared/components/tasks/TaskReportContext.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

describe('TaskReportContext', () => {
    it('keeps readable paragraph boundaries and reflects the current description', async () => {
        const wrapper = mount(TaskReportContext, { props: { description: '<p>Export filters.</p><p>Keep selected columns.</p>' } });
        expect(wrapper.get('summary').text()).toContain('Export filters. Keep selected columns.');
        expect(wrapper.get('[data-testid="task-report-context-body"]').findAll('p')).toHaveLength(2);

        await wrapper.setProps({ description: '**Updated report** with the current draft.' });
        expect(wrapper.get('summary').text()).toContain('Updated report with the current draft.');
        expect(wrapper.get('[data-testid="task-report-context-body"] strong').text()).toBe('Updated report');
    });

    it('preserves rich report content while removing unsafe markup', () => {
        const wrapper = mount(TaskReportContext, {
            props: {
                description:
                    '<p>See <a href="https://example.test/report">the report</a>.</p><pre><code>export()</code></pre><script>bad()</script><img src="/report.png" onerror="bad()">',
            },
        });
        expect(wrapper.find('script').exists()).toBe(false);
        expect(wrapper.get('a').attributes('href')).toBe('https://example.test/report');
        expect(wrapper.get('pre code').text()).toBe('export()');
        expect(wrapper.get('img').attributes('onerror')).toBeUndefined();
    });

    it('omits empty descriptions but keeps image-only reports available', async () => {
        const wrapper = mount(TaskReportContext, { props: { description: '<p><br></p>' } });
        expect(wrapper.find('[data-testid="task-report-context"]').exists()).toBe(false);
        await wrapper.setProps({ description: '<p><img src="/report.png"></p>' });
        expect(wrapper.find('[data-testid="task-report-context"]').exists()).toBe(true);
        expect(wrapper.get('summary').text()).toBe('Description');
    });
});
