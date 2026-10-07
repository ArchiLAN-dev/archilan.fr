<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

use App\Community\Application\Support\ProfileTitleCatalog;

/** The profile titles as the site and the admin read them (story 41.22). */
final readonly class ProfileTitleCatalogQuery
{
    public function __construct(private ProfileTitleCatalog $catalog)
    {
    }

    /**
     * The titles that can be worn, in order.
     *
     * @return list<array{key: string, label: string, access: string, rarity: string, icon: string|null}>
     */
    public function published(): array
    {
        $titles = [];
        foreach ($this->catalog->titles() as $title) {
            if (!$title['retired']) {
                $titles[] = ['key' => $title['key'], 'label' => $title['label'], 'access' => $title['access']->value, 'rarity' => $title['rarity'], 'icon' => $title['icon']];
            }
        }

        return $titles;
    }

    /**
     * @return list<array{key: string, label: string, access: string, retired: bool, position: int, rarity: string, icon: string|null}>
     */
    public function forAdmin(): array
    {
        return array_map(static fn (array $title): array => [...$title, 'access' => $title['access']->value], $this->catalog->titles());
    }
}
