import Index from '@/pages/Tasks/Index.vue';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import { axiosGetMock, makeTasksPage } from './test-helpers';

describe('Tasks/Index.vue', () => {
    it('renders markdown list comments as list HTML', async () => {
        axiosGetMock.mockReset();

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Auth issue',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-02-10T17:40:00',
                    description: '',
                    is_owner: false,
                    submitter: { email: 'someone@example.com' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({
                data: {
                    external: [
                        {
                            id: 11,
                            sender_name: 'You',
                            is_current_user: true,
                            content: '- first\n- second',
                            created_at: '2026-02-09T12:01:00Z',
                            attachments: [],
                        },
                    ],
                },
            });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Open details"]').trigger('click');
        await flushPromises();

        const commentHtml = wrapper.get('[data-testid="comment-bubble-11"]').html();
        expect(commentHtml).toContain('<ul>');
        expect(commentHtml).toMatch(/<li>first<\/li>/i);
        expect(commentHtml).toMatch(/<li>second<\/li>/i);

        wrapper.unmount();
    });

    it('keeps comments separate from occurrence details for error intake tasks', async () => {
        axiosGetMock.mockReset();

        axiosGetMock.mockImplementation((url: string, config?: { params?: Record<string, unknown> }) => {
            if (url === '/tasks.show') {
                return Promise.resolve({
                    data: {
                        id: 1,
                        title: 'UI error: Widget crashed',
                        priority: 'high',
                        status: 'pending',
                        created_at: '2026-02-10T17:40:00',
                        description: '',
                        is_owner: false,
                        submitter: { email: 'someone@example.com' },
                        attachments: [],
                        error_signature: 'error-signature',
                        error_source: 'ui',
                        error_occurrences_count: 2,
                    },
                });
            }

            if (url === '/task-threads.index') {
                return Promise.resolve({
                    data: {
                        internal: [],
                        external: [
                            {
                                id: 22,
                                sender_name: 'Client',
                                is_current_user: false,
                                content: 'Customer-facing comment',
                                created_at: '2026-02-09T12:02:00Z',
                                attachments: [],
                            },
                        ],
                    },
                });
            }

            if (url === '/task-error-occurrences.index') {
                if (config?.params?.page === 2) {
                    return Promise.resolve({
                        data: {
                            occurrences: [
                                {
                                    id: 32,
                                    number: 1,
                                    source: 'ui',
                                    environment: 'local',
                                    message: 'Older widget render failed',
                                    error_name: 'TypeError',
                                    received_at: '2026-02-09T11:59:00Z',
                                    stacktrace: { frames: [] },
                                },
                            ],
                            pagination: {
                                current_page: 2,
                                last_page: 2,
                                per_page: 15,
                                total: 16,
                                from: 16,
                                to: 16,
                            },
                        },
                    });
                }

                return Promise.resolve({
                    data: {
                        occurrences: [
                            {
                                id: 31,
                                number: 2,
                                source: 'ui',
                                environment: 'local',
                                message: 'Widget render failed token=[Filtered]',
                                error_name: 'TypeError',
                                culprit: {
                                    file: 'https://consumer.test/widget.js',
                                    line: 88,
                                    function: 'renderWidget',
                                },
                                request: {
                                    url: 'https://consumer.test/demo',
                                    referrer: 'https://consumer.test/dashboard',
                                },
                                occurred_at: '2026-02-09T12:00:30Z',
                                received_at: '2026-02-09T12:01:00Z',
                                stacktrace: {
                                    frames: [
                                        {
                                            file: 'https://consumer.test/widget.js',
                                            line: 88,
                                            function: 'renderWidget',
                                            in_app: true,
                                        },
                                        {
                                            file: 'https://consumer.test/vendor.js',
                                            line: 20,
                                            function: 'mount',
                                            in_app: false,
                                        },
                                    ],
                                },
                            },
                        ],
                        pagination: {
                            current_page: 1,
                            last_page: 2,
                            per_page: 15,
                            total: 16,
                            from: 1,
                            to: 15,
                        },
                    },
                });
            }

            return Promise.reject(new Error(`Unexpected GET ${url}`));
        });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'UI error: Widget crashed', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Open details"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="error-comments-tab"]').attributes('aria-selected')).toBe('true');
        expect(wrapper.get('[data-testid="comment-bubble-22"]').text()).toContain('Customer-facing comment');
        expect(wrapper.find('[data-testid="comment-bubble-31"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Widget render failed token=[Filtered]');

        await wrapper.get('[data-testid="error-occurrences-tab"]').trigger('click');
        await flushPromises();

        const occurrencesText = wrapper.get('[data-testid="error-occurrences-panel"]').text();
        expect(wrapper.get('[data-testid="error-occurrences-tab"]').attributes('aria-selected')).toBe('true');
        expect(occurrencesText).toContain('Occurrence #2');
        expect(occurrencesText).toContain('Widget render failed token=[Filtered]');
        expect(occurrencesText).toContain('https://consumer.test/widget.js:88');
        expect(occurrencesText).toContain('renderWidget');
        expect(occurrencesText).toContain('Stack trace');
        expect(occurrencesText).toContain('https://consumer.test/demo');
        expect(wrapper.find('[data-testid="comments-editor"]').exists()).toBe(false);

        expect(wrapper.get('[data-testid="error-occurrences-range"]').text()).toContain('Showing 1-15 of 16');
        await wrapper.get('[data-testid="error-occurrences-next"]').trigger('click');
        await flushPromises();

        expect(axiosGetMock).toHaveBeenCalledWith('/task-error-occurrences.index', { params: { page: 2 } });
        expect(wrapper.get('[data-testid="error-occurrences-range"]').text()).toContain('Showing 16-16 of 16');
        expect(wrapper.get('[data-testid="error-occurrences-panel"]').text()).toContain('Older widget render failed');

        wrapper.unmount();
    });

    it('normalizes legacy list HTML comments with br-separated markers', async () => {
        axiosGetMock.mockReset();

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Auth issue',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-02-10T17:40:00',
                    description: '',
                    is_owner: false,
                    submitter: { email: 'someone@example.com' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({
                data: {
                    external: [
                        {
                            id: 11,
                            sender_name: 'You',
                            is_current_user: true,
                            content: '<ul><li><p>test<br>- test</p></li></ul>',
                            created_at: '2026-02-09T12:01:00Z',
                            attachments: [],
                        },
                    ],
                },
            });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Open details"]').trigger('click');
        await flushPromises();

        const commentHtml = wrapper.get('[data-testid="comment-bubble-11"]').html();
        const liMatches = commentHtml.match(/<li>/g) ?? [];
        expect(commentHtml).toContain('<ul>');
        expect(liMatches.length).toBe(2);

        wrapper.unmount();
    });

    it('renders inline code in comments for backtick-wrapped text', async () => {
        axiosGetMock.mockReset();

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Auth issue',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-02-10T17:40:00',
                    description: '',
                    is_owner: false,
                    submitter: { email: 'someone@example.com' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({
                data: {
                    external: [
                        {
                            id: 11,
                            sender_name: 'You',
                            is_current_user: true,
                            content: 'Use `this quote` text',
                            created_at: '2026-02-09T12:01:00Z',
                            attachments: [],
                        },
                    ],
                },
            });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Open details"]').trigger('click');
        await flushPromises();

        const commentHtml = wrapper.get('[data-testid="comment-bubble-11"]').html();
        expect(commentHtml).toContain('<code>');
        expect(commentHtml).toContain('this quote');

        wrapper.unmount();
    });

    it('syntax-highlights fenced code comments after rendering markdown', async () => {
        axiosGetMock.mockReset();

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Auth issue',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-02-10T17:40:00',
                    description: '',
                    is_owner: false,
                    submitter: { email: 'someone@example.com' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({
                data: {
                    external: [
                        {
                            id: 11,
                            sender_name: 'You',
                            is_current_user: true,
                            content: "```js\nfunction demo(){\n  console.log('test')\n}\n```",
                            created_at: '2026-02-09T12:01:00Z',
                            attachments: [],
                        },
                    ],
                },
            });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Open details"]').trigger('click');
        await flushPromises();
        await nextTick();

        const code = wrapper.get('[data-testid="comment-bubble-11"] pre code');
        expect(code.classes()).toContain('hljs');
        expect(code.html()).toContain('hljs-keyword');
        expect(code.text()).toContain("console.log('test')");

        wrapper.unmount();
    });
});
