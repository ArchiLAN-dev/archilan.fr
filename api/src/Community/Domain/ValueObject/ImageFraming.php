<?php

declare(strict_types=1);

namespace App\Community\Domain\ValueObject;

/**
 * The part of an uploaded image a profile shows (story 30.43): the point aimed at, in percent of the image (0 to
 * 100 on each axis), and a zoom in percent (100 = the whole image fills the frame, up to 300). The file itself is
 * never cut, so a GIF keeps moving and its first frame (story 30.40), the same size, takes the same framing.
 */
final readonly class ImageFraming
{
    public const int MIN_ZOOM = 100;
    public const int MAX_ZOOM = 300;

    public int $x;
    public int $y;
    public int $zoom;

    /** Stored values are brought back within bounds; input is checked by fromInput(). */
    public function __construct(int $x, int $y, int $zoom)
    {
        $this->x = max(0, min(100, $x));
        $this->y = max(0, min(100, $y));
        $this->zoom = max(self::MIN_ZOOM, min(self::MAX_ZOOM, $zoom));
    }

    public static function centred(): self
    {
        return new self(50, 50, self::MIN_ZOOM);
    }

    /** The framing sent by a client, or null when it is not one. */
    public static function fromInput(mixed $value): ?self
    {
        if (!is_array($value)) {
            return null;
        }
        $x = $value['x'] ?? null;
        $y = $value['y'] ?? null;
        $zoom = $value['zoom'] ?? null;
        if (!is_int($x) || !is_int($y) || !is_int($zoom)) {
            return null;
        }
        if ($x < 0 || $x > 100 || $y < 0 || $y > 100 || $zoom < self::MIN_ZOOM || $zoom > self::MAX_ZOOM) {
            return null;
        }

        return new self($x, $y, $zoom);
    }

    public function isCentred(): bool
    {
        return 50 === $this->x && 50 === $this->y && self::MIN_ZOOM === $this->zoom;
    }

    /**
     * @return array{x: int, y: int, zoom: int}
     */
    public function toArray(): array
    {
        return ['x' => $this->x, 'y' => $this->y, 'zoom' => $this->zoom];
    }
}
