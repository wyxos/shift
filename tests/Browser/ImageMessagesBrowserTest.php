<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Vite;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\Webpage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // Browser tests use this checkout's build, independently of a developer Vite server.
    Vite::useHotFile(storage_path('framework/testing/image-messages.hot'));
});

function settleImageMessageQa(AwaitableWebpage|Webpage $page): void
{
    $page->script(<<<'JS'
        async () => {
            await Promise.all([...document.querySelectorAll('img')].map(image => image.decode().catch(() => {})));
            await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
        }
        JS);
}

function saveImageMessageQa(AwaitableWebpage|Webpage $page, string $name, bool $fullPage = false): void
{
    $page->screenshot($fullPage, $name);
    $destination = getenv('SHIFT_BROWSER_REVIEW_DIR');
    if (is_string($destination) && $destination !== '') {
        @mkdir($destination, 0777, true);
        copy(__DIR__.'/Screenshots/'.$name, rtrim($destination, '/').'/'.$name);
    }
}

function pasteImageMessageQa(AwaitableWebpage|Webpage $page, int $width = 800, int $height = 800): void
{
    $page->script(<<<JS
        async () => {
            const canvas = document.createElement('canvas');
            canvas.width = {$width};
            canvas.height = {$height};
            const context = canvas.getContext('2d');
            context.fillStyle = '#19769b';
            context.fillRect(0, 0, canvas.width, canvas.height);
            const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
            const data = new DataTransfer();
            data.items.add(new File([blob], 'browser-square.png', { type: 'image/png' }));
            const editor = document.querySelector('[aria-label="Image QA message editor"]');
            editor.focus();
            editor.dispatchEvent(new ClipboardEvent('paste', { bubbles: true, cancelable: true, clipboardData: data }));
        }
        JS);
}

it('keeps consecutive images together and explicit breaks on separate rows', function (string $scenario, bool $stacked) {
    $page = visit('/qa/image-messages/'.$scenario)->resize(1440, 1000)
        ->assertVisible('[data-testid="qa-message"] img >> nth=0')
        ->assertCount('[data-testid="qa-message"] img', 2)
        ->assertNoSmoke();
    settleImageMessageQa($page);
    $page->assertScript(<<<'JS'
        (() => {
            const images = [...document.querySelectorAll('[data-testid="qa-message"] img')];
            const first = images[0].getBoundingClientRect();
            const second = images[1].getBoundingClientRect();
            return second.top > first.bottom;
        })()
        JS, $stacked);
    $page->assertScript('document.documentElement.scrollWidth <= window.innerWidth');
    saveImageMessageQa($page, 'image-messages-'.$scenario.'-desktop.png');
})->with(['consecutive' => ['T11', false], 'hard break' => ['T12', true]]);

it('fits the complete source without enlarging small images or cropping rectangles', function (string $scenario, float $ratio) {
    $page = visit('/qa/image-messages/'.$scenario)->resize(1440, 1000)
        ->assertVisible('[data-testid="qa-message"] img >> nth=0')
        ->assertNoSmoke();
    settleImageMessageQa($page);
    $page->assertScript(<<<JS
        (() => {
            const image = document.querySelector('[data-testid="qa-message"] img');
            const rect = image.getBoundingClientRect();
            const style = getComputedStyle(image);
            return image.complete && image.naturalWidth > 0
                && style.objectFit === 'scale-down'
                && Math.abs(rect.width / rect.height - {$ratio}) < 0.05
                && style.objectPosition === '50% 50%';
        })()
        JS);
    if ($scenario === 'T03') {
        $page->assertScript(<<<'JS'
            (() => {
                const image = document.querySelector('[data-testid="qa-message"] img');
                return image.naturalWidth === 64 && image.getBoundingClientRect().width > 170;
            })()
            JS);
    }
    saveImageMessageQa($page, 'image-messages-'.$scenario.'-desktop.png');
    $page->resize(390, 844)->assertScript('document.documentElement.scrollWidth <= window.innerWidth')->assertNoSmoke();
    saveImageMessageQa($page, 'image-messages-'.$scenario.'-mobile.png');
})->with(['tiny' => ['T03', 1], 'large square' => ['T05', 1], 'wide' => ['T06', 4], 'portrait' => ['T07', 1], 'banner' => ['T08', 20]]);

