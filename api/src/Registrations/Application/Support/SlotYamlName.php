<?php

declare(strict_types=1);

namespace App\Registrations\Application\Support;

use App\Shared\Domain\ValueObject\SlotName;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The slot name (`name:`) of a registration slot's YAML, checked the same way whoever saves it: the player, or an
 * admin on their behalf (story 5.9).
 */
final class SlotYamlName
{
    /**
     * Validates the YAML `name:` charset/length. Returns an error message, or null when the name is valid or
     * absent/unparseable (a broken YAML fails later in the pipeline).
     */
    public static function error(string $playerYaml): ?string
    {
        try {
            $parsed = Yaml::parse($playerYaml);
        } catch (ParseException) {
            return null;
        }

        if (!is_array($parsed) || !is_string($parsed['name'] ?? null)) {
            return null;
        }

        if (!SlotName::isValid($parsed['name'])) {
            return sprintf(
                'Nom de slot invalide : seuls les lettres, chiffres, _ et les placeholders {number}/{player} sont autorisés (%d caractères max).',
                SlotName::MAX_LENGTH,
            );
        }

        if (SlotName::isReserved($parsed['name'])) {
            return sprintf('Le nom « %s » est réservé : choisis-en un autre.', SlotName::RESERVED_NAME);
        }

        return null;
    }
}
