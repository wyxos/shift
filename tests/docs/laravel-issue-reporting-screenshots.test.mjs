import assert from 'node:assert/strict';
import { test } from 'node:test';

import { buildUrl, readPngSize, screenshotTargets } from '../../scripts/capture-laravel-issue-reporting-screenshots.mjs';

test('defines the Laravel issue reporting screenshot set', () => {
    assert.deepEqual(
        screenshotTargets.map((target) => target.slug),
        ['report-form', 'created-task', 'error-report', 'task-thread'],
    );

    assert.deepEqual(
        screenshotTargets.map((target) => target.file),
        ['01-report-form.png', '02-created-task.png', '03-error-report.png', '04-task-thread.png'],
    );
});

test('builds demo URLs without duplicate slashes', () => {
    assert.equal(
        buildUrl('https://shift.test/docs/laravel-issue-reporting-demo/', 'report-form'),
        'https://shift.test/docs/laravel-issue-reporting-demo/report-form',
    );
});

test('reads PNG dimensions from the IHDR chunk', () => {
    const png = Buffer.alloc(24);

    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]).copy(png, 0);
    png.writeUInt32BE(1920, 16);
    png.writeUInt32BE(1080, 20);

    assert.deepEqual(readPngSize(png), { width: 1920, height: 1080 });
});
