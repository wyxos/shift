import fs from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath, pathToFileURL } from 'node:url';

import { chromium } from 'playwright';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

export const screenshotTargets = [
    {
        slug: 'embedded-issue-form',
        file: '01-embedded-issue-form.png',
    },
    {
        slug: 'created-task-context',
        file: '02-created-task-context.png',
    },
    {
        slug: 'backend-error-intake',
        file: '03-backend-error-intake.png',
    },
    {
        slug: 'task-thread-follow-up',
        file: '04-task-thread-follow-up.png',
    },
];

export function buildUrl(baseUrl, slug) {
    return `${baseUrl.replace(/\/+$/, '')}/${slug}`;
}

export function readPngSize(buffer) {
    if (buffer.length < 24 || buffer.toString('ascii', 1, 4) !== 'PNG') {
        throw new Error('Screenshot is not a PNG file.');
    }

    return {
        width: buffer.readUInt32BE(16),
        height: buffer.readUInt32BE(20),
    };
}

function parseArgs(argv) {
    const options = {
        baseUrl: process.env.SHIFT_PUBLIC_DISCOVERY_DEMO_URL ?? 'https://shift.test/docs/public-discovery-demo',
        outputDir: path.resolve(__dirname, '../docs/assets/public-discovery'),
        headed: false,
    };

    for (const arg of argv) {
        if (arg === '--headed') {
            options.headed = true;
            continue;
        }

        if (arg.startsWith('--base-url=')) {
            options.baseUrl = arg.slice('--base-url='.length);
            continue;
        }

        if (arg.startsWith('--output-dir=')) {
            options.outputDir = path.resolve(arg.slice('--output-dir='.length));
            continue;
        }

        throw new Error(`Unknown argument: ${arg}`);
    }

    return options;
}

export async function captureScreenshots(options) {
    await fs.mkdir(options.outputDir, { recursive: true });

    const browser = await chromium.launch({
        headless: !options.headed,
    });

    try {
        const context = await browser.newContext({
            viewport: { width: 1920, height: 1080 },
            deviceScaleFactor: 1,
            ignoreHTTPSErrors: true,
        });
        const page = await context.newPage();
        const results = [];

        for (const target of screenshotTargets) {
            const url = buildUrl(options.baseUrl, target.slug);
            const outputPath = path.join(options.outputDir, target.file);

            try {
                await page.goto(url, { waitUntil: 'domcontentloaded' });
            } catch (error) {
                throw new Error(
                    `Could not open ${url}. Ensure the local SHIFT app is available through Herd, or pass --base-url=. Original error: ${error.message}`,
                );
            }

            await page.waitForSelector(`[data-screenshot-ready="${target.slug}"]`, {
                timeout: 10_000,
            });

            const buffer = await page.screenshot({
                path: outputPath,
                fullPage: false,
            });
            const size = readPngSize(buffer);

            if (size.width !== 1920 || size.height !== 1080) {
                throw new Error(`${outputPath} was ${size.width}x${size.height}; expected 1920x1080.`);
            }

            results.push({
                ...target,
                path: outputPath,
                ...size,
            });
        }

        return results;
    } finally {
        await browser.close();
    }
}

async function main() {
    const options = parseArgs(process.argv.slice(2));
    const results = await captureScreenshots(options);

    for (const result of results) {
        console.log(`${result.slug}: ${result.path} (${result.width}x${result.height})`);
    }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    main().catch((error) => {
        console.error(error.message);
        process.exitCode = 1;
    });
}
