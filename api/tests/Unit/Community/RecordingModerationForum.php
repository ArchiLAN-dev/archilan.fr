<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Exception\ModerationForumDeliveryException;
use App\Community\Application\Port\ModerationForumInterface;
use App\Community\Application\Support\ModerationForumMessage;

/**
 * Stands in for the Discord staff forum (story 39.1 tests): records the posts it opens and the messages it
 * receives, and can be told to fail.
 */
final class RecordingModerationForum implements ModerationForumInterface
{
    /** @var list<array{title: string, message: ModerationForumMessage}> */
    public array $openedThreads = [];

    /** @var list<array{threadId: string, message: ModerationForumMessage}> */
    public array $posts = [];

    public ?ModerationForumDeliveryException $failWith = null;

    public function __construct(private readonly bool $configured = true)
    {
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function openThread(string $title, ModerationForumMessage $message): string
    {
        if (null !== $this->failWith) {
            throw $this->failWith;
        }
        $this->openedThreads[] = ['title' => $title, 'message' => $message];

        return 'thread-'.\count($this->openedThreads);
    }

    public function post(string $threadId, ModerationForumMessage $message): void
    {
        if (null !== $this->failWith) {
            throw $this->failWith;
        }
        $this->posts[] = ['threadId' => $threadId, 'message' => $message];
    }
}
