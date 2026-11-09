<?php

declare(strict_types=1);

namespace App\Tests\Unit\Communications;

use App\Communications\Application\Email\AdminDirectMessageEmail;
use App\Communications\Application\Email\ArchilanEmail;
use App\Communications\Application\Email\EventCapacityReachedEmail;
use App\Communications\Application\Email\RegistrationConfirmationEmail;
use App\Communications\Application\Email\RegistrationConfirmationReminderEmail;
use App\Communications\Application\Email\RegistrationReleasedEmail;
use App\Communications\Application\Email\SessionPausedWithoutSaveEmail;
use App\Communications\Application\Email\SessionRestartFailedEmail;
use App\Communications\Application\Email\SessionRunningEmail;
use App\Communications\Application\Email\SurveyOpenedEmail;
use App\Communications\Application\Email\SurveyReminderEmail;
use App\Communications\Application\Handler\RegistrationConfirmationHandler;
use App\Communications\Application\Message\RegistrationConfirmationMessage;
use App\Communications\Application\Service\ArchilanMailer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Story 4.15: every mail of the site carries the ArchiLAN HTML layout, not only a text body.
 */
final class HtmlEmailsTest extends TestCase
{
    private const string SITE = 'https://archilan.fr';

    /** @return iterable<string, array{ArchilanEmail, list<string>}> */
    public static function emails(): iterable
    {
        yield 'registration confirmed' => [
            new RegistrationConfirmationEmail('a@example.org', 'Alice', 'ArchiLAN #4', '07/11/2026 à 18h00', 'Lille', ['Celeste', 'Hollow Knight'], self::SITE.'/evenements/e1/inscription/r1/recap', self::SITE),
            ['Inscription confirmée', 'ArchiLAN #4', '07/11/2026 à 18h00', 'Lille', '<li>Celeste</li>', '<li>Hollow Knight</li>', 'href="https://archilan.fr/evenements/e1/inscription/r1/recap"', 'Voir mon inscription'],
        ];
        yield 'confirmation reminder' => [
            new RegistrationConfirmationReminderEmail('a@example.org', 'Alice', 'ArchiLAN #4', '01/11/2026', self::SITE.'/recap', self::SITE),
            ['pas encore confirmée', '01/11/2026', 'href="https://archilan.fr/recap"', 'Confirmer mon inscription'],
        ];
        yield 'survey opened' => [
            new SurveyOpenedEmail('a@example.org', 'Alice', 'Quand viens-tu ?', 'ArchiLAN #4', '20/11/2026 à 18h00', false, self::SITE.'/evenements/e1/sondages/s1', self::SITE),
            ['L&#039;orga a une question pour toi', 'Quand viens-tu ?', '20/11/2026 à 18h00', 'href="https://archilan.fr/evenements/e1/sondages/s1"', 'Répondre'],
        ];
        yield 'survey reminder' => [
            new SurveyReminderEmail('a@example.org', 'Alice', 'Ton retour', 'ArchiLAN #4', '27/11/2026 à 18h00', true, self::SITE.'/evenements/e1/sondages/s2', self::SITE),
            ['Tu n&#039;as pas encore répondu', 'Ton retour', 'Tes réponses sont anonymes', 'Répondre'],
        ];
        yield 'place released' => [
            new RegistrationReleasedEmail('a@example.org', 'Alice', 'ArchiLAN #4', self::SITE.'/evenements/e1', self::SITE),
            ['Ta place a été libérée', 'href="https://archilan.fr/evenements/e1"'],
        ];
        yield 'event full' => [
            new EventCapacityReachedEmail('admin@example.org', 'Admin', 'ArchiLAN #4', 48, self::SITE.'/admin/evenements/e1/inscriptions', self::SITE),
            ['Événement complet', '48 places', 'Voir les inscriptions'],
        ];
        yield 'admin message' => [
            new AdminDirectMessageEmail('a@example.org', 'Alice', 'Ta ROM', "Salut,\nil manque ta ROM.", self::SITE.'/evenements/e1/inscription/r1/recap', self::SITE),
            ['Ta ROM', 'Salut,<br>', 'il manque ta ROM.', 'Voir mon inscription'],
        ];
        yield 'session running' => [
            new SessionRunningEmail('a@example.org', 'Alice', 'ArchiLAN #4', 'ap.archilan.fr', 35001, 'secret', ['Alice_Celeste'], self::SITE),
            ['partie Archipelago est prête', '<li>Alice_Celeste</li>', 'ap.archilan.fr', '35001', 'secret'],
        ];
        yield 'paused without save' => [
            new SessionPausedWithoutSaveEmail('admin@example.org', 'sess-1', self::SITE),
            ['Sauvegarde échouée', 'sess-1'],
        ];
        yield 'restart failed' => [
            new SessionRestartFailedEmail('admin@example.org', 'sess-1', self::SITE),
            ['Redémarrage de session échoué', 'sess-1'],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('emails')]
    public function testTheMailHasTheArchilanLayoutAndItsContent(ArchilanEmail $email, array $expected): void
    {
        $html = $email->htmlBody();

        self::assertNotNull($html);
        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('https://archilan.fr/images/logo.webp', $html);
        foreach ($expected as $fragment) {
            self::assertStringContainsString($fragment, $html);
        }
        self::assertStringNotContainsString('—', $html);
    }

    public function testValuesFromMembersAndAdminsAreEscaped(): void
    {
        $confirmation = new RegistrationConfirmationEmail('a@example.org', '<b>Alice</b>', '<script>x</script>', 'demain', 'Lille', ['<i>Jeu</i>']);
        $message = new AdminDirectMessageEmail('a@example.org', null, '<h1>Objet</h1>', '<img src=x onerror=alert(1)>');

        foreach ([$confirmation->htmlBody(), $message->htmlBody()] as $html) {
            self::assertStringNotContainsString('<script>', $html);
            self::assertStringNotContainsString('<img src=x', $html);
            self::assertStringNotContainsString('<b>Alice</b>', $html);
            self::assertStringNotContainsString('<i>Jeu</i>', $html);
            self::assertStringNotContainsString('<h1>Objet</h1>', $html);
        }
    }

    public function testWithoutALinkTheConfirmationKeepsItsPreviousWording(): void
    {
        $email = new RegistrationConfirmationEmail('a@example.org', 'Alice', 'ArchiLAN #4', 'demain', 'Lille', []);

        self::assertStringNotContainsString('Voir mon inscription', $email->htmlBody());
        self::assertStringContainsString('Aucun jeu sélectionné.', $email->htmlBody());
        self::assertStringContainsString('Retrouve tous les détails sur le site ArchiLAN.', $email->textBody());
    }

    public function testTheConfirmationLinksTheRegistrationRecap(): void
    {
        $sent = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->willReturnCallback(static function (Email $email) use (&$sent): void {
            $sent = $email;
        });

        $handler = new RegistrationConfirmationHandler(new ArchilanMailer($mailer, new NullLogger(), 'noreply@archilan.fr'), self::SITE.'/');
        $handler(new RegistrationConfirmationMessage('a@example.org', 'Alice', 'ArchiLAN #4', 'demain', 'Lille', [], 'e1', 'r1'));

        self::assertInstanceOf(Email::class, $sent);
        self::assertStringContainsString('https://archilan.fr/evenements/e1/inscription/r1/recap', (string) $sent->getHtmlBody());
        self::assertStringContainsString('https://archilan.fr/evenements/e1/inscription/r1/recap', (string) $sent->getTextBody());
    }
}
