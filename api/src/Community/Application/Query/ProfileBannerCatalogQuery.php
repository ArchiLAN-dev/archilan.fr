<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

use App\Community\Application\Support\ProfileBannerCatalog;
use App\Shared\Application\Support\PublicMediaUrlResolver;

/**
 * The profile banners as the site and the admin read them (story 41.11). A preset of the code carries no files: the
 * frontend draws it.
 */
final readonly class ProfileBannerCatalogQuery
{
    public function __construct(
        private ProfileBannerCatalog $catalog,
        private PublicMediaUrlResolver $publicMedia,
    ) {
    }

    /**
     * Every banner that can be shown, for the profiles and the editor.
     *
     * @return list<array{key: string, label: string, access: string, builtIn: bool, media: array{image: string, webm: string|null, mp4: string|null}|null}>
     */
    public function published(): array
    {
        $banners = [];
        foreach ($this->catalog->banners() as $banner) {
            if ($banner['retired']) {
                continue;
            }
            $banners[] = [
                'key' => $banner['key'],
                'label' => $banner['label'],
                'access' => $banner['access']->value,
                'builtIn' => $banner['builtIn'],
                'media' => $this->urls($banner['files']),
            ];
        }

        return $banners;
    }

    /**
     * Every banner, retired ones included, with its order.
     *
     * @return list<array{key: string, label: string, access: string, builtIn: bool, retired: bool, position: int, media: array{image: string, webm: string|null, mp4: string|null}|null}>
     */
    public function forAdmin(): array
    {
        return array_map(fn (array $banner): array => [
            'key' => $banner['key'],
            'label' => $banner['label'],
            'access' => $banner['access']->value,
            'builtIn' => $banner['builtIn'],
            'retired' => $banner['retired'],
            'position' => $banner['position'],
            'media' => $this->urls($banner['files']),
        ], $this->catalog->banners());
    }

    /**
     * @param array{image: string, webm: string|null, mp4: string|null}|null $files
     *
     * @return array{image: string, webm: string|null, mp4: string|null}|null
     */
    private function urls(?array $files): ?array
    {
        if (null === $files) {
            return null;
        }

        return [
            'image' => $this->publicMedia->resolve($files['image']),
            'webm' => null === $files['webm'] ? null : $this->publicMedia->resolve($files['webm']),
            'mp4' => null === $files['mp4'] ? null : $this->publicMedia->resolve($files['mp4']),
        ];
    }
}
