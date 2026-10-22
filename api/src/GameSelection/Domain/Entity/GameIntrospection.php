<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * What the introspection of a game's apworld produced: its default YAML, option table, location, item and item
 * group names (story 38.20).
 *
 * Kept off the `game` table because it is by far the heaviest part of a game - thousands of names for some
 * worlds - and almost no reader needs it: every list of games (catalog sync, run picker, event selection, update
 * check) decoded all of it for all 855 games and ran PHP out of memory once item names were added (v0.44.0).
 * {@see Game} references it lazily, so a list never touches it and a single-game reader loads one row.
 *
 * Part of the Game aggregate: only Game creates and changes it, through its own named methods.
 */
#[ORM\Entity]
#[ORM\Table(name: 'game_introspection')]
final class GameIntrospection
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'game_id', type: 'string', length: 32)]
        private string $gameId,
        #[ORM\Column(name: 'default_yaml', type: 'text', nullable: true)]
        private ?string $defaultYaml = null,
        /**
         * What the apworld says each of its options is, from introspection (stories 9.25 / 9.33).
         *
         * Range bounds only at first, which is why the editor guessed every other type from the
         * shape of the value. Rows written back then carry `{min, max, default}` with no `type`.
         *
         * A `dict` row may also carry `keys` (story 9.51): per sub-setting, the values it accepts,
         * for the worlds whose `OptionDict` declares a `schema`. Absent for all the others, which is
         * the majority - and absent means "nothing was declared", not "an empty list was".
         *
         * @var array<string, array{type?: string, min?: int, max?: int, default?: int|string|bool|null, values?: list<string>, keys?: array<string, array{values: list<string>}>}>|null
         */
        #[ORM\Column(name: 'option_types', type: 'json', nullable: true)]
        private ?array $optionTypes = null,
        /**
         * Static apworld location names (the World class's location_name_to_id keys), from introspection
         * (story 4.14). Free-text suggestion hint for the location-typed YAML options.
         *
         * @var list<string>|null
         */
        #[ORM\Column(name: 'location_names', type: 'json', nullable: true)]
        private ?array $locationNames = null,
        /**
         * Static apworld item names (item_name_to_id keys), from introspection (story 9.58): the same kind of hint
         * as the locations, for the item-typed YAML options (start_hints, start_inventory, local_items...).
         *
         * @var list<string>|null
         */
        #[ORM\Column(name: 'item_names', type: 'json', nullable: true)]
        private ?array $itemNames = null,
        /**
         * The world's item groups (item_name_groups, without "Everything"), group => item names (story 9.58).
         *
         * @var array<string, list<string>>|null
         */
        #[ORM\Column(name: 'item_groups', type: 'json', nullable: true)]
        private ?array $itemGroups = null,
    ) {
    }

    public function getGameId(): string
    {
        return $this->gameId;
    }

    public function getDefaultYaml(): ?string
    {
        return $this->defaultYaml;
    }

    public function recordDefaultYaml(?string $defaultYaml): void
    {
        $this->defaultYaml = $defaultYaml;
    }

    /**
     * @return array<string, array{type?: string, min?: int, max?: int, default?: int|string|bool|null, values?: list<string>, keys?: array<string, array{values: list<string>}>}>|null
     */
    public function getOptionTypes(): ?array
    {
        return $this->optionTypes;
    }

    /**
     * @param array<string, array{type?: string, min?: int, max?: int, default?: int|string|bool|null, values?: list<string>, keys?: array<string, array{values: list<string>}>}>|null $optionTypes
     */
    public function recordOptionTypes(?array $optionTypes): void
    {
        $this->optionTypes = $optionTypes;
    }

    /**
     * @return list<string>|null
     */
    public function getLocationNames(): ?array
    {
        return $this->locationNames;
    }

    /**
     * @param list<string>|null $locationNames
     */
    public function recordLocationNames(?array $locationNames): void
    {
        $this->locationNames = $locationNames;
    }

    /**
     * @return list<string>|null
     */
    public function getItemNames(): ?array
    {
        return $this->itemNames;
    }

    /**
     * @return array<string, list<string>>|null
     */
    public function getItemGroups(): ?array
    {
        return $this->itemGroups;
    }

    /**
     * @param list<string>|null                $itemNames
     * @param array<string, list<string>>|null $itemGroups
     */
    public function recordItems(?array $itemNames, ?array $itemGroups): void
    {
        $this->itemNames = $itemNames;
        $this->itemGroups = $itemGroups;
    }
}
