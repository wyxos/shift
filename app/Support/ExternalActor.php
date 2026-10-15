<?php

namespace App\Support;

use App\Models\ExternalIdentity;
use App\Models\ProjectEnvironment;
use App\Models\ProjectParticipant;

final readonly class ExternalActor
{
    public function __construct(
        public ExternalIdentity $identity,
        public ?ProjectParticipant $participant,
        public ?ProjectEnvironment $environment,
    ) {}
}
