<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Enum;

/**
 * Why a customised slot YAML needs a review after an apworld switch (story 38.7).
 */
enum SlotYamlProblem: string
{
    case RemovedOption = 'removed_option';
    case UnknownValue = 'unknown_value';
    case OutOfRange = 'out_of_range';
    case UnknownSubOption = 'unknown_sub_option';
    case Unreadable = 'unreadable';
}
