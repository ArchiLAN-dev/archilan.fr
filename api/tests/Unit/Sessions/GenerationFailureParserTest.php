<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Application\Support\GenerationFailureParser;
use PHPUnit\Framework\TestCase;

final class GenerationFailureParserTest extends TestCase
{
    /** Real stderr of the 2026-07-30 "A Bug's Life" incident (abridged traceback). */
    private const string ABL_STDERR = <<<'LOG'
DEBUG combined apworlds: ['0cf638ef.apworld', 'ahit.apworld']
Warning: pip install failed for factorio: ERROR: Could not find a version that satisfies the requirement factorio-rcon-py==2.1.3 (from versions: none)
ERROR: No matching distribution found for factorio-rcon-py==2.1.3
Warning: pip install failed for zillion: ERROR: Error [Errno 2] No such file or directory: 'git' while executing command git version
ERROR: Cannot find command 'git' - do you have 'git' installed and in your PATH?
DEBUG worlds loaded: ["A Bug's Life", 'A Hat in Time']
DEBUG host gates enabled: {'stardew_valley_options': {'allow_chaos_er': True}}
Traceback (most recent call last):
  File "/usr/local/bin/generate_multiworld.py", line 449, in <module>
    ERmain(erargs, seed)
  File "/app/ArchipelagoSrc/worlds/AutoWorld.py", line 169, in _timed_call
    ret = method(*args)
  File "/tmp/apworld_r72gtyq0/abugslife/__init__.py", line 597, in create_items
    raise Exception(
    ...<3 lines>...
    )
Exception: Too many upgrade items based on LEVEL_CAPS: 141 items for 16 locations. Disable some location categories/options or verify cap data.
Exception in <bound method BugsLifeWorld.create_items of <worlds.abugslife.BugsLifeWorld object at 0x7fecb271d940>> for player 2, named masterkafey_ABL.
LOG;

    /** Real stderr of the 2026-07-30 "2048" incident (abridged tracebacks). */
    private const string YAML_2048_STDERR = <<<'LOG'
DEBUG combined apworlds: ['13a02b1f.apworld']
DEBUG worlds loaded: ['2048', 'Archipelago']
ERROR:root:Exception reading settings in file masterkafey_.yaml document #1 (name: masterkafey_')
Traceback (most recent call last):
  File "/app/ArchipelagoSrc/Generate.py", line 246, in main
    settings_object: argparse.Namespace = (cached[doc_index] if cached else roll_settings(yaml, args.plando))
  File "/app/ArchipelagoSrc/Generate.py", line 587, in roll_settings
    raise Exception(f"No game options for selected game \"{ret.game}\" found.")
Exception: No game options for selected game "2048" found.
Traceback (most recent call last):
  File "/usr/local/bin/generate_multiworld.py", line 448, in <module>
    erargs, seed = Generate.main()
ValueError: Encountered 1 error(s) in player files. See logs for full tracebacks.

1. File masterkafey_.yaml document #1 (name: masterkafey_') is invalid. Please fix your yaml.
Exception: No game options for selected game "2048" found.
LOG;

    /** Real stderr of the 2026-07-31 Atlyss preflight: the world raises a multi-line OptionError. */
    private const string ATLYSS_STDERR = <<<'LOG'
DEBUG host gates enabled: {'stardew_valley_options': {'allow_chaos_er': True}}
Traceback (most recent call last):
  File "/usr/local/bin/generate_multiworld.py", line 454, in <module>
    ERmain(erargs, seed)
  File "/app/ArchipelagoSrc/worlds/AutoWorld.py", line 169, in _timed_call
    ret = method(*args)
  File "/tmp/apworld_6oe69zs7/Atlyss/Options.py", line 100, in raise_yaml_error
    raise OptionError(f'\n\n=== Atlyss YAML ERROR ===\n...')
Options.OptionError:

=== Atlyss YAML ERROR ===
Atlyss: 1 You cannot have the same class selected for main_class and secondary_class, PLEASE FIX YOUR YAML


Exception in <bound method Atlyss.generate_early of <worlds.Atlyss.Atlyss object at 0x7f767f090980>> for player 1, named Player1.
LOG;

    /** Real stderr of the 2026-07-31 Beat Saber preflight: single-line message, must stay verbatim. */
    private const string BEAT_SABER_STDERR = <<<'LOG'
Traceback (most recent call last):
  File "/tmp/apworld_kvtynptf/beat_saber/BeatmapMasterList.py", line 116, in save_to_json
    with open(full_path, "w", encoding="utf-8") as f:
FileNotFoundError: [Errno 2] No such file or directory: '/Archipelago/data/ranked_maps.json'
Exception in <bound method BSWorld.generate_early of <worlds.beat_saber.BSWorld object at 0x7fa4d42916a0>> for player 1, named Player1.
LOG;

