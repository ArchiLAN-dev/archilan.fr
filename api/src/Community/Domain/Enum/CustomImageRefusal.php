<?php

declare(strict_types=1);

namespace App\Community\Domain\Enum;

/** Why a profile image upload is refused (story 30.40). */
enum CustomImageRefusal
{
    /** A banner image, from someone who is neither a member nor an admin. */
    case NotAllowed;

    /** A GIF, from someone who is not an admin. */
    case GifAdminOnly;

    /** An animated PNG or WebP: animation only comes as a GIF. */
    case AnimationUnsupported;

    /** Not a JPEG, PNG, WebP or GIF (SVG included). */
    case UnsupportedType;

    case TooLarge;
}
