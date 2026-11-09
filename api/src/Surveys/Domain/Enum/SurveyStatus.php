<?php

declare(strict_types=1);

namespace App\Surveys\Domain\Enum;

/** A draft is free to change and invisible to players; a published survey keeps its audience and its anonymity. */
enum SurveyStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
