<?php

use App\Models\User;

it('pastes image, shows preview, and toggles modal', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visit('/components');

    $page->click('.milkdown-editor .ProseMirror');

    // Paste image via ClipboardEvent
    $page->script(<<<'JS'
        (() => {
          const el = document.querySelector('.milkdown-editor .ProseMirror');
          if (!el) return false;
          const dt = new DataTransfer();
          const blob = new Blob(['fake-image'], { type: 'image/png' });
          const file = new File([blob], 'paste.png', { type: 'image/png' });
          dt.items.add(file);
          const ev = new ClipboardEvent('paste', { bubbles: true, cancelable: true });
          Object.defineProperty(ev, 'clipboardData', { value: dt });
          el.dispatchEvent(ev);
          return true;
        })();
    JS);

    $page->assertNoSmoke();

    $page->assertSee('Uploading image...')
         ->assertVisible('.milkdown-editor .ProseMirror img')
         ->click('.milkdown-editor .ProseMirror img')
         ->assertVisible('img[alt="full-size"]')
         ->click('div.fixed.inset-0')
         ->assertNotPresent('img[alt="full-size"]');
});

it('handles drag-and-drop image upload', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visit('/components');

    $page->click('.milkdown-editor .ProseMirror');

    // Drop image via DragEvent
    $page->script(<<<'JS'
        (() => {
          const el = document.querySelector('.milkdown-editor .ProseMirror');
          if (!el) return false;
          const dt = new DataTransfer();
          const blob = new Blob(['fake-image-2'], { type: 'image/png' });
          const file = new File([blob], 'drop.png', { type: 'image/png' });
          dt.items.add(file);
          const ev = new DragEvent('drop', { bubbles: true, cancelable: true });
          Object.defineProperty(ev, 'dataTransfer', { value: dt });
          el.dispatchEvent(ev);
          return true;
        })();
    JS);

    $page->assertNoSmoke();

    $page->assertSee('Uploading image...')
         ->assertVisible('.milkdown-editor .ProseMirror img');
})->skip();