it('keeps captions between image rows and wraps long image groups at narrower widths', function () {
    $page = visit('/qa/image-messages/T13')->resize(1440, 1000)
        ->assertVisible('[data-testid="qa-message"] img >> nth=0')
        ->assertSeeIn('[data-testid="qa-message"]', 'Middle caption');
    settleImageMessageQa($page);
    $page->assertScript(<<<'JS'
        (() => {
            const paragraph = document.querySelector('[data-testid="qa-message"] p');
            const images = paragraph.querySelectorAll('img');
            const caption = [...paragraph.childNodes].find(node => node.textContent === 'Middle caption');
            const range = document.createRange();
            range.selectNodeContents(caption);
            const rect = range.getBoundingClientRect();
            return images[0].getBoundingClientRect().bottom <= rect.top
                && images[1].getBoundingClientRect().top >= rect.bottom;
        })()
        JS);
    saveImageMessageQa($page, 'image-messages-captions-desktop.png');

    $page->click('a[href="/qa/image-messages/T15"]')
        ->assertVisible('[data-qa-case="T15"]')
        ->resize(1000, 1000);
    settleImageMessageQa($page);
    $page->assertScript(<<<'JS'
        (() => {
            const images = [...document.querySelectorAll('[data-testid="qa-message"] img')];
            return images.length === 5 && images[4].getBoundingClientRect().top > images[0].getBoundingClientRect().top
                && document.documentElement.scrollWidth <= window.innerWidth;
        })()
        JS);
    saveImageMessageQa($page, 'image-messages-wrap-tablet.png');
    $page->resize(390, 844)->assertScript('document.documentElement.scrollWidth <= window.innerWidth')->assertNoSmoke();
    $page->script(<<<'JS'
        async () => {
            document.querySelector('[data-testid="qa-editor"]').scrollIntoView({ behavior: 'instant', block: 'start' });
            await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
        }
        JS);
    $page->assertScript(<<<'JS'
        (() => {
            const nav = document.querySelector('nav[aria-label="Image message cases"]').getBoundingClientRect();
            const header = document.querySelector('header').getBoundingClientRect();
            return nav.top >= header.bottom - 1 && nav.bottom <= window.innerHeight;
        })()
        JS);
    saveImageMessageQa($page, 'image-messages-wrap-mobile.png');
});

it('shows uploaded image attachments below body content and opens their viewer by keyboard', function () {
    $page = visit('/qa/image-messages/T10')->resize(1440, 1000)
        ->assertVisible('[data-testid="message-attachments"] [data-message-image]')
        ->assertVisible('[data-testid="qa-message"] .shift-rich img')
        ->assertNoSmoke();
    settleImageMessageQa($page);
    $page->assertScript(<<<'JS'
        (() => {
            const body = document.querySelector('[data-testid="qa-message"] .shift-rich');
            const attachment = document.querySelector('[data-testid="message-attachments"]');
            const image = attachment.querySelector('img');
            return attachment.getBoundingClientRect().top >= body.getBoundingClientRect().bottom
                && image.complete && image.naturalWidth > 0
                && !image.classList.contains('editor-tile');
        })()
        JS);
    saveImageMessageQa($page, 'image-messages-upload-and-paste.png');
    $page->keys('[data-testid="message-attachments"] [data-message-image]', 'Enter')
        ->assertVisible('[role="dialog"][aria-label="Image preview"]')
        ->assertVisible('[title="Zoom in"]')
        ->click('[title="Close"]')
        ->assertNotPresent('[role="dialog"][aria-label="Image preview"]')
        ->resize(390, 844)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoSmoke();
    saveImageMessageQa($page, 'image-messages-attachment-mobile.png');
});

