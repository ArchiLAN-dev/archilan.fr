<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Shared\Presentation\Support\PatchDownloadUrl;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Téléchargement public d'un fichier de sortie par lien signé (story 16.16).
 *
 * C'est la seule route de l'API qui sert un fichier sans appelant : elle est faite pour qu'un
 * joueur puisse envoyer le lien de son patch à qui doit jouer ce slot, sans compte ArchiLAN. D'où
 * les cas ci-dessous : le lien signé marche sans session, tout le reste est refusé.
 */
final class PublicPatchDownloadTest extends FunctionalTestCase
{
    private const string SESSION_ID = 'sess-public-patch';
    private const string FILENAME = 'AP_123_P2_masterkafey_LM.aplm';

    protected function tearDown(): void
    {
        $dir = $this->outputDir();
        foreach (glob($dir.'/*') ?: [] as $path) {
            unlink($path);
        }
        if (is_dir($dir)) {
            rmdir($dir);
            rmdir(\dirname($dir));
        }

        parent::tearDown();
    }

    public function testASignedLinkServesTheFileWithoutAnyLogin(): void
    {
        $this->writeOutputFile(self::FILENAME, 'contenu-du-patch');

        $this->client->request('GET', $this->signedWorkspaceUrl(self::FILENAME));

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        // BinaryFileResponse ne met pas le corps en mémoire : le fichier servi est lu sur disque.
        self::assertSame('contenu-du-patch', file_get_contents($response->getFile()->getPathname()));
        self::assertStringContainsString(self::FILENAME, (string) $response->headers->get('Content-Disposition'));
    }

    public function testAnUnsignedLinkIsRefused(): void
    {
        $this->writeOutputFile(self::FILENAME, 'contenu-du-patch');

        $this->client->request(
            'GET',
            PatchDownloadUrl::PATH.rawurlencode(self::FILENAME).'?workspace='.self::SESSION_ID,
        );

        self::assertResponseStatusCodeSame(403);
    }

    /** Refusé avant même de regarder si le fichier existe : rien n'est énumérable. */
    public function testAnUnsignedLinkIsRefusedEvenForAFileThatDoesNotExist(): void
    {
        $this->client->request('GET', PatchDownloadUrl::PATH.'aucun-fichier.aplm?workspace='.self::SESSION_ID);

        self::assertResponseStatusCodeSame(403);
    }

    public function testALinkSignedForAnotherFileDoesNotServeThisOne(): void
    {
        $this->writeOutputFile(self::FILENAME, 'contenu-du-patch');
        $this->writeOutputFile('AP_123_P3_someone_else.apz2', 'patch-du-voisin');

        $signed = $this->signedWorkspaceUrl(self::FILENAME);
        $tampered = str_replace(rawurlencode(self::FILENAME), rawurlencode('AP_123_P3_someone_else.apz2'), $signed);

        $this->client->request('GET', $tampered);

        self::assertResponseStatusCodeSame(403);
    }

    /** Deuxième verrou : ni la multidata ni le spoiler ne sortent, même avec une signature valide. */
    public function testTheSeedAndTheSpoilerAreRefusedEvenWhenSigned(): void
    {
        $this->writeOutputFile('AP_123.archipelago', 'graine');
        $this->writeOutputFile('AP_123_Spoiler.txt', 'tout le multiworld');

        $this->client->request('GET', $this->signedWorkspaceUrl('AP_123.archipelago'));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', $this->signedWorkspaceUrl('AP_123_Spoiler.txt'));
        self::assertResponseStatusCodeSame(403);
    }

    public function testAMissingFileIsNotFound(): void
    {
        $this->writeOutputFile(self::FILENAME, 'contenu-du-patch');

        $this->client->request('GET', $this->signedWorkspaceUrl('AP_123_P9_absent.aplm'));

        self::assertResponseStatusCodeSame(404);
    }

    private function signedWorkspaceUrl(string $filename): string
    {
        $urls = self::getContainer()->get(PatchDownloadUrl::class);
        self::assertInstanceOf(PatchDownloadUrl::class, $urls);

        return $urls->forWorkspace(self::SESSION_ID, $filename);
    }

    private function outputDir(): string
    {
        $workspaceDir = $_ENV['WORKSPACE_DIR'] ?? '';
        self::assertIsString($workspaceDir);

        return $workspaceDir.'/'.self::SESSION_ID.'/output';
    }

    private function writeOutputFile(string $filename, string $contents): void
    {
        $dir = $this->outputDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        file_put_contents($dir.'/'.$filename, $contents);
    }
}
