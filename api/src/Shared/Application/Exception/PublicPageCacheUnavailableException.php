<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

/**
 * Story 34.10: the frontend did not take a revalidation. Thrown from the job handler so Messenger retries it.
 */
final class PublicPageCacheUnavailableException extends \RuntimeException
{
    /**
     * @param list<string> $tags
     */
    public function __construct(array $tags)
    {
        parent::__construct(sprintf('The frontend did not revalidate %s.', implode(', ', $tags)));
    }
}
