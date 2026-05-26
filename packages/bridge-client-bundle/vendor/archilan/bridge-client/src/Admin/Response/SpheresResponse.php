<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Admin\Response;

final readonly class SpheresResponse
{
    /**
     * @param Sphere[] $spheres
     */
    public function __construct(
        public bool $cached,
        public array $spheres,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $spheres = [];
        foreach (is_array($data['spheres'] ?? null) ? $data['spheres'] : [] as $s) {
            if (is_array($s)) {
                /** @var array<string, mixed> $s */
                $spheres[] = Sphere::fromArray($s);
            }
        }

        return new self(
            cached:  (bool) ($data['cached'] ?? false),
            spheres: $spheres,
        );
    }
}
