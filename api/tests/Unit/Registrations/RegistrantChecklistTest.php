<?php

declare(strict_types=1);

namespace App\Tests\Unit\Registrations;

use App\Registrations\Domain\Service\RegistrantChecklist;
use PHPUnit\Framework\TestCase;

/** Story 53.2: what a registrant still has to do, line by line. */
final class RegistrantChecklistTest extends TestCase
{
    public function testAFreshRegistrationOnlyAsksToConfirm(): void
    {
        $items = $this->build();

        self::assertSame(['confirm', 'presence', 'discord'], array_column($items, 'key'));
        self::assertSame(['key' => 'confirm', 'state' => 'todo', 'required' => true], $items[0]);
        self::assertSame(['key' => 'presence', 'state' => 'optional', 'required' => false], $items[1]);
    }

    public function testGamesAppearOnlyWithAGameSelection(): void
    {
        self::assertSame('todo', $this->state($this->build(gameSelectionEnabled: true), 'games'));
        self::assertSame('done', $this->state($this->build(gameSelectionEnabled: true, slots: 2), 'games'));
        self::assertNull($this->item($this->build(slots: 2), 'games'));
    }

    public function testYamlCountsTheConfigurableSlotsAndPointsAtTheFirstMissing(): void
    {
        $item = $this->item($this->build(slots: 3, configurableSlots: 2, slotsMissingYaml: ['s2']), 'yaml');

        self::assertSame(['key' => 'yaml', 'state' => 'todo', 'required' => true, 'detail' => ['done' => 1, 'total' => 2, 'firstSlotId' => 's2']], $item);
    }

    public function testYamlIsDoneWhenNothingWaits(): void
    {
        $item = $this->item($this->build(slots: 2, configurableSlots: 2), 'yaml');

        self::assertSame(['key' => 'yaml', 'state' => 'done', 'required' => true, 'detail' => ['done' => 2, 'total' => 2]], $item);
    }

    public function testNoConfigurableSlotMeansNoYamlLine(): void
    {
        self::assertNull($this->item($this->build(slots: 2), 'yaml'));
    }

    public function testRegistrationQuestionsAppearOnlyWhenAsked(): void
    {
        self::assertNull($this->item($this->build(), 'registration_questions'));
        self::assertSame('todo', $this->state($this->build(registrationQuestionsDone: false), 'registration_questions'));
        self::assertSame('done', $this->state($this->build(registrationQuestionsDone: true), 'registration_questions'));
    }

    public function testPendingSurveysAreOptionalWithTheirCount(): void
    {
        self::assertNull($this->item($this->build(), 'surveys'));
        self::assertSame(
            ['key' => 'surveys', 'state' => 'optional', 'required' => false, 'detail' => ['count' => 2]],
            $this->item($this->build(pendingSurveys: 2), 'surveys'),
        );
    }

    public function testPaymentAppearsOnlyForAHelloAssoEvent(): void
    {
        self::assertNull($this->item($this->build(), 'payment'));
        self::assertSame(
            ['key' => 'payment', 'state' => 'done', 'required' => true],
            $this->item($this->build(paymentExpected: true, paymentStatus: 'Processed'), 'payment'),
        );
    }

    public function testAnUnpaidOrderSaysWhatWasFound(): void
    {
        self::assertSame(
            ['key' => 'payment', 'state' => 'todo', 'required' => true, 'detail' => ['found' => false, 'stale' => false]],
            $this->item($this->build(paymentExpected: true), 'payment'),
        );
        self::assertSame(
            ['key' => 'payment', 'state' => 'todo', 'required' => true, 'detail' => ['found' => true, 'stale' => true]],
            $this->item($this->build(paymentExpected: true, paymentStatus: 'Refused', paymentStale: true), 'payment'),
        );
    }

    public function testEverythingDone(): void
    {
        $items = $this->build(
            confirmed: true,
            gameSelectionEnabled: true,
            slots: 1,
            configurableSlots: 1,
            registrationQuestionsDone: true,
            paymentExpected: true,
            paymentStatus: 'paid',
            hasPresence: true,
            discordLinked: true,
        );

        self::assertSame(['done'], array_values(array_unique(array_column($items, 'state'))));
        self::assertSame(['confirm', 'games', 'yaml', 'registration_questions', 'payment', 'presence', 'discord'], array_column($items, 'key'));
    }

    /**
     * @param list<string> $slotsMissingYaml
     *
     * @return list<array{key: string, state: 'done'|'todo'|'optional', required: bool, detail?: array<string, int|bool|string>}>
     */
    private function build(
        bool $confirmed = false,
        bool $gameSelectionEnabled = false,
        int $slots = 0,
        int $configurableSlots = 0,
        array $slotsMissingYaml = [],
        ?bool $registrationQuestionsDone = null,
        int $pendingSurveys = 0,
        bool $paymentExpected = false,
        ?string $paymentStatus = null,
        bool $paymentStale = false,
        bool $hasPresence = false,
        bool $discordLinked = false,
    ): array {
        return RegistrantChecklist::build($confirmed, $gameSelectionEnabled, $slots, $configurableSlots, $slotsMissingYaml, $registrationQuestionsDone, $pendingSurveys, $paymentExpected, $paymentStatus, $paymentStale, $hasPresence, $discordLinked);
    }

    /**
     * @param list<array{key: string, state: 'done'|'todo'|'optional', required: bool, detail?: array<string, int|bool|string>}> $items
     *
     * @return array{key: string, state: 'done'|'todo'|'optional', required: bool, detail?: array<string, int|bool|string>}|null
     */
    private function item(array $items, string $key): ?array
    {
        foreach ($items as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param list<array{key: string, state: 'done'|'todo'|'optional', required: bool, detail?: array<string, int|bool|string>}> $items
     */
    private function state(array $items, string $key): ?string
    {
        return $this->item($items, $key)['state'] ?? null;
    }
}
