/* eslint-disable @typescript-eslint/no-unused-vars */
import Index from '@/pages/Organisations/Index.vue';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { fetchMock, getCreateForm, getEditForm, getInviteForm, makeProps, routerDeleteMock, routerGetMock } from './test-helpers';

describe('Organisations/Index.vue', () => {
    it('renders organisation rows with access counts and unknown date fallback', () => {
        const wrapper = mount(Index, {
            props: makeProps(),
        });

        expect(wrapper.find('[data-testid="organisation-row-1"]').text()).toContain('Acme Labs');
        expect(wrapper.find('[data-testid="organisation-row-2"]').text()).toContain('Beta Systems');
        expect(wrapper.text()).toContain('1 user');
        expect(wrapper.text()).toContain('3 projects');
        expect(wrapper.text()).toContain('Unknown');
    });

    it('shows an empty state when no organisations are returned', () => {
        const wrapper = mount(Index, {
            props: makeProps({
                organisations: {
                    data: [],
                    current_page: 1,
                    last_page: 1,
                    total: 0,
                    from: null,
                    to: null,
                },
            }),
        });

        expect(wrapper.text()).toContain('No organisations found.');
    });

    it('applies search and sort filters through the sheet', async () => {
        const wrapper = mount(Index, {
            props: makeProps(),
        });

        await wrapper.get('[data-testid="filter-search"]').setValue('acme');
        await wrapper.get('[data-testid="sort-by-name"]').trigger('click');
        await wrapper.get('[data-testid="filters-apply"]').trigger('click');

        expect(routerGetMock).toHaveBeenCalledWith(
            '/organisations',
            {
                search: 'acme',
                sort_by: 'name',
                page: 1,
            },
            expect.objectContaining({
                preserveState: true,
                preserveScroll: true,
                replace: true,
            }),
        );
    });

    it('resets filters back to defaults', async () => {
        const wrapper = mount(Index, {
            props: makeProps({
                filters: {
                    search: 'beta',
                    sort_by: 'oldest',
                },
            }),
        });

        await wrapper.get('[data-testid="filters-reset"]').trigger('click');

        expect(routerGetMock).toHaveBeenCalledWith(
            '/organisations',
            {
                search: undefined,
                sort_by: undefined,
                page: 1,
            },
            expect.objectContaining({
                preserveState: true,
                preserveScroll: true,
                replace: true,
            }),
        );
    });

    it('navigates pages while preserving active filters', async () => {
        const wrapper = mount(Index, {
            props: makeProps({
                filters: {
                    search: 'labs',
                    sort_by: 'oldest',
                },
            }),
        });

        await wrapper.get('[data-testid="page-2"]').trigger('click');

        expect(routerGetMock).toHaveBeenCalledWith(
            '/organisations',
            {
                search: 'labs',
                sort_by: 'oldest',
                page: 2,
            },
            expect.objectContaining({
                preserveState: true,
                preserveScroll: true,
                replace: true,
            }),
        );
    });

});
