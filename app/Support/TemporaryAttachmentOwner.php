<?php

namespace App\Support;

final readonly class TemporaryAttachmentOwner
{
    public string $fingerprint;

    /** @param array<string, int|string|null> $context */
    public function __construct(array $context)
    {
        $this->fingerprint = hash('sha256', json_encode($context, JSON_THROW_ON_ERROR));
    }

    public static function portal(int $userId): self
    {
        return new self(['kind' => 'portal', 'user_id' => $userId]);
    }
}
