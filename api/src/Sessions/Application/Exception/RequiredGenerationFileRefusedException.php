<?php

declare(strict_types=1);

namespace App\Sessions\Application\Exception;

/**
 * The orchestrator did not take a file provided for a generation (story 44.2): too large, too many
 * files held at once, or the orchestrator did not answer. Nothing was launched.
 */
final class RequiredGenerationFileRefusedException extends \RuntimeException
{
}