it('uses real typing after a paste for captions and Shift Enter for a separate image row', function () {
    $editor = '[aria-label="Image QA message editor"]';
    $page = visit('/qa/image-messages/T01')->resize(1440, 1000)->assertVisible($editor);
    pasteImageMessageQa($page);
    $page->assertVisible($editor.' img.editor-tile');
    // The upload adapter completes via FileReader/load events. The Send action is enabled only afterwards.
    $page->assertScript(<<<'JS'
        async () => {
            const image = document.querySelector('[aria-label="Image QA message editor"] img.editor-tile');
            if (image.title.startsWith('upload-')) {
                await new Promise(resolve => {
                    const observer = new MutationObserver(() => {
                        if (!image.title.startsWith('upload-')) { observer.disconnect(); resolve(); }
                    });
                    observer.observe(image, { attributes: true, attributeFilter: ['title'] });
                });
            }
            return true;
        }
        JS);
    $page->typeSlowly($editor, 'Caption after paste', 10)
        ->click('[data-testid="toolbar-send"]')
        ->assertVisible('[data-testid="qa-sent-message"] img')
        ->assertSeeIn('[data-testid="qa-sent-message"]', 'Caption after paste')
        ->assertScript(<<<'JS'
            (() => {
                const root = document.querySelector('[data-testid="qa-sent-message"]');
                const image = root.querySelector('img');
                const paragraph = root.querySelector('p');
                const caption = [...paragraph.childNodes].find(node => node.textContent.includes('Caption after paste') && node.nodeType === Node.TEXT_NODE);
                const range = document.createRange(); range.selectNodeContents(caption);
                return range.getBoundingClientRect().top >= image.getBoundingClientRect().bottom;
            })()
            JS);
    saveImageMessageQa($page, 'image-messages-typed-caption.png', true);

    pasteImageMessageQa($page);
    $page->assertVisible($editor.' img.editor-tile')->keys($editor, 'Shift+Enter');
    pasteImageMessageQa($page);
    $page->assertCount($editor.' img.editor-tile', 2)->assertScript(<<<'JS'
        (() => {
            const images = document.querySelectorAll('[aria-label="Image QA message editor"] img.editor-tile');
            return images[1].getBoundingClientRect().top > images[0].getBoundingClientRect().top;
        })()
        JS)->assertNoSmoke();
});

it('renders image rows and captions in the actual task comments surface without overflow', function () {
    Notification::fake();
    $user = User::factory()->create(['name' => 'Image Reviewer']);
    $project = Project::factory()->withAuthor($user->id)->create();
    $task = Task::factory()->for($project)->create(['title' => 'Image message browser review', 'description' => '']);
    $task->submitter()->associate($user)->save();
    $image = '<img src="/qa/image-message-fixtures/square" alt="Synthetic square">';
    $thread = new TaskThread([
        'task_id' => $task->id, 'type' => 'external',
        'content' => '<p>'.$image.$image.'Middle caption'.$image.'</p>',
        'sender_name' => $user->name,
    ]);
    $thread->sender()->associate($user)->save();
    $this->actingAs($user);
    $selector = '[data-testid="comment-bubble-'.$thread->id.'"]';
    $page = visit('/tasks?task='.$task->id)->resize(1920, 1080)
        ->assertVisible($selector.' img >> nth=0')
        ->assertCount($selector.' .shift-image-run', 2)
        ->assertSeeIn($selector, 'Middle caption')
        ->assertNoSmoke();
    settleImageMessageQa($page);
    $page->assertScript('document.documentElement.scrollWidth <= window.innerWidth');
    saveImageMessageQa($page, 'image-messages-real-task-desktop.png');
    $page->resize(390, 844)->click('[data-testid="edit-mobile-pane-comments"]')
        ->assertVisible($selector.' img >> nth=0')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoSmoke();
    saveImageMessageQa($page, 'image-messages-real-task-mobile.png');
});

