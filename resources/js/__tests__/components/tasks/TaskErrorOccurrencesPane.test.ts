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
