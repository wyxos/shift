import TaskErrorOccurrencesPane from '@/components/tasks/index/TaskErrorOccurrencesPane.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { h } from 'vue';

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
    it('uses the row as the occurrence activation target', async () => {
        const wrapper = mount(TaskErrorOccurrencesPane, {
            props: { state: makeState() },
        });

        expect(wrapper.get('[data-testid="error-occurrence-stack"]').text()).toContain('Primary failure');

        await wrapper.get('[data-testid="error-occurrence-row-30"]').trigger('click');

        expect(wrapper.get('[data-testid="error-occurrence-stack"]').text()).toContain('Previous failure');
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
