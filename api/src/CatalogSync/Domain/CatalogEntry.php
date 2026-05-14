<?php

declare(strict_types=1);

namespace App\CatalogSync\Domain;

final readonly class CatalogEntry
{
    /**
     * @param list<array{label: string, url: ?string}> $links
     */
    public function __construct(
        public string $name,
        public string $availability,
        public ?string $prStatus,
        public bool $adultContent,
        public ?string $notes,
        public array $links,
        public bool $bundledWithAp,
    ) {
    }
}
