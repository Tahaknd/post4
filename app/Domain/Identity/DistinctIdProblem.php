<?php

namespace App\Domain\Identity;

/** Why a distinct id may not take part in an identity event (see DistinctIdRules). */
enum DistinctIdProblem: string
{
    case Blank = 'blank';
    case TooLong = 'too_long';
    case ControlCharacters = 'control_characters';
    case Reserved = 'reserved';
}
