<?php

declare(strict_types=1);

namespace App\Sessions\Application\Exception;

/**
 * A generation named a provided file the orchestrator no longer holds (story 44.2): more than 30
 * minutes went by, or the orchestrator restarted. The launcher has to send it again.
 */
final class RequiredGenerationFileExpiredException extends \RuntimeException
{
    public const string MESSAGE = 'Le fichier fourni pour la génération n\'est plus disponible (délai dépassé ou serveur redémarré). Relance la partie en le renvoyant.';

    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(self::MESSAGE, 0, $previous);
    }
}
