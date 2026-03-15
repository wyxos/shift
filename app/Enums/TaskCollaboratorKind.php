<?php

namespace App\Enums;

enum TaskCollaboratorKind: string
{
    case Internal = 'internal';
    case External = 'external';
}
