<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Support;

use App\GameSelection\Domain\Entity\ApworldIncident;
use App\GameSelection\Domain\Enum\ApworldIncidentStatus;
use App\GameSelection\Domain\Enum\ApworldIncidentType;
use App\Sessions\Application\Support\GenerationFailureParser;

/**
 * Turns an apworld incident transition into a staff channel message (story 38.2).
 *
 * The staff channel is read by people who did not open the admin page: each message has to say what
 * broke, which version, and one line of why - never the multi-kilobyte generator dump, never a full
 * hash. Lengths are bounded here, at the source, to Discord's embed limits.
 */
final readonly class StaffAlertFactory
{
    public const int TITLE_MAX = 256;
    public const int DESCRIPTION_MAX = 4096;
    private const int SHORT_HASH_LENGTH = 12;

    public function __construct(private string $siteUrl)
    {
    }

    public function opened(ApworldIncident $incident, string $gameName): StaffAlert
    {
        $lines = [
            sprintf('**Type :** %s', self::typeLabel($incident->getType())),
            sprintf('**Version :** `%s`', substr($incident->getApworldHash(), 0, self::SHORT_HASH_LENGTH)),
            sprintf('**Erreur :** %s', GenerationFailureParser::summarize($incident->getError())),
        ];

        return $this->alert(sprintf('Apworld en échec : %s', $gameName), $lines, $incident, StaffAlertLevel::Alert);
    }

    public function acknowledged(ApworldIncident $incident, string $gameName, string $adminName): StaffAlert
    {
        return $this->alert(sprintf("%s s'occupe de %s", $adminName, $gameName), [], $incident, StaffAlertLevel::Info);
    }

    /**
     * A null admin means the reconciliation closed it: a green verdict or a replaced apworld for a
     * resolution, an admin override on the verdict for an ignore.
     */
    public function closed(ApworldIncident $incident, string $gameName, ?string $adminName): StaffAlert
    {
        $outcome = match ($incident->getStatus()) {
            ApworldIncidentStatus::Resolved => null === $adminName
                ? 'incident résolu automatiquement'
                : sprintf('incident résolu par %s', $adminName),
            ApworldIncidentStatus::Ignored => null === $adminName
                ? 'incident ignoré, verdict forcé par un admin'
                : sprintf('incident ignoré par %s', $adminName),
            ApworldIncidentStatus::Open, ApworldIncidentStatus::Acknowledged => throw new \LogicException(sprintf('Incident %s is still active.', $incident->getId())),
        };

        return $this->alert(sprintf('%s : %s', $gameName, $outcome), [], $incident, StaffAlertLevel::Resolved);
    }

    /**
     * @param list<string> $lines
     */
    private function alert(string $title, array $lines, ApworldIncident $incident, StaffAlertLevel $level): StaffAlert
    {
        return new StaffAlert(
            self::bounded($title, self::TITLE_MAX),
            self::bounded(implode("\n", $lines), self::DESCRIPTION_MAX),
            sprintf('%s/admin/jeux/%s', rtrim($this->siteUrl, '/'), $incident->getGameId()),
            $level,
        );
    }

    private static function typeLabel(ApworldIncidentType $type): string
    {
        return match ($type) {
            ApworldIncidentType::PreflightFailed => 'Test de génération en échec',
        };
    }

    private static function bounded(string $text, int $max): string
    {
        return mb_strlen($text) <= $max ? $text : mb_substr($text, 0, $max - 1).'…';
    }
}
