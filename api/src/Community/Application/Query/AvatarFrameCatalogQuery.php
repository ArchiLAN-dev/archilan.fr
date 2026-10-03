<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

use App\Community\Application\Support\AvatarFrameCatalog;
use App\Shared\Application\Support\PublicMediaUrlResolver;

/**
 * The video frames as the site and the admin read them (story 41.10). A built-in frame carries no files: the
 * frontend has them in its own catalog.
 */
final readonly class AvatarFrameCatalogQuery
{
    public function __construct(
        private AvatarFrameCatalog $catalog,
        private PublicMediaUrlResolver $publicMedia,
    ) {
    }

    /**
     * Every frame that can be worn, for the avatars and the picker.
     *
     * @return list<array{key: string, label: string, access: string, builtIn: bool, video: array{webm: string, mp4: string, poster: string, still: string}|null}>
     */
    public function published(): array
    {
        $frames = [];
        foreach ($this->catalog->videoFrames() as $frame) {
            if ($frame['retired']) {
                continue;
            }
            $frames[] = [
                'key' => $frame['key'],
                'label' => $frame['label'],
                'access' => $frame['access']->value,
                'builtIn' => $frame['builtIn'],
                'video' => $this->urls($frame['files']),
            ];
        }

        return $frames;
    }

    /**
     * Every frame, retired ones included, with its order.
     *
     * @return list<array{key: string, label: string, access: string, builtIn: bool, retired: bool, position: int, video: array{webm: string, mp4: string, poster: string, still: string}|null}>
     */
    public function forAdmin(): array
    {
        return array_map(fn (array $frame): array => [
            'key' => $frame['key'],
            'label' => $frame['label'],
            'access' => $frame['access']->value,
            'builtIn' => $frame['builtIn'],
            'retired' => $frame['retired'],
            'position' => $frame['position'],
            'video' => $this->urls($frame['files']),
        ], $this->catalog->videoFrames());
    }

    /**
     * @param array{webm: string, mp4: string, poster: string, still: string}|null $files
     *
     * @return array{webm: string, mp4: string, poster: string, still: string}|null
     */
    private function urls(?array $files): ?array
    {
        if (null === $files) {
            return null;
        }

        return [
            'webm' => $this->publicMedia->resolve($files['webm']),
            'mp4' => $this->publicMedia->resolve($files['mp4']),
            'poster' => $this->publicMedia->resolve($files['poster']),
            'still' => $this->publicMedia->resolve($files['still']),
        ];
    }
}
