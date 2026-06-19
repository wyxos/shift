<?php

use App\Services\AppErrors\AppErrorScrubber;

it('redacts authorization credentials embedded in raw header strings', function () {
    $payload = [
        'context' => [
            'request' => [
                'raw_headers' => implode("\n", [
                    'Accept: application/json',
                    'Authorization: Bearer raw-secret-token',
                    'Proxy-Authorization: Basic raw-basic-token',
                    'Content-Type: application/json',
                ]),
                'note' => 'Request failed with authorization=Bearer inline-secret-token while syncing.',
            ],
        ],
    ];

    $scrubbed = (new AppErrorScrubber)->scrubArray($payload);

    expect($scrubbed['context']['request']['raw_headers'])
        ->toContain('Authorization: [Filtered]')
        ->toContain('Proxy-Authorization: [Filtered]')
        ->not->toContain('raw-secret-token')
        ->not->toContain('raw-basic-token')
        ->and($scrubbed['context']['request']['note'])
        ->toContain('authorization=[Filtered]')
        ->toContain('while syncing.')
        ->not->toContain('inline-secret-token');
});
