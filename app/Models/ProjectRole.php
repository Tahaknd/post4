<?php

namespace App\Models;

/**
 * What a user is on a project. One role exists so far; the column and the enum are in place so that adding
 * one later (viewer, owner) is a case here and a policy, not a migration of meaning.
 */
enum ProjectRole: string
{
    case Member = 'member';
}
