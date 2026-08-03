<?php

namespace App\Enums;

enum RegistrationPolicy: string
{
    case Open = 'open';
    case InviteOnly = 'invite_only';
    case Closed = 'closed';
}
