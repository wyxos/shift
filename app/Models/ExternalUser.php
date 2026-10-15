<?php

namespace App\Models;

// Restore model identifiers and enum values serialized before the participant rename.
class_alias(ExternalIdentity::class, __NAMESPACE__.'\\ExternalUser');
