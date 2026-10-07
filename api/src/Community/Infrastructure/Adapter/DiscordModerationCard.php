<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Adapter;

use App\Community\Application\Support\ModerationForumMessage;

/**
 * The card every moderation message of the bot takes (stories 39.1 and 39.3). Mentions are rendered but never
 * notify anyone (`allowed_mentions` empty).
 */
final class DiscordModerationCard
{
    /**
     * @return array<string, mixed>
     */
    public static function body(ModerationForumMessage $message): array
    {
        return [
            'embeds' => [[
                'title' => mb_substr($message->title, 0, 256),
                'description' => mb_substr($message->description, 0, 4096),
                'color' => $message->color,
                'fields' => array_map(static fn (array $field): array => [
                    'name' => mb_substr($field['name'], 0, 256),
                    'value' => mb_substr('' !== $field['value'] ? $field['value'] : '-', 0, 1024),
                    'inline' => false,
                ], $message->fields),
            ]],
            'allowed_mentions' => ['parse' => []],
        ];
    }
}
