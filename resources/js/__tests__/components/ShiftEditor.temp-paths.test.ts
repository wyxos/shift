import ShiftEditor from '@/components/ShiftEditor.vue';
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { h, nextTick } from 'vue';

// Mock AppLayout and Inertia
vi.mock('@/layouts/AppLayout.vue', () => ({
    default: {
        props: ['breadcrumbs'],
        render() {
            return h('div', { class: 'app-layout' }, this.$slots.default?.());
        },
    },
}));
vi.mock('@inertiajs/vue3', () => ({ Head: { render: () => {} } }));

beforeEach(() => {
    (global as any).route = (name: string, params?: any) => {
        if (name === 'attachments.upload-init') return '/attachments/upload-init';
        if (name === 'attachments.upload-status') return '/attachments/upload-status';
        if (name === 'attachments.upload-chunk') return '/attachments/upload-chunk';
        if (name === 'attachments.upload-complete') return '/attachments/upload-complete';
        if (name === 'attachments.remove-temp') return '/attachments/remove-temp';
        if (name === 'attachments.temp') {
            const temp = params?.temp ?? '';
            const filename = params?.filename ?? '';
            return `/attachments/temp/${temp}/${filename}`;
        }
        return '/';
    };
});

// axios mock
const postMock = vi.fn();
const getMock = vi.fn();
const deleteMock = vi.fn();
vi.mock('axios', () => ({
    default: {
        post: (...args: any[]) => postMock(...args),
        get: (...args: any[]) => getMock(...args),
        delete: (...args: any[]) => deleteMock(...args),
    },
}));

describe('ShiftEditor temp path rendering', () => {
    const originalCreateObjectURL = URL.createObjectURL;

    beforeEach(() => {
        URL.createObjectURL = vi.fn(() => 'blob:mock-1234') as any;
        postMock.mockReset();
        getMock.mockReset();
        deleteMock.mockReset();
    });
    afterEach(() => {
        URL.createObjectURL = originalCreateObjectURL as any;
    });

    async function waitForEditor(wrapper: any) {
        const start = Date.now();
        while (Date.now() - start < 800) {
            const el = wrapper.find('.ProseMirror');
            if (el.exists()) return el;
            await new Promise((r) => setTimeout(r, 10));
            await nextTick();
        }
        return wrapper.find('.ProseMirror');
    }

    it('uses temp path as final img src after upload resolves', async () => {
        postMock.mockImplementation((url: string) => {
            if (url === '/attachments/upload-init') {
                return Promise.resolve({ data: { upload_id: 'u1', chunk_size: 5, total_chunks: 1, max_bytes: 41943040 } });
            }
            if (url === '/attachments/upload-chunk') {
                return Promise.resolve({ data: { ok: true } });
            }
            if (url === '/attachments/upload-complete') {
                return Promise.resolve({ data: { path: 'temp_attachments/TEMP123/foo.png' } });
            }
            return Promise.resolve({ data: {} });
        });
        getMock.mockResolvedValue({ data: { uploaded_chunks: [], total_chunks: 1, chunk_size: 5 } });

        // mock canvas for placeholder tile
        const proto: any = (HTMLCanvasElement as any).prototype;
        const origGetContext = proto.getContext;
        const origToDataURL = proto.toDataURL;
        proto.getContext = vi.fn(() => ({
            fillStyle: '',
            strokeStyle: '',
            font: '',
            textAlign: '',
            lineWidth: 1,
            lineCap: '',
            fillRect: vi.fn(),
            strokeRect: vi.fn(),
            fillText: vi.fn(),
            beginPath: vi.fn(),
            moveTo: vi.fn(),
            lineTo: vi.fn(),
            closePath: vi.fn(),
            stroke: vi.fn(),
            arc: vi.fn(),
            fill: vi.fn(),
        }));
        proto.toDataURL = vi.fn(() => 'data:image/png;base64,AAAA');

        // Mock Image preload to immediately resolve
        const OrigImage = (globalThis as any).Image;
        class MockImage {
            onload: (() => void) | null = null;
            onerror: (() => void) | null = null;
            set src(_v: string) {
                setTimeout(() => this.onload && this.onload());
            }
        }
        (globalThis as any).Image = MockImage as any;

        const wrapper = mount(ShiftEditor);
        await nextTick();
        const editorEl = await waitForEditor(wrapper);

        const file = new File([new Uint8Array([1, 2, 3])], 'foo.png', { type: 'image/png' });
        const ed: any = (wrapper.vm as any).editor;
        ed.commands.insertFiles([file]);

        await nextTick();

        // Wait for axios to resolve and node to update src
        const start = Date.now();
        let srcOk = false;
        const expected = '/attachments/temp/TEMP123/foo.png';
        while (Date.now() - start < 800) {
            const img = editorEl.find('img');
            if (img.exists()) {
                const src = img.attributes('src') || '';
                if (src.includes(expected)) {
                    srcOk = true;
                    break;
                }
            }
            await new Promise((r) => setTimeout(r, 10));
            await nextTick();
        }
        expect(srcOk).toBe(true);

        proto.getContext = origGetContext;
        proto.toDataURL = origToDataURL;
        (globalThis as any).Image = OrigImage;
    });

    it('renders non-image attachment item with temp path after upload', async () => {
        postMock.mockImplementation((url: string) => {
            if (url === '/attachments/upload-init') {
                return Promise.resolve({ data: { upload_id: 'u2', chunk_size: 5, total_chunks: 1, max_bytes: 41943040 } });
            }
            if (url === '/attachments/upload-chunk') {
                return Promise.resolve({ data: { ok: true } });
            }
            if (url === '/attachments/upload-complete') {
                return Promise.resolve({ data: { path: 'temp_attachments/XYZ/manual.pdf' } });
            }
            return Promise.resolve({ data: {} });
        });
        getMock.mockResolvedValue({ data: { uploaded_chunks: [], total_chunks: 1, chunk_size: 5 } });
        deleteMock.mockResolvedValue({});

        const wrapper = mount(ShiftEditor);
        await nextTick();
        const editorEl = await waitForEditor(wrapper);
        expect(editorEl.exists()).toBe(true);

        const file = new File([new Uint8Array([1, 2, 3, 4])], 'manual.pdf', { type: 'application/pdf' });
        const ed: any = (wrapper.vm as any).editor;
        ed.commands.insertFiles([file]);

        await nextTick();

        const start = Date.now();
        let itemOk = false;
        while (Date.now() - start < 800) {
            const item = wrapper.find('[data-testid=\"attachment-item\"]');
            if (item.exists()) {
                const el = item.element as HTMLElement;
                const tempPath = el.getAttribute('data-temp-path') || '';
                if (tempPath === 'temp_attachments/XYZ/manual.pdf') {
                    itemOk = true;
                    break;
                }
            }
            await new Promise((r) => setTimeout(r, 10));
            await nextTick();
        }
        expect(itemOk).toBe(true);
    });
});
