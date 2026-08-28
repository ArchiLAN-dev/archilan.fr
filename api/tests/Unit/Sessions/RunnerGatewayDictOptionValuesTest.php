<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Infrastructure\Http\RunnerGateway;
use Archilan\OrchestratorClient\OrchestratorClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Story 9.51: an `OptionDict` declares no value vocabulary of its own, so a sub-setting was eleven
 * free text fields in a row. When the world declares a `schema`, introspection now says what each
 * sub-setting accepts, and this is the hop that carries it to the option-type table.
 *
 * The trap the tests pin down is the naming: on a dict, `values` holds the sub-setting NAMES
 * (`validKeys`) while `keys` holds the VALUES each of them accepts. They look alike on the wire and
 * mean opposite things - swapping them would put key names in the player's dropdown.
 */
final class RunnerGatewayDictOptionValuesTest extends TestCase
{
    public function testDictOptionCarriesBothItsKeyNamesAndItsDeclaredValues(): void
    {
        $gateway = $this->gateway([
            'options' => [[
                'key' => 'game_options',
                'description' => 'In-game settings.',
                'type' => 'dict',
                'defaultValue' => ['battle_style' => 'shift', 'default_player_name' => 'player_name'],
                'validKeys' => ['battle_style', 'default_player_name'],
                'keys' => ['battle_style' => ['values' => ['shift', 'set']]],
            ]],
        ]);

        $types = $gateway->fetchOptionTypes('hash');

        self::assertSame('dict', $types['game_options']['type']);
        // Names on one side...
        self::assertSame(['battle_style', 'default_player_name'], $types['game_options']['values'] ?? null);
        // ...values on the other, and only for the sub-setting the schema spoke about.
        self::assertSame(['battle_style' => ['values' => ['shift', 'set']]], $types['game_options']['keys'] ?? null);
    }

    public function testAWorldThatDeclaresNoSchemaCarriesNoValues(): void
    {
        // The common case. The absence is what tells the editor to keep its free text field: an
        // empty `keys` would instead read as "the world declared a vocabulary, and it is empty".
        $gateway = $this->gateway([
            'options' => [[
                'key' => 'game_options',
                'description' => 'In-game settings.',
                'type' => 'dict',
                'defaultValue' => ['battle_style' => 'shift'],
                'validKeys' => ['battle_style'],
            ]],
        ]);

        self::assertArrayNotHasKey('keys', $gateway->fetchOptionTypes('hash')['game_options']);
    }

    public function testAChoiceOptionIsUntouched(): void
    {
        // `values` on a choice keeps meaning what it always meant.
        $gateway = $this->gateway([
            'options' => [[
                'key' => 'goal',
                'description' => 'Goal.',
                'type' => 'choice',
                'defaultValue' => 'champion',
                'validValues' => ['champion', 'elite_four'],
            ]],
        ]);

        $types = $gateway->fetchOptionTypes('hash');

        self::assertSame(['champion', 'elite_four'], $types['goal']['values'] ?? null);
        self::assertArrayNotHasKey('keys', $types['goal']);
    }

    /** @param array<string, mixed> $payload */
    private function gateway(array $payload): RunnerGateway
    {
        $json = json_encode($payload);
        self::assertIsString($json);

        $http = new MockHttpClient([new MockResponse($json, ['response_headers' => ['content-type' => 'application/json']])]);

        return new RunnerGateway(new OrchestratorClient('http://runner', 'key', $http), new NullLogger());
    }
}
