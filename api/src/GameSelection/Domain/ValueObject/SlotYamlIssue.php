<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\ValueObject;

use App\GameSelection\Domain\Enum\SlotYamlProblem;

/**
 * One setting of a player's YAML that no longer holds after an apworld switch (story 38.7). `option`
 * is dotted for a dict sub-setting (`game_options.difficulty`), empty for an unreadable YAML.
 */
final readonly class SlotYamlIssue
{
    public function __construct(
        public string $option,
        public SlotYamlProblem $problem,
        public ?string $value,
    ) {
    }

    /**
     * The sentence shown to the player on their slot and in the notification.
     */
    public function message(): string
    {
        return match ($this->problem) {
            SlotYamlProblem::RemovedOption => sprintf('« %s » n\'existe plus dans cette version.', $this->option),
            SlotYamlProblem::UnknownValue => sprintf('« %s » : la valeur « %s » n\'est plus acceptée.', $this->option, $this->value ?? ''),
            SlotYamlProblem::OutOfRange => sprintf('« %s » : %s est hors des bornes de cette version.', $this->option, $this->value ?? ''),
            SlotYamlProblem::UnknownSubOption => sprintf('« %s » n\'existe plus dans cette version.', $this->option),
            SlotYamlProblem::Unreadable => 'Ton YAML n\'a pas pu être relu : vérifie-le avant la partie.',
        };
    }
}
