<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Enum;

/**
 * The first steps of a newcomer (story 41.25), each paid once for life. Code defined, like the quest metrics: all
 * but Discord are read from what was actually played, and a Discord account links to one site account only.
 */
enum WelcomeStep: string
{
    /** Story 41.25 review: the Discord step is keyed by the Discord account, which pays once. */
    public const string DISCORD_KEY_PREFIX = 'welcome:discord:discord-';

    case Discord = 'discord';
    case FirstCheck = 'check';
    case FirstWeekly = 'weekly';
    case FirstPartner = 'partner';
    case FirstGoal = 'goal';

    public function label(): string
    {
        return match ($this) {
            self::Discord => 'Lier ton compte Discord',
            self::FirstCheck => 'Faire ton premier check',
            self::FirstWeekly => 'Jouer ta première hebdo',
            self::FirstPartner => 'Jouer avec un autre membre',
            self::FirstGoal => 'Atteindre ton premier goal',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Discord => 'Pour les annonces, les rôles et le salon de tes parties.',
            self::FirstCheck => 'Un item trouvé dans une partie du site.',
            self::FirstWeekly => 'Une tentative de la run hebdo, avec au moins un check.',
            self::FirstPartner => 'Un check chacun dans une même partie.',
            self::FirstGoal => 'Ton jeu terminé dans une partie ou une hebdo.',
        };
    }

    /** The gold pelles the step pays. */
    public function reward(): int
    {
        return match ($this) {
            self::Discord, self::FirstCheck => 10,
            self::FirstWeekly, self::FirstPartner => 15,
            self::FirstGoal => 25,
        };
    }

    /**
     * The ledger key of the step paid to a member: once for life. The Discord step names the Discord account
     * instead, so relinking it to another site account pays nothing.
     */
    public function rewardKey(string $userId, ?string $discordId): string
    {
        if (self::Discord === $this) {
            if (null === $discordId || '' === $discordId) {
                throw new \LogicException('The Discord step is keyed by a Discord account.');
            }

            return self::DISCORD_KEY_PREFIX.$discordId;
        }

        return sprintf('welcome:%s:%s', $this->value, $userId);
    }
}
