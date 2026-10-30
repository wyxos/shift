import TaskErrorSourceMapStatus from '@/components/tasks/index/TaskErrorSourceMapStatus.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

describe('TaskErrorSourceMapStatus', () => {
    it('shows the loaded UI build and original source revision', () => {
        const build = 'a'.repeat(64);
        const revision = 'b'.repeat(40);
        const wrapper = mount(TaskErrorSourceMapStatus, {
            props: { buildId: build, resolution: { status: 'resolved', sdk_revision: revision, matched_frames: 2, total_frames: 2 } },
        });
        expect(wrapper.get('[data-testid="error-ui-build"]').text()).toBe(build);
        expect(wrapper.get('[data-testid="error-source-map-label"]').text()).toBe('Original source resolved');
        expect(wrapper.text()).toContain(revision.slice(0, 12));
    });

    it.each([
        ['partial', '1 of 3 frames resolved'],
        ['missing_build', 'Build not registered'],
        ['invalid_artifact', 'Source maps unavailable'],
        ['unmapped', 'No matching source positions'],
        ['no_frames', 'No stack positions captured'],
    ])('makes %s source resolution explicit', (status, label) => {
        const wrapper = mount(TaskErrorSourceMapStatus, {
            props: { buildId: 'a'.repeat(64), resolution: { status, matched_frames: 1, total_frames: 3 } },
        });
        expect(wrapper.get('[data-testid="error-source-map-label"]').text()).toBe(label);
    });

    it('identifies legacy reports without a frontend build', () => {
        const wrapper = mount(TaskErrorSourceMapStatus);
        expect(wrapper.text()).toContain('Not recorded');
        expect(wrapper.text()).toContain('UI build not identified');
    });
});
