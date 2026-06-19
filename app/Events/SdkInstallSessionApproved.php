<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class SdkInstallSessionApproved implements ShouldBroadcastNow
{
    public function __construct(
        public readonly string $deviceCode,
        public readonly array $session,
    ) {}

    public static function channelName(string $deviceCode): string
    {
        return 'sdk-install.'.hash('sha256', $deviceCode);
    }

    public function broadcastOn(): array
    {
        return [new Channel(self::channelName($this->deviceCode))];
    }

    public function broadcastAs(): string
    {
        return 'sdk-install.approved';
    }

    public function broadcastWith(): array
    {
        return [
            'state' => 'approved',
            'approved_at' => $this->session['approved_at'] ?? null,
            'expires_at' => $this->session['expires_at'] ?? null,
        ];
    }
}
