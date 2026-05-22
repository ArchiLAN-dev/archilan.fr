<?php

declare(strict_types=1);

namespace App\Membership\Application;

final readonly class AdminUnmatchedHelloAssoOrdersQuery
{
    public function __construct(private AdminUnmatchedHelloAssoOrdersQueryInterface $query)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function execute(): array
    {
        return $this->query->execute();
    }
}
