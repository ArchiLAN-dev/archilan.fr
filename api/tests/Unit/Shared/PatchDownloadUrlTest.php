<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Presentation\Support\PatchDownloadUrl;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;

/**
 * Le lien public d'un fichier de sortie (story 16.16). Il porte son autorisation dans sa signature,
 * puisqu'il est fait pour être envoyé à quelqu'un qui n'a pas de compte : ce qu'il ouvre doit donc
 * être exactement le fichier qu'il nomme, et rien d'autre.
 */
final class PatchDownloadUrlTest extends TestCase
{
    private const string ARCHIVE = 'sess-1/output/archive.zip';
    private const string FILENAME = 'AP_123_P2_masterkafey_LM.aplm';

    public function testASignedLinkIsAccepted(): void
    {
        $urls = $this->urls();

        self::assertTrue($urls->isValid($this->requestFor($urls->forArchive(self::ARCHIVE, self::FILENAME))));
    }

    public function testTheSameHoldsForAWorkspaceFile(): void
    {
        $urls = $this->urls();

        self::assertTrue($urls->isValid($this->requestFor($urls->forWorkspace('sess-1', self::FILENAME))));
    }

    public function testChangingTheFilenameInvalidatesTheLink(): void
    {
        $urls = $this->urls();
        $signed = $urls->forArchive(self::ARCHIVE, self::FILENAME);

        $tampered = str_replace(rawurlencode(self::FILENAME), rawurlencode('AP_123_P3_someone_else.aplm'), $signed);

        self::assertNotSame($signed, $tampered);
        self::assertFalse($urls->isValid($this->requestFor($tampered)));
    }

    public function testChangingTheLocationInvalidatesTheLink(): void
    {
        $urls = $this->urls();
        $signed = $urls->forArchive(self::ARCHIVE, self::FILENAME);

        $tampered = str_replace(rawurlencode(self::ARCHIVE), rawurlencode('sess-2/output/archive.zip'), $signed);

        self::assertNotSame($signed, $tampered);
        self::assertFalse($urls->isValid($this->requestFor($tampered)));
    }

    public function testAnUnsignedLinkIsRefused(): void
    {
        $urls = $this->urls();

        self::assertFalse($urls->isValid($this->requestFor(
            PatchDownloadUrl::PATH.rawurlencode(self::FILENAME).'?archive='.rawurlencode(self::ARCHIVE),
        )));
    }

    /** Un lien émis avec un autre secret ne vaut rien ici : la signature n'est pas devinable. */
    public function testALinkSignedWithAnotherSecretIsRefused(): void
    {
        $foreign = new PatchDownloadUrl(new UriSigner('un-autre-secret'));

        self::assertFalse($this->urls()->isValid($this->requestFor($foreign->forArchive(self::ARCHIVE, self::FILENAME))));
    }

    /** Un nom de slot peut contenir une apostrophe : l'encodage doit rester stable des deux côtés. */
    public function testASlotNameWithAnApostropheSurvivesTheRoundTrip(): void
    {
        $urls = $this->urls();
        $filename = "AP_123_P3_LuDoSniper_'SW.apz2";

        self::assertTrue($urls->isValid($this->requestFor($urls->forArchive(self::ARCHIVE, $filename))));
    }

    private function urls(): PatchDownloadUrl
    {
        return new PatchDownloadUrl(new UriSigner('secret-de-test'));
    }

    private function requestFor(string $requestUri): Request
    {
        return Request::create($requestUri);
    }
}
