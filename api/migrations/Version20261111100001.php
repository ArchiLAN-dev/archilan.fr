<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Story 53.5: the help guides, written in the admin, and the six first ones (published, to be read again by the orga). */
final class Version20261111100001 extends AbstractMigration
{
    private const array GUIDES = [
        [
            'id' => '53050000000000000000000000000001',
            'slug' => 'premiere-partie',
            'title' => 'Ta première partie',
            'summary' => "De l'inscription au goal : choisir tes jeux, régler leurs options, te connecter le jour J et finir ta partie.",
            'body' => <<<'MD'
                Une ArchiLAN, c'est une grande partie Archipelago : les objets de ton jeu sont cachés dans les jeux des autres, et les leurs dans le tien. Tout le monde avance ensemble.

                ## 1. Choisis tes jeux

                Depuis ton inscription, choisis le ou les jeux que tu joueras parmi ceux proposés par l'orga. Prends un jeu que tu connais un peu : tu vas le parcourir dans un ordre inhabituel.

                ## 2. Règle les options

                Chaque jeu a ses options (difficulté, objectifs, ce qui est mélangé). Les réglages par défaut conviennent pour une première partie. Tant que la ligne « Régler les options de mes jeux » n'est pas cochée dans ton espace, l'orga attend tes choix.

                ## 3. Installe Archipelago

                Le guide [Installer Archipelago](/aide/archipelago) explique quoi installer pour chaque jeu. Fais-le avant le jour J : sur place, le wifi est partagé.

                ## 4. Connecte-toi

                Le jour J, l'adresse de connexion apparaît dans ton espace, avec ton nom de joueur. Tu la colles dans ton client Archipelago et c'est parti.

                ## 5. Bloqué ?

                Si tu ne peux plus avancer en attendant un objet d'un autre joueur, tu es « BK » (en panne). C'est normal : va voir les autres, demande un indice, ou fais appel au [Passeur](/aide/passeur).

                ## 6. Le goal, puis le release

                Quand tu atteins l'objectif de ton jeu (le goal), les objets qui restent dans ton monde attendent encore d'être trouvés. Si tu dois partir avant la fin, demande un « release » à un admin : il envoie d'un coup tout ce que ton monde contient encore pour les autres.
                MD,
        ],
        [
            'id' => '53050000000000000000000000000002',
            'slug' => 'pelles',
            'title' => 'Les pelles',
            'summary' => "La monnaie d'ArchiLAN : comment en gagner, à quoi elles servent, et la différence entre l'or, l'argent et le bronze.",
            'body' => <<<'MD'
                Les pelles sont la monnaie d'ArchiLAN. Il en existe trois sortes.

                ## Les pelles d'or

                Elles sont à toi et ne disparaissent pas. Tu les gagnes avec les quêtes de la semaine et le coffre hebdomadaire. Elles servent à la boutique : cadres d'avatar, bannières de profil et articles ArchiLAN.

                ## Les pelles d'argent (pelles d'un événement)

                Elles ne valent que pendant un événement. Tu en reçois en arrivant, et tu en gagnes en jouant : blind test, Minimapperz, primes sur les objets. Elles servent à acheter des indices, à poser des primes et à faire appel au [Passeur](/aide/passeur).

                À la fin de l'événement, il en reste toujours un peu : 10 % de ce qui te reste est converti en pelles d'or.

                ## Les pelles de bronze

                Elles n'existent que dans une partie privée et disparaissent avec elle. Le propriétaire de la partie choisit combien on en gagne.

                ## Où les voir

                Ton solde est affiché dans ton espace pour l'événement et sur la page « Mes pelles » de ton compte, avec l'historique de chaque gain et de chaque dépense.
                MD,
        ],
        [
            'id' => '53050000000000000000000000000003',
            'slug' => 'passeur',
            'title' => 'Le Passeur',
            'summary' => "Bloqué depuis un moment ? Le Passeur ouvre pour toi un coffre dans le monde d'un autre joueur, contre des pelles d'argent.",
            'body' => <<<'MD'
                Quand tu es bloqué depuis un moment (« BK ») parce qu'il te manque un objet, le Passeur peut aller le chercher pour toi.

                ## Comment ça marche

                Le Passeur ouvre un coffre précis dans le monde d'un autre joueur, celui qui contient un objet qui te débloque. L'objet t'arrive comme si l'autre joueur l'avait trouvé.

                - Il apparaît sur la page de ta partie après un temps minimum bloqué (30 minutes par défaut).
                - Il se paie en **pelles d'argent** seulement, jamais en or.
                - Son prix baisse avec le temps passé bloqué, jusqu'à un plancher.
                - Le joueur dont le coffre est ouvert reçoit une part du prix, et un message lui dit ce que le Passeur a ouvert dans son monde.
                - Un même monde ne peut pas être ouvert sans limite dans la journée.

                ## Pourquoi pas un simple indice ?

                Un indice te dit où est l'objet, mais quelqu'un doit encore aller le chercher. Le Passeur sert quand ce joueur est loin dans son jeu, sur autre chose, ou pas encore assez avancé.
                MD,
        ],
        [
            'id' => '53050000000000000000000000000004',
            'slug' => 'blind-test',
            'title' => 'Le blind test',
            'summary' => "Reconnaître des musiques de jeux vidéo pendant les pauses, pour le classement et des pelles de l'événement.",
            'body' => <<<'MD'
                Pendant l'événement, l'orga ouvre un blind test de musiques de jeux vidéo.

                ## Comment jouer

                Quand il est ouvert, le bouton « Jouer au blind test » apparaît dans ton espace pour l'événement. Tu peux aussi l'ouvrir sur ton téléphone avec le QR code affiché à côté. L'application te demande de te connecter avec ton compte archilan.fr : c'est comme ça qu'elle sait qui joue.

                ## Ce que tu gagnes

                Chaque musique trouvée te rapporte des pelles d'argent de l'événement, dans la limite fixée par l'orga, et te fait monter au classement.
                MD,
        ],
        [
            'id' => '53050000000000000000000000000005',
            'slug' => 'minimapperz',
            'title' => 'Minimapperz',
            'summary' => "Le GeoGuessr des jeux vidéo, partenaire d'ArchiLAN : ton code, comment jouer et ce que tu y gagnes.",
            'body' => <<<'MD'
                Minimapperz est un jeu de devinettes en images à 360° tirées de jeux vidéo : tu dois retrouver d'où vient la vue. C'est un partenaire d'ArchiLAN.

                ## Ton code

                Pour les événements où Minimapperz est de la partie, chaque inscrit reçoit un code personnel, affiché dans son espace pour l'événement. Le bouton « Rejoindre la LAN » t'emmène sur Minimapperz : saisis ton code en rejoignant la LAN, il relie tes parties à ton compte ArchiLAN. Il est à toi seul, ne le partage pas.

                ## Ce que tu gagnes

                Les parties jouées pendant l'événement avec ton code te rapportent des pelles d'argent de l'événement. Le classement de l'événement passe aussi sur l'écran de la régie.
                MD,
        ],
        [
            'id' => '53050000000000000000000000000006',
            'slug' => 'diffusion',
            'title' => 'Ton stream et la régie',
            'summary' => 'Si tu streames ta partie, la régie ArchiLAN peut montrer ton live : comment ça marche et comment refuser.',
            'body' => <<<'MD'
                Pendant les pauses d'un événement, la régie ArchiLAN montre les lives des joueurs qui streament leur partie.

                ## Qui est montré

                Seuls les joueurs dont la chaîne Twitch figure dans les liens de leur profil, et qui sont en direct, apparaissent. L'accord est donné par défaut sur ton inscription.

                ## Refuser

                Dans ton espace pour l'événement, carte « Ton stream sur la régie », décoche la case : ton live ne sera plus repris par la régie, sans autre conséquence. Tu peux changer d'avis jusqu'à la fin de l'événement.

                ## Voir les autres

                La page Streams du site montre aussi les lives des joueurs en mosaïque.
                MD,
        ],
    ];

    #[\Override]
    public function getDescription(): string
    {
        return 'Story 53.5: help guides, and the first six';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE help_guide (id VARCHAR(32) NOT NULL, slug VARCHAR(60) NOT NULL, title VARCHAR(80) NOT NULL, summary VARCHAR(200) NOT NULL, body TEXT NOT NULL, position INT NOT NULL, published BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_help_guide_slug ON help_guide (slug)');
        $this->addSql('CREATE INDEX idx_help_guide_position ON help_guide (position)');

        foreach (self::GUIDES as $position => $guide) {
            $this->addSql(
                'INSERT INTO help_guide (id, slug, title, summary, body, position, published, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, true, NOW(), NOW())',
                [$guide['id'], $guide['slug'], $guide['title'], $guide['summary'], $guide['body'], $position],
            );
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE help_guide');
    }
}
