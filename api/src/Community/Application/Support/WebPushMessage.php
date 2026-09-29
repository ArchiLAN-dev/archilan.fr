<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

/**
 * What the service worker shows (story 40.2): a system notification and the page a click opens. The tag
 * makes a newer push about the same thing replace the previous one instead of piling up.
 */
final readonly class WebPushMessage
{
    public function __construct(
        public string $title,
        public string $body,
        public string $url,
        public string $tag,
    ) {
    }

    public function toJson(): string
    {
        return json_encode(
            ['title' => $this->title, 'body' => $this->body, 'url' => $this->url, 'tag' => $this->tag],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
        );
    }
}
