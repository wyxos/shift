import TaskErrorOccurrencesPane from '@/components/tasks/index/TaskErrorOccurrencesPane.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { h, nextTick, reactive } from 'vue';

vi.mock('@/components/ui/button', () => ({
    Button: {
        props: ['disabled', 'size', 'variant'],
        render() {
            return h(
                'button',
                {
                    ...this.$attrs,
                    class: ['button-stub', this.size, this.variant],
                    disabled: this.disabled,
                },
                this.$slots.default?.(),
            );
        },
    },
}));

function makeState(overrides: Record<string, unknown> = {}) {
    return {
        editTask: { id: 107 },
        errorOccurrences: [
            {
                id: 31,
                number: 2,
                received_at: '2026-10-08T10:00:00Z',
                source: 'backend',
                environment: 'local',
                message: 'Primary failure',
                exception_class: 'RuntimeException',
                culprit: {
                    file: 'app/Services/Checkout.php',
                    line: 42,
                    function: 'capture',
                },
                request: {
                    method: 'POST',
                    url: 'https://consumer.test/checkout?coupon=SAVE',
                    query: {
                        coupon: 'SAVE',
                    },
                    body: {
                        cart_id: 123,
                        password: '[Filtered]',
                    },
                },
                stacktrace: {
                    frames: [
                        {
                            file: 'app/Services/Checkout.php',
                            line: 42,
                            function: 'capture',
                            in_app: true,
                            context: {
                                start_line: 32,
                                lines: [
                                    { number: 40, text: '$cart = $this->cart();' },
                                    { number: 41, text: '$gateway = $this->gateway();' },
                                    { number: 42, text: '$gateway->capture($cart);', active: true },
                                    { number: 43, text: 'return $cart;' },
                                ],
                            },
                        },
                    ],
                },
            },
            {
                id: 30,
                number: 1,
                received_at: '2026-10-08T09:00:00Z',
                source: 'backend',
                message: 'Previous failure',
                exception_class: 'RuntimeException',
                culprit: {
                    file: 'app/Services/Checkout.php',
                    line: 42,
                },
                request: {
                    method: 'POST',
                    url: 'https://consumer.test/checkout',
                },
                stacktrace: { frames: [] },
            },
        ],
        errorOccurrencesPagination: {
            current_page: 1,
            last_page: 1,
            per_page: 15,
            total: 2,
            from: 1,
            to: 2,
        },
        errorOccurrencesLoading: false,
        errorOccurrencesError: null,
        fetchErrorOccurrences: vi.fn(),
        ...overrides,
    };
}

