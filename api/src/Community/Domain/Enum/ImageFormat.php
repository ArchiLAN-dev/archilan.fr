<?php

declare(strict_types=1);

namespace App\Community\Domain\Enum;

/** The image formats a profile accepts (story 30.40), each with the extension its stored object gets. */
enum ImageFormat: string
{
    case Jpeg = 'jpg';
    case Png = 'png';
    case Webp = 'webp';
    case Gif = 'gif';
}
