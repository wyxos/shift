export type QaAttachment = { id: number | string; url: string; original_filename: string; mime_type: string };
export type ImageMessageCase = { id: string; title: string; dimensions: string; expected: string; content: string; attachments: QaAttachment[] };

export function fixtureUrl(shape: string): string {
    return `/qa/image-message-fixtures/${shape}`;
}

const image = (shape = 'square') => `<img class="editor-tile" src="${fixtureUrl(shape)}" alt="${shape} test image">`;
const squares = (count: number) => Array.from({ length: count }, () => image()).join('');
const attachment = (shape: string): QaAttachment => ({
    id: shape,
    url: fixtureUrl(shape),
    original_filename: `${shape}-test.png`,
    mime_type: 'image/png',
});
const defineCase = (
    id: string,
    title: string,
    dimensions: string,
    expected: string,
    content: string,
    attachments: QaAttachment[] = [],
): ImageMessageCase => ({
    id,
    title,
    dimensions,
    expected,
    content,
    attachments,
});

export const imageMessageCases: ImageMessageCase[] = [
    defineCase('T01', 'Pasted image only', '800 × 800', 'One square thumbnail with no caption or attachment card.', `<p>${image()}</p>`),
    defineCase(
        'T02',
        'Text around a paste',
        '800 × 800',
        'Text stays above and below the image, even when originally typed without a line break.',
        `<p>Before image ${image()} after image</p>`,
    ),
    defineCase(
        'T03',
        'Tiny image beside text',
        '64 × 64',
        'The tiny image stays small inside a padded square tile. Text appears above and below.',
        `<p>BEFORE ${image('tiny')} AFTER</p>`,
    ),
    defineCase(
        'T04',
        'Explicit multiline caption',
        '160 × 160',
        'Line breaks and captions stay in order around the small image.',
        `<p>Small screenshot<br>Above image<br>${image('small')}<br>Below image</p>`,
    ),
    defineCase(
        'T05',
        'Large square',
        '2400 × 2400',
        'The source scales down to the same compact square tile as the 800 px image.',
        `<p>${image('large')}</p>`,
    ),
    defineCase(
        'T06',
        'Wide rectangle',
        '1600 × 400',
        'The complete wide image keeps its proportions in a broad, shallow preview.',
        `<p>${image('wide')}</p>`,
    ),
    defineCase(
        'T07',
        'Tall rectangle',
        '400 × 1600',
        'The whole tall image fits inside a padded square tile. No top or bottom is cropped.',
        `<p>${image('tall')}</p>`,
    ),
    defineCase(
        'T08',
        'Thin banner',
        '2400 × 120',
        'The complete banner becomes a shallow strip. The gold border stays visible.',
        `<p>${image('banner')}</p>`,
    ),
    defineCase(
        'T09',
        'Uploaded image only',
        '2400 × 2400',
        'An uploaded image uses an attachment preview with its filename, separate from pasted body images.',
        '',
        [attachment('large')],
    ),
    defineCase(
        'T10',
        'Upload plus pasted image',
        '1600 × 400 upload + 2400 × 120 paste',
        'The body banner appears first. The uploaded wide image has its own attachment preview below.',
        `<p>Mixed image message</p><p>${image('banner')}</p>`,
        [attachment('wide')],
    ),
    defineCase('T11', 'Consecutive images', 'Two 800 × 800', 'Consecutive pasted images share one row when space allows.', `<p>${squares(2)}</p>`),
    defineCase(
        'T12',
        'Images with a line break',
        'Two 800 × 800',
        'An explicit line break starts a new image row.',
        `<p>${image()}<br>${image()}</p>`,
    ),
    defineCase(
        'T13',
        'Captions between images',
        'Two 800 × 800',
        'Each caption separates the image runs and remains attached to its position in the message.',
        `<p>First image${image()}Middle caption${image()}Final caption</p>`,
    ),
    defineCase(
        'T14',
        'Four consecutive images',
        'Four 800 × 800',
        'Images share rows and wrap naturally when the available message width is smaller.',
        `<p>${squares(4)}</p>`,
    ),
    defineCase(
        'T15',
        'Five consecutive images',
        'Five 800 × 800',
        'Images wrap onto additional rows without cropping or horizontal overflow. The row count depends on the viewport.',
        `<p>${squares(5)}</p>`,
    ),
];