describe('TaskErrorOccurrencesPane', () => {
    it('uses meaningful source ownership without repeating source-map badges', () => {
        const state = makeState();
        Object.assign(state.errorOccurrences[0], {
            source: 'ui',
            metadata: { component: 'shift_sdk' },
            stacktrace: {
                frames: [],
                resolved_frames: [
                    { resolved: true, file: 'sdk/ui/src/Component.vue', in_app: true },
                    { resolved: true, file: 'portal/components/SharedButton.vue', in_app: true },
                    { resolved: true, file: 'dependency/vue/runtime.js', in_app: false },
                    { resolved: true, file: 'app/Service.php', in_app: true },
                ],
            },
        });
        const wrapper = mount(TaskErrorOccurrencesPane, { props: { state } });
        expect(wrapper.findAll('[data-testid="error-frame-code-owner"]').map((item) => item.text())).toEqual([
            'SDK code',
            'SDK code',
            'Application code',
        ]);
        const trace = wrapper.get('[data-testid="error-occurrence-stack"] ol');
        expect(trace.findAll('span').some((item) => item.text() === 'Original')).toBe(false);
        expect(trace.findAll('span').some((item) => item.text() === 'in app')).toBe(false);
    });

    it('shows original source with context and keeps the compiled position', async () => {
        const state = makeState();
        Object.assign(state.errorOccurrences[0], {
            source: 'ui',
            metadata: { component: 'shift_sdk', frontend_build_id: 'a'.repeat(64) },
            stacktrace: {
                frames: [{ file: '/shift-assets/workspace.js', line: 1, column: 450 }],
                resolved_frames: [
                    {
                        resolved: true,
                        file: 'ui/src/components/TaskList.vue',
                        line: 20,
                        column: 5,
                        function: 'save',
                        in_app: true,
                        generated: { file: '/shift-assets/workspace.js', line: 1, column: 450 },
                        context: {
                            start_line: 19,
                            lines: [
                                { number: 19, text: 'const task = props.task;' },
                                { number: 20, text: 'return task.title.trim();', active: true },
                            ],
                        },
                    },
                ],
                resolution: { status: 'resolved', matched_frames: 1, total_frames: 1 },
            },
        });
        const wrapper = mount(TaskErrorOccurrencesPane, { props: { state } });
        expect(wrapper.get('[data-testid="error-occurrence-stack"]').text()).toContain('ui/src/components/TaskList.vue:20:5');
        expect(wrapper.get('[data-testid="error-frame-generated"]').text()).toContain('/shift-assets/workspace.js:1:450');
        await wrapper.get('[data-testid="error-stack-frame-context-0"]').trigger('click');
        expect(wrapper.get('[data-testid="error-stack-frame-context-lines-0"]').text()).toContain('return task.title.trim();');
    });

    it('shows browser columns so minified bundle failures can be distinguished', () => {
        const state = makeState();
        Object.assign(state.errorOccurrences[0].stacktrace.frames[0], { file: '/shift-assets/workspace.js', line: 1, column: 9876 });
        const wrapper = mount(TaskErrorOccurrencesPane, { props: { state } });
        expect(wrapper.get('[data-testid="error-occurrence-stack"]').text()).toContain('/shift-assets/workspace.js:1:9876');
    });

    it('identifies the SDK and installed version separately from the app release', () => {
        const state = makeState();
        state.errorOccurrences[0] = {
            ...state.errorOccurrences[0],
            metadata: { component: 'shift_sdk', sdk_version: '1.3.8', operation: 'save task', correlation_id: 'request-42' },
        } as (typeof state.errorOccurrences)[0];
        const wrapper = mount(TaskErrorOccurrencesPane, { props: { state } });
        expect(wrapper.get('[data-testid="error-occurrence-sdk"]').text()).toBe('SHIFT SDK · 1.3.8');
        expect(wrapper.get('[data-testid="error-occurrence-sdk-context"]').text()).toContain('save task');
        expect(wrapper.get('[data-testid="error-occurrence-sdk-context"]').text()).toContain('request-42');
    });

    it('opens the latest event first and expands the event selected by its heading', async () => {
        const wrapper = mount(TaskErrorOccurrencesPane, {
            props: { state: makeState() },
        });

        expect(wrapper.findAll('[data-testid^="error-occurrence-row-"]').map((row) => row.attributes('data-testid'))).toEqual([
            'error-occurrence-row-31',
            'error-occurrence-row-30',
        ]);
        expect(wrapper.get('#error-event-trigger-31').attributes('aria-expanded')).toBe('true');
        expect(wrapper.get('[data-testid="error-events-sort"]').text()).toBe('Newest first');
        expect(wrapper.get('[data-testid="error-event-latest"]').text()).toBe('Latest');
        expect(wrapper.get('[data-testid="error-occurrence-stack"]').text()).toContain('Primary failure');

        await wrapper.get('#error-event-trigger-30').trigger('click');

        expect(wrapper.get('#error-event-trigger-31').attributes('aria-expanded')).toBe('false');
        expect(wrapper.get('#error-event-trigger-30').attributes('aria-expanded')).toBe('true');
        expect(wrapper.get('[data-testid="error-occurrence-stack"]').text()).toContain('Previous failure');

        await wrapper.get('#error-event-trigger-30').trigger('click');

        expect(wrapper.find('[data-testid="error-occurrence-stack"]').exists()).toBe(false);
    });

    it('keeps fatal error summaries concise while retaining the complete diagnostic message and release', () => {
        const state = makeState();
        const summary = 'Uncaught Error: Call to undefined function Symfony\\Component\\ErrorHandler\\highlight_file()';
        const message = `${summary} Stack trace: #0 /app/vendor/trace.php(36): render() #1 /app/public/index.php(20): handle() thrown`;
        const sha = '7e671aa394607f5a58f2790c92fd508df69be466';
        Object.assign(state.errorOccurrences[0], { message, git_sha: sha });
        const wrapper = mount(TaskErrorOccurrencesPane, { props: { state } });

        expect(wrapper.get('#error-event-trigger-31 .line-clamp-2').text()).toBe(summary);
        expect(wrapper.get('[data-testid="error-occurrence-message"]').text()).toBe(summary);
        expect(wrapper.get('[data-testid="error-occurrence-full-message"] pre').text()).toBe(message);
        expect(wrapper.get('[data-testid="error-occurrence-full-message"]').attributes('open')).toBeUndefined();
        expect(wrapper.get('[data-testid="error-occurrence-release"]').text()).toBe(sha.slice(0, 8));
        expect(wrapper.get('[data-testid="error-occurrence-release"]').attributes('title')).toBe(sha);
    });

    it('preserves API receipt order when event numbers differ and opens the first event on a new page', async () => {
        const state = reactive(makeState());
        state.errorOccurrences[0].number = 1;
        state.errorOccurrences[0].received_at = '2026-10-08T10:00:00Z';
        state.errorOccurrences[1].number = 2;
        state.errorOccurrences[1].received_at = '2026-10-08T09:00:00Z';
        const wrapper = mount(TaskErrorOccurrencesPane, { props: { state } });

        expect(wrapper.findAll('[data-testid^="error-occurrence-row-"]').map((row) => row.attributes('data-testid'))).toEqual([
            'error-occurrence-row-31',
            'error-occurrence-row-30',
        ]);
        expect(wrapper.get('#error-event-trigger-31').attributes('aria-expanded')).toBe('true');

        state.errorOccurrences = [{ ...state.errorOccurrences[0], id: 42, number: 3, message: 'New page failure' }];
        state.errorOccurrencesPagination = { ...state.errorOccurrencesPagination, current_page: 2, last_page: 2 };
        await nextTick();

        expect(wrapper.get('#error-event-trigger-42').attributes('aria-expanded')).toBe('true');
        expect(wrapper.get('[data-testid="error-occurrence-stack"]').text()).toContain('New page failure');
        expect(wrapper.find('[data-testid="error-event-latest"]').exists()).toBe(false);
    });

    it('uses source language, removes redundant badges and avoids nesting the active occurrence in another card', () => {
        const wrapper = mount(TaskErrorOccurrencesPane, {
            props: { state: makeState() },
        });

        const text = wrapper.text();
        const panel = wrapper.get('[data-testid="error-occurrences-panel"]');
        const detail = wrapper.get('[data-testid="error-occurrence-stack"]');

        expect(text).toContain('Source');
        expect(text).not.toContain('Culprit');
        expect(text).not.toContain(' local ');
        expect(panel.classes()).toContain('shift-scrollbar');
        expect(detail.classes()).not.toContain('border');
    });

    it('renders request params and body when they are captured', () => {
        const wrapper = mount(TaskErrorOccurrencesPane, {
            props: { state: makeState() },
        });

        const requestDetails = wrapper.get('[data-testid="error-occurrence-request-details"]').text();
        const disclosures = wrapper.findAll('[data-testid="error-occurrence-request-details"] details');

        expect(disclosures).toHaveLength(2);
        expect(disclosures.every((disclosure) => disclosure.attributes('open') === undefined)).toBe(true);
        expect(requestDetails).toContain('Query');
        expect(requestDetails).toContain('"coupon": "SAVE"');
        expect(requestDetails).toContain('Body');
        expect(requestDetails).toContain('"cart_id": 123');
        expect(requestDetails).toContain('"password": "[Filtered]"');
    });

    it('expands stack frames to show captured source context', async () => {
        const wrapper = mount(TaskErrorOccurrencesPane, {
            props: { state: makeState() },
        });

        expect(wrapper.text()).not.toContain('$gateway->capture($cart);');

        await wrapper.get('[data-testid="error-stack-frame-context-0"]').trigger('click');

        const context = wrapper.get('[data-testid="error-stack-frame-context-lines-0"]').text();

        expect(context).toContain('40');
        expect(context).toContain('$cart = $this->cart();');
        expect(context).toContain('42');
        expect(context).toContain('$gateway->capture($cart);');
    });
});

it('shows queued recurrence history without claiming delivery', () => {
    const state = makeState();
    Object.assign(state.errorOccurrences[0], { notification: { reason: 'recurring', status: 'queued' } });
    const wrapper = mount(TaskErrorOccurrencesPane, { props: { state } });
    expect(wrapper.get('[data-testid="error-event-notification-31"]').text()).toBe('Recurrence alert queued');
    expect(wrapper.text()).not.toContain('alert sent');
});

it('distinguishes a missing notification audience from dispatch failure', async () => {
    const state = makeState();
    Object.assign(state.errorOccurrences[0], { notification: { reason: 'created', status: 'no_recipients' } });
    const wrapper = mount(TaskErrorOccurrencesPane, { props: { state } });
    expect(wrapper.get('[data-testid="error-event-notification-31"]').text()).toBe('New error alert · no recipients');
    Object.assign(state.errorOccurrences[1], { notification: { reason: 'reopened', status: 'failed' } });
    await wrapper.setProps({ state: { ...state } });
    expect(wrapper.get('[data-testid="error-event-notification-30"]').text()).toBe('Reopened alert could not be queued');
});
