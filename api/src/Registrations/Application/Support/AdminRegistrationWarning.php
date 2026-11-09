<?php

declare(strict_types=1);

namespace App\Registrations\Application\Support;

/**
 * Story 5.10: a member-side rule an admin may override. For a member it blocks; for an admin it is only shown, and
 * the action goes through once the admin acknowledged it.
 */
enum AdminRegistrationWarning: string
{
    case RegistrationNotOpenYet = 'registration_not_open_yet';
    case RegistrationClosed = 'registration_closed';
    case EventNotPublished = 'event_not_published';
    case EventInProgress = 'event_in_progress';
    case CapacityFull = 'capacity_full';
    case EmailNotVerified = 'email_not_verified';
    case PrivateEvent = 'private_event';
    case MaxGamesExceeded = 'max_games_exceeded';
    case GameNotInEvent = 'game_not_in_event';
    case IncompleteYaml = 'incomplete_yaml';

    public function message(): string
    {
        return match ($this) {
            self::RegistrationNotOpenYet => 'Les inscriptions ne sont pas encore ouvertes.',
            self::RegistrationClosed => 'Les inscriptions sont closes.',
            self::EventNotPublished => 'L\'événement n\'est pas publié.',
            self::EventInProgress => 'L\'événement est en cours.',
            self::CapacityFull => 'L\'événement est complet : cette inscription dépasse la capacité.',
            self::EmailNotVerified => 'Le joueur n\'a pas confirmé son adresse email.',
            self::PrivateEvent => 'Le joueur n\'a pas accès à cet événement privé.',
            self::MaxGamesExceeded => 'La sélection dépasse le nombre de jeux autorisé par joueur.',
            self::GameNotInEvent => 'Un jeu ne fait pas partie de la sélection de l\'événement.',
            self::IncompleteYaml => 'Au moins un jeu n\'a pas encore de configuration YAML.',
        };
    }
}