    public function testParseRebuildsMultiLineExceptionMessage(): void
    {
        $report = GenerationFailureParser::parse(self::ATLYSS_STDERR);

        self::assertCount(1, $report->findings);
        self::assertSame('Player1', $report->findings[0]->slotName);
        self::assertStringContainsString(
            'You cannot have the same class selected for main_class and secondary_class',
            $report->findings[0]->message,
        );
        // The useless AutoWorld marker must never be the message when a real cause exists.
        self::assertStringNotContainsString('bound method', $report->findings[0]->message);
    }

    public function testParseKeepsSingleLineMessageVerbatim(): void
    {
        $report = GenerationFailureParser::parse(self::BEAT_SABER_STDERR);

        self::assertCount(1, $report->findings);
        self::assertSame('Player1', $report->findings[0]->slotName);
        self::assertSame(
            "FileNotFoundError: [Errno 2] No such file or directory: '/Archipelago/data/ranked_maps.json'",
            $report->findings[0]->message,
        );
    }

    public function testParseAttributesAutoworldFailureToNamedSlot(): void
    {
        $report = GenerationFailureParser::parse(self::ABL_STDERR);

        self::assertCount(1, $report->findings);
        self::assertSame('masterkafey_ABL', $report->findings[0]->slotName);
        self::assertSame(
            'Exception: Too many upgrade items based on LEVEL_CAPS: 141 items for 16 locations. Disable some location categories/options or verify cap data.',
            $report->findings[0]->message,
        );
    }

    public function testParseAttributesPlayerFileErrorToSlotFromFilename(): void
    {
        $report = GenerationFailureParser::parse(self::YAML_2048_STDERR);

        self::assertCount(1, $report->findings);
        self::assertSame('masterkafey_', $report->findings[0]->slotName);
        self::assertSame('Exception: No game options for selected game "2048" found.', $report->findings[0]->message);
    }

    public function testParseStripsDebugAndPipNoiseFromCleanedLog(): void
    {
        $report = GenerationFailureParser::parse(self::ABL_STDERR);

        self::assertStringNotContainsString('DEBUG', $report->cleanedLog);
        self::assertStringNotContainsString('pip install failed', $report->cleanedLog);
        self::assertStringNotContainsString('No matching distribution found', $report->cleanedLog);
        self::assertStringContainsString('Too many upgrade items', $report->cleanedLog);
    }

    public function testParseKeepsRootLoggerErrorLines(): void
    {
        $report = GenerationFailureParser::parse(self::YAML_2048_STDERR);

        self::assertStringContainsString('ERROR:root:Exception reading settings', $report->cleanedLog);
    }

    public function testParseStripsOrchestratorEnvelope(): void
    {
        $report = GenerationFailureParser::parse('generate_multiworld.py exited 1: FillError: No more spots to place Progressive Sword.');

        self::assertCount(1, $report->findings);
        self::assertNull($report->findings[0]->slotName);
        self::assertSame('FillError: No more spots to place Progressive Sword.', $report->findings[0]->message);
        self::assertSame('FillError: No more spots to place Progressive Sword.', $report->cleanedLog);
    }

    public function testParseFallsBackToLastExceptionLineUnattributed(): void
    {
        $log = "Traceback (most recent call last):\n  File \"Fill.py\", line 42, in fill\nFillError: No more spots to place items, 3 remaining.";

        $report = GenerationFailureParser::parse($log);

        self::assertCount(1, $report->findings);
        self::assertNull($report->findings[0]->slotName);
        self::assertSame('FillError: No more spots to place items, 3 remaining.', $report->findings[0]->message);
    }

    public function testParseReturnsNoFindingsForUnrecognizedText(): void
    {
        $report = GenerationFailureParser::parse("Génération bloquée : aucun retour de l'orchestrateur.");

        self::assertSame([], $report->findings);
        self::assertSame("Génération bloquée : aucun retour de l'orchestrateur.", $report->cleanedLog);
    }

    public function testParseHandlesNullAndEmptyReason(): void
    {
        self::assertSame([], GenerationFailureParser::parse(null)->findings);
        self::assertSame('', GenerationFailureParser::parse(null)->cleanedLog);
        self::assertSame([], GenerationFailureParser::parse('  ')->findings);
    }

    public function testParseDeduplicatesIdenticalFindings(): void
    {
        $log = "1. File slot_a.yaml document #1 (name: A) is invalid. Please fix your yaml.\n"
            ."Exception: Broken option.\n"
            ."1. File slot_a.yaml document #1 (name: A) is invalid. Please fix your yaml.\n"
            .'Exception: Broken option.';

        $report = GenerationFailureParser::parse($log);

        self::assertCount(1, $report->findings);
        self::assertSame('slot_a', $report->findings[0]->slotName);
    }
}
