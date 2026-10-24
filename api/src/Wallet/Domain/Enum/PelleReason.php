<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Enum;

/** Story 41.1: why pelles moved. Later stories add the earning and spending reasons. */
enum PelleReason: string
{
    case AdminCredit = 'admin_credit';
    case AdminDebit = 'admin_debit';
    // Story 41.2: event pelles handed out during an event, then converted and destroyed at its end.
    case EventDistribution = 'event_distribution';
    case EventConversion = 'event_conversion';
    case EventExpired = 'event_expired';
    // Story 41.3: a hint bought with pelles, and its refund when the hint could not be given.
    case HintPurchase = 'hint_purchase';
    case HintRefund = 'hint_refund';
    // Story 41.4: a bounty's pelles held, paid to the sender of the item, or given back.
    case BountyEscrow = 'bounty_escrow';
    case BountyReward = 'bounty_reward';
    case BountyRefund = 'bounty_refund';
    // Story 41.5: a tutorial contribution approved, paid at the admin's discretion.
    case ContributionReward = 'contribution_reward';
    // Story 41.6: a quest of the week accomplished.
    case QuestReward = 'quest_reward';
    // Story 41.7: a cosmetic bought in the shop.
    case ShopPurchase = 'shop_purchase';
    // Story 41.25: a first step of a newcomer.
    case WelcomeReward = 'welcome_reward';
    // Story 30.52: a collection of achievements completed.
    case CollectionReward = 'collection_reward';
    // Story 41.36: a private run's bronze, handed out as it is played (41.37), burned at its end and given back
    // when a cancelled run is restored.
    case BronzeStart = 'bronze_start';
    case BronzeCheck = 'bronze_check';
    case BronzeGoal = 'bronze_goal';
    case BronzeExpired = 'bronze_expired';
    case BronzeRestored = 'bronze_restored';
    // Story 41.40: a source found at the blind test of an event, credited by its app.
    case BlindTest = 'blind_test';
}
