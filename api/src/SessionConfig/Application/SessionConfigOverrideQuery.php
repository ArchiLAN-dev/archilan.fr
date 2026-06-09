<?php

declare(strict_types=1);

namespace App\SessionConfig\Application;

use App\SessionConfig\Domain\SessionConfigOverrideRepositoryInterface;

final readonly class SessionConfigOverrideQuery
{
    public function __construct(
        private SessionConfigOverrideRepositoryInterface $overrides,
    ) {
    }

    /**
     * Returns the stored override for a scope as its canonical array (only set fields), or [] if none.
     *
     * @return array<string, mixed>
     */
    public function execute(string $scopeKey): array
    {
        $override = $this->overrides->find($scopeKey);

        return null === $override ? [] : $override->toArray();
    }
}
