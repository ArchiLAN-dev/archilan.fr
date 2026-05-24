<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Tests\Apworlds;

use Archilan\OrchestratorClient\Apworlds\ApworldsClient;
use Archilan\OrchestratorClient\Apworlds\Response\ChoiceTemplateOption;
use Archilan\OrchestratorClient\Apworlds\Response\RangeTemplateOption;
use Archilan\OrchestratorClient\Apworlds\Response\TemplateOption;
use Archilan\OrchestratorClient\Apworlds\Response\TextTemplateOption;
use Archilan\OrchestratorClient\Apworlds\Response\ToggleTemplateOption;
use Archilan\OrchestratorClient\Apworlds\Response\UploadApworldResult;
use Archilan\OrchestratorClient\Http\HttpTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ApworldsClientTest extends TestCase
{
    private function client(MockResponse $response): ApworldsClient
    {
        $transport = new HttpTransport(new MockHttpClient($response), 'http://localhost:8000', 'key');

        return new ApworldsClient($transport);
    }

    private function uploadBody(string $hash, mixed $options = []): string
    {
        return json_encode(['hash' => $hash, 'options' => $options]) ?: '';
    }

    public function testUpload_returnsUploadApworldResult(): void
    {
        $body = $this->uploadBody('deadbeef', [
            ['key' => 'logic_percent', 'description' => 'Controls logic.', 'type' => 'range',
             'defaultValue' => 80, 'rangeMin' => 50, 'rangeMax' => 95],
        ]);
        $client = $this->client(new MockResponse($body, ['http_code' => 201]));
        $result = $client->upload('binary-data', 'game.apworld');

        $this->assertInstanceOf(UploadApworldResult::class, $result);
        $this->assertSame('deadbeef', $result->hash);
        $this->assertCount(1, $result->options);

        $opt = $result->options[0];
        $this->assertInstanceOf(RangeTemplateOption::class, $opt);
        $this->assertSame('logic_percent', $opt->key);
        $this->assertSame(80, $opt->default);
        $this->assertSame(50, $opt->rangeMin);
        $this->assertSame(95, $opt->rangeMax);
    }

    public function testUpload_choiceOption(): void
    {
        $body = $this->uploadBody('abc123', [
            ['key' => 'smallkey_shuffle', 'description' => 'Where keys go.',
             'type' => 'choice', 'defaultValue' => 'original_dungeon',
             'validValues' => ['original_dungeon', 'any_world', 'own_world'],
             'weights' => ['original_dungeon' => 50, 'any_world' => 0, 'own_world' => 0]],
        ]);
        $client = $this->client(new MockResponse($body, ['http_code' => 201]));
        $result = $client->upload('binary-data', 'game.apworld');

        $opt = $result->options[0];
        $this->assertInstanceOf(ChoiceTemplateOption::class, $opt);
        $this->assertSame('original_dungeon', $opt->default);
        $this->assertSame(['original_dungeon', 'any_world', 'own_world'], $opt->validValues);
        $this->assertSame(['original_dungeon' => 50, 'any_world' => 0, 'own_world' => 0], $opt->weights);
    }

    public function testUpload_toggleOption(): void
    {
        $body = $this->uploadBody('abc123', [
            ['key' => 'swordless', 'description' => 'No swords.', 'type' => 'toggle',
             'defaultValue' => false, 'weights' => ['false' => 50, 'true' => 0]],
        ]);
        $client = $this->client(new MockResponse($body, ['http_code' => 201]));
        $result = $client->upload('binary-data', 'game.apworld');

        $opt = $result->options[0];
        $this->assertInstanceOf(ToggleTemplateOption::class, $opt);
        $this->assertFalse($opt->default);
        $this->assertSame(['false' => 50, 'true' => 0], $opt->weights);
    }

    public function testUpload_emptyOptions(): void
    {
        $body = $this->uploadBody('abc123', []);
        $client = $this->client(new MockResponse($body, ['http_code' => 201]));
        $result = $client->upload('binary-data', 'game.apworld');

        $this->assertSame([], $result->options);
    }

    public function testUpload_sendsMultipartWithFile(): void
    {
        $capturedBody = '';
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedBody): MockResponse {
            $this->assertSame('POST', $method);
            $this->assertStringContainsString('/apworlds', $url);
            $capturedBody = $options['body'] ?? '';

            return new MockResponse($this->uploadBody('abc', []) ?: '', ['http_code' => 201]);
        });
        $client = new ApworldsClient(new HttpTransport($mock, 'http://localhost:8000', 'key'));
        $client->upload('file-binary-content', 'zelda.apworld');

        $this->assertIsString($capturedBody);
        $this->assertStringContainsString('zelda.apworld', $capturedBody);
    }

    public function testUpload_toggleTrueDefault(): void
    {
        $body = $this->uploadBody('abc123', [
            ['key' => 'disable_forced_camera', 'description' => 'Lock camera.', 'type' => 'toggle',
             'defaultValue' => true, 'weights' => ['false' => 0, 'true' => 50]],
        ]);
        $client = $this->client(new MockResponse($body, ['http_code' => 201]));
        $result = $client->upload('binary-data', 'game.apworld');

        $opt = $result->options[0];
        $this->assertInstanceOf(ToggleTemplateOption::class, $opt);
        $this->assertTrue($opt->default);
        $this->assertSame(['false' => 0, 'true' => 50], $opt->weights);
    }

    public function testUpload_choiceOption_noWeightsField_defaultsToEmpty(): void
    {
        $body = $this->uploadBody('abc123', [
            ['key' => 'some_choice', 'description' => 'Desc.', 'type' => 'choice',
             'defaultValue' => 'a', 'validValues' => ['a', 'b']],
        ]);
        $client = $this->client(new MockResponse($body, ['http_code' => 201]));
        $result = $client->upload('binary-data', 'game.apworld');

        $opt = $result->options[0];
        $this->assertInstanceOf(ChoiceTemplateOption::class, $opt);
        $this->assertSame([], $opt->weights);
    }

    public function testUpload_rangeNullDefault(): void
    {
        // Ranges like puzzle_randomization_seed have no concrete default
        $body = $this->uploadBody('abc123', [
            ['key' => 'puzzle_randomization_seed', 'description' => 'Seed value.',
             'type' => 'range', 'defaultValue' => null, 'rangeMin' => 1, 'rangeMax' => 9999999],
        ]);
        $client = $this->client(new MockResponse($body, ['http_code' => 201]));
        $result = $client->upload('binary-data', 'game.apworld');

        $opt = $result->options[0];
        $this->assertInstanceOf(RangeTemplateOption::class, $opt);
        $this->assertNull($opt->default);
        $this->assertSame(1, $opt->rangeMin);
        $this->assertSame(9999999, $opt->rangeMax);
    }

    public function testUpload_textOption(): void
    {
        // Text options have no structured default/values (e.g. locked_items, excluded_items)
        $body = $this->uploadBody('abc123', [
            ['key' => 'locked_items', 'description' => 'Guaranteed unlockable items.',
             'type' => 'text', 'defaultValue' => null],
        ]);
        $client = $this->client(new MockResponse($body, ['http_code' => 201]));
        $result = $client->upload('binary-data', 'game.apworld');

        $opt = $result->options[0];
        $this->assertInstanceOf(TextTemplateOption::class, $opt);
        $this->assertNull($opt->default);
    }

    public function testUpload_choiceWithRandomAsOption(): void
    {
        // Options like game_version or starting_robot_master include "random" as a selectable value
        $body = $this->uploadBody('abc123', [
            ['key' => 'game_version', 'description' => 'Red or Blue.',
             'type' => 'choice', 'defaultValue' => 'random',
             'validValues' => ['red', 'blue', 'random']],
        ]);
        $client = $this->client(new MockResponse($body, ['http_code' => 201]));
        $result = $client->upload('binary-data', 'game.apworld');

        $opt = $result->options[0];
        $this->assertInstanceOf(ChoiceTemplateOption::class, $opt);
        $this->assertSame('random', $opt->default);
        $this->assertSame(['red', 'blue', 'random'], $opt->validValues);
    }

    public function testUpload_multilineDescription(): void
    {
        $desc = "First line of description.\nSecond line with more detail.";
        $body = $this->uploadBody('abc123', [
            ['key' => 'mission_order', 'description' => $desc,
             'type' => 'choice', 'defaultValue' => 'golden_path',
             'validValues' => ['vanilla', 'golden_path', 'mini_campaign']],
        ]);
        $client = $this->client(new MockResponse($body, ['http_code' => 201]));
        $result = $client->upload('binary-data', 'game.apworld');

        $opt = $result->options[0];
        $this->assertSame($desc, $opt->description);
    }

    public function testUpload_choiceOption_hasNoRangeFields(): void
    {
        // A ChoiceTemplateOption structurally cannot have range fields — verified by instanceof
        $body = json_encode(['hash' => 'abc', 'options' => [
            ['key' => 'some_option', 'description' => 'Desc.', 'type' => 'choice',
             'defaultValue' => 'foo', 'validValues' => ['foo', 'bar']],
        ]]) ?: '';
        $client = $this->client(new MockResponse($body, ['http_code' => 201]));
        $result = $client->upload('binary-data', 'game.apworld');

        $this->assertInstanceOf(ChoiceTemplateOption::class, $result->options[0]);
    }

    public function testGetYamlTemplate_returnsRawYaml(): void
    {
        $yaml = "name: Zelda\nversion: 1\n";
        $client = $this->client(new MockResponse($yaml, ['http_code' => 200]));
        $result = $client->getYamlTemplate('deadbeef');

        $this->assertSame($yaml, $result);
    }
}
