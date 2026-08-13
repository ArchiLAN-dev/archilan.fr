<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Application\Support\TraefikConfigBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Le nom d'un entrypoint est un contrat entre deux couches qui ne se parlent pas.
 *
 * `scripts/gen-traefik-entrypoints.sh` déclare les entrypoints dans le reverse proxy ;
 * `TraefikConfigBuilder` produit les routeurs qui les référencent. Si les deux conventions
 * divergent, Traefik ignore le routeur avec une simple ligne de log, et la run devient
 * injoignable **en paraissant saine** : la poignée TLS réussit et le proxy répond lui-même un
 * HTTP 404. Vérifié en local le 2026-08-13.
 *
 * J'ai écrit deux fois dans les stories de l'epic 37 qu'aucun test ne pouvait tenir ce contrat,
 * « parce que les deux vivent dans des couches différentes ». C'était faux : ils vivent dans le
 * **même dépôt**. Ce test existe pour que l'affirmation cesse d'être vraie, sur le modèle de
 * `StandardsDocsMatchToolingTest` - un gate, pas une bonne intention.
 */
final class TraefikEntrypointContractTest extends TestCase
{
    private const string GENERATOR = __DIR__.'/../../../../scripts/gen-traefik-entrypoints.sh';

    public function testTheGeneratorDeclaresTheEntrypointPrefixTheBuilderReferences(): void
    {
        $prefix = $this->builderPrefix();

        self::assertStringContainsString(
            sprintf('--entrypoints.%s', $prefix),
            $this->generatorSource(),
            sprintf(
                'Le générateur doit déclarer des entrypoints « %s{port} », préfixe utilisé par '
                .'TraefikConfigBuilder. Une divergence rend les runs injoignables sans erreur visible.',
                $prefix,
            ),
        );
    }

    public function testTheBuilderNamesEntrypointsFromThePortAlone(): void
    {
        $prefix = $this->builderPrefix();

        // La forme exacte que le générateur produit pour un port donné, et celle que le builder
        // doit référencer : le port suffit à identifier la run, sans autre discriminant.
        self::assertStringContainsString(
            sprintf('--entrypoints.%s${port}.address=:${port}', $prefix),
            $this->normalisedGeneratorSource(),
            'Le générateur doit dériver le nom de l\'entrypoint du seul port.',
        );
    }

    public function testTheGeneratorIsExecutableDocumentationOfTheContract(): void
    {
        // Le contrat n'est tenu par aucun mécanisme au déploiement : il doit au moins être écrit
        // là où quelqu'un le lira avant de changer la convention.
        $source = $this->generatorSource();

        self::assertStringContainsString('CONTRAT AVEC L\'API', $source);
        self::assertStringContainsString('TraefikConfigBuilder', $source);
    }

    private function builderPrefix(): string
    {
        $reflection = new \ReflectionClass(TraefikConfigBuilder::class);
        $prefix = $reflection->getConstant('ENTRYPOINT_PREFIX');

        self::assertIsString($prefix, 'TraefikConfigBuilder::ENTRYPOINT_PREFIX doit exister.');
        self::assertNotSame('', $prefix);

        return $prefix;
    }

    private function generatorSource(): string
    {
        $path = realpath(self::GENERATOR);
        self::assertIsString($path, 'Le générateur d\'entrypoints est introuvable : '.self::GENERATOR);

        $source = file_get_contents($path);
        self::assertIsString($source);

        return $source;
    }

    /**
     * Ramène les substitutions shell (`%s` de printf alimenté par $port) à une forme lisible,
     * pour comparer une intention plutôt qu'une syntaxe.
     */
    private function normalisedGeneratorSource(): string
    {
        $source = $this->generatorSource();

        return str_replace(
            ['--entrypoints.ap-%s.address=:%s'],
            ['--entrypoints.ap-${port}.address=:${port}'],
            $source,
        );
    }
}
