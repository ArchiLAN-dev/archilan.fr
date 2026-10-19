<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

/**
 * Result of starting a test generation of a YAML from an option editor (story 9.57).
 */
enum GameYamlTestOutcome: string
{
    case Started = 'started';
    /** Empty, or longer than StartGameYamlTest::MAX_YAML_BYTES. */
    case InvalidYaml = 'invalid_yaml';
    /** No such game, or no apworld configured for it. */
    case UnknownGame = 'unknown_game';
    /** The game reads a file (a ROM) at generation: only the slot test can join it (story 44.7). */
    case RequiresFile = 'requires_file';
    /** The player already started StartGameYamlTest::MAX_TESTS_PER_WINDOW tests in the window. */
    case TooManyTests = 'too_many_tests';
    case RunnerUnavailable = 'runner_unavailable';
}