it('finishes image viewer transitions without moving the message and respects reduced motion', function (string $motion) {
    $page = visit('/qa/image-messages/T09', ['reducedMotion' => $motion])->resize(1280, 900)
        ->assertVisible('[data-testid="message-attachments"] [data-message-image]');
    settleImageMessageQa($page);
    $page->script(<<<'JS'
        (() => {
            window.__imageMessageMotion = { starts: [], ends: [], frames: [], rect: document.querySelector('[data-testid="qa-message"]').getBoundingClientRect().toJSON() };
            document.addEventListener('animationstart', event => {
                if (event.target.matches('[data-slot="dialog-content"]')) {
                    window.__imageMessageMotion.starts.push({ name: event.animationName, duration: getComputedStyle(event.target).animationDuration });
                    const animation = event.target.getAnimations().find(item => item.animationName === event.animationName);
                    const recordFrame = () => {
                        if (!event.target.isConnected || !animation || animation.playState !== 'running') return;
                        const style = getComputedStyle(event.target);
                        window.__imageMessageMotion.frames.push({ opacity: Number(style.opacity), transform: style.transform, rect: event.target.getBoundingClientRect().toJSON() });
                        requestAnimationFrame(recordFrame);
                    };
                    requestAnimationFrame(recordFrame);
                }
            }, true);
            document.addEventListener('animationend', event => {
                if (event.target.matches('[data-slot="dialog-content"]')) window.__imageMessageMotion.ends.push(event.animationName);
            }, true);
        })()
        JS);
    $page->keys('[data-testid="message-attachments"] [data-message-image]', 'Enter')
        ->assertVisible('[role="dialog"][aria-label="Image preview"]');
    $page->script(<<<'JS'
        async () => {
            const dialog = document.querySelector('[role="dialog"][aria-label="Image preview"]');
            await Promise.all(dialog.getAnimations().map(animation => animation.finished.catch(() => {})));
        }
        JS);
    $page->assertScript(<<<JS
        (() => {
            const dialog = document.querySelector('[role="dialog"][aria-label="Image preview"]');
            const duration = Math.max(...getComputedStyle(dialog).animationDuration.split(',').map(value => parseFloat(value) * (value.includes('ms') ? 1 : 1000)));
            return matchMedia('(prefers-reduced-motion: reduce)').matches === ('{$motion}' === 'reduce')
                && ('{$motion}' === 'reduce' ? getComputedStyle(dialog).animationName === 'none' || (getComputedStyle(dialog).animationName === 'shift-reduced-in' && duration <= 120) : duration >= 100 && duration <= 300);
        })()
        JS);
    $page->click('[title="Close"]')->assertNotPresent('[role="dialog"][aria-label="Image preview"]')
        ->assertScript(<<<JS
            (() => {
                const motion = window.__imageMessageMotion;
                return '{$motion}' === 'reduce'
                    ? motion.starts.every(event => event.name.startsWith('shift-reduced-'))
                        && motion.ends.length === motion.starts.length
                        && motion.frames.every(frame => frame.transform === 'none')
                        && motion.frames.every(frame => Math.abs(frame.rect.x - motion.frames[0].rect.x) < 1 && Math.abs(frame.rect.y - motion.frames[0].rect.y) < 1 && Math.abs(frame.rect.width - motion.frames[0].rect.width) < 1)
                    : motion.starts.length >= 2 && motion.ends.length >= 2
                        && motion.frames.some(frame => frame.opacity > 0 && frame.opacity < 1);
            })()
            JS)
        ->assertScript(<<<'JS'
            (() => {
                const before = window.__imageMessageMotion.rect;
                const after = document.querySelector('[data-testid="qa-message"]').getBoundingClientRect();
                return Math.abs(before.x - after.x) < 1 && Math.abs(before.y - after.y) < 1
                    && Math.abs(before.width - after.width) < 1 && Math.abs(before.height - after.height) < 1;
            })()
            JS)->assertNoSmoke();
})->with(['normal motion' => 'no-preference', 'reduced motion' => 'reduce']);

it('keeps consecutive real pastes in a single submitted image row', function () {
    $editor = '[aria-label="Image QA message editor"]';
    $page = visit('/qa/image-messages/T11')->resize(1440, 1000)->assertVisible($editor);
    pasteImageMessageQa($page);
    $page->assertCount($editor.' img.editor-tile', 1);
    pasteImageMessageQa($page);
    $page->assertCount($editor.' img.editor-tile', 2)
        ->assertScript("[...document.querySelectorAll('[aria-label=\"Image QA message editor\"] img.editor-tile')].every(image => !image.title.startsWith('upload-'))")
        ->click('[data-testid="toolbar-send"]')
        ->assertCount('[data-testid="qa-sent-message"] .shift-image-run', 1)
        ->assertCount('[data-testid="qa-sent-message"] img', 2)
        ->assertScript(<<<'JS'
            (() => {
                const images = document.querySelectorAll('[data-testid="qa-sent-message"] img');
                return Math.abs(images[0].getBoundingClientRect().top - images[1].getBoundingClientRect().top) < 2;
            })()
            JS)->assertNoSmoke();
});

it('keeps a wide pasted image and its node view inside the phone editor', function () {
    $editor = '[aria-label="Image QA message editor"]';
    $page = visit('/qa/image-messages/T06')->resize(390, 844)->assertVisible($editor);
    pasteImageMessageQa($page, 1600, 400);
    $page->assertVisible($editor.' img.editor-tile')
        ->assertScript("!document.querySelector('[aria-label=\"Image QA message editor\"] img.editor-tile').title.startsWith('upload-')");
    settleImageMessageQa($page);
    $page->assertScript(<<<'JS'
        (() => {
            const editor = document.querySelector('[aria-label="Image QA message editor"]');
            const image = editor.querySelector('img.editor-tile');
            const editorRect = editor.getBoundingClientRect();
            const imageRect = image.getBoundingClientRect();
            return image.naturalWidth === 1600 && imageRect.width <= editorRect.width
                && imageRect.left >= editorRect.left && imageRect.right <= editorRect.right
                && document.documentElement.scrollWidth <= window.innerWidth;
        })()
        JS)->assertNoSmoke();
    saveImageMessageQa($page, 'image-messages-wide-editor-mobile.png', true);
});
