<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

/**
 * Result of starting a YAML test on an apworld candidate (story 38.14).
 */
enum ApworldCandidateYamlTestOutcome: string
{
    case Started = 'started';
    /** Empty, or longer than StartApworldCandidateYamlTest::MAX_YAML_BYTES. */
    case InvalidYaml = 'invalid_yaml';
    /** The game has no candidate in test, awaiting approval, rejected or expired. */
    case NoCandidate = 'no_candidate';
    case RunnerUnavailable = 'runner_unavailable';
}
