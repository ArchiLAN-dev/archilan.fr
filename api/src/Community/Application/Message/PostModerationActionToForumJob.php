<?php

declare(strict_types=1);

namespace App\Community\Application\Message;

/**
 * Mirror one recorded sanction in the member's case and its staff forum post (story 39.1). Dispatched after
 * the sanction is committed; asynchronous, so Discord never holds a sanction back.
 */
final readonly class PostModerationActionToForumJob
{
    public function __construct(public string $actionId)
    {
    }
}
