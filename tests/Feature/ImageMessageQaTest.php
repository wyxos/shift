<?php

use Inertia\Testing\AssertableInertia as Assert;

it('renders each image-message QA case without authentication', function (string $scenario) {
    $this->get('/qa/image-messages/'.$scenario)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('qa/ImageMessages')
            ->where('scenario', $scenario));
})->with(array_map(fn (int $number): string => sprintf('T%02d', $number), range(1, 15)));

it('defaults the image-message QA gallery to its first case', function () {
    $this->get('/qa/image-messages')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('scenario', 'T01'));
});

it('serves bounded synthetic image dimensions for QA', function (string $shape, int $width, int $height) {
    $response = $this->get('/qa/image-message-fixtures/'.$shape)
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $size = getimagesize($response->baseResponse->getFile()->getPathname());
    expect($size[0])->toBe($width)->and($size[1])->toBe($height);
})->with([
    ['tiny', 64, 64], ['small', 160, 160], ['square', 800, 800], ['large', 2400, 2400],
    ['wide', 1600, 400], ['tall', 400, 1600], ['banner', 2400, 120],
]);

it('rejects unknown QA scenarios and fixture shapes', function () {
    $this->get('/qa/image-messages/T99')->assertNotFound();
    $this->get('/qa/image-message-fixtures/unknown')->assertNotFound();
});

it('hides the gallery and image fixtures outside local and testing', function (string $environment) {
    app()->detectEnvironment(fn (): string => $environment);

    $this->get('/qa/image-messages/T01')->assertNotFound();
    $this->get('/qa/image-message-fixtures/square')->assertNotFound();
})->with(['production', 'staging']);
