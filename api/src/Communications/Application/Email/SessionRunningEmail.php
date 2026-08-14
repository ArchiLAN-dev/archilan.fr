<?php

declare(strict_types=1);

namespace App\Communications\Application\Email;

use App\Shared\Application\Support\ArchipelagoConnectionUri;

final class SessionRunningEmail extends ArchilanEmail
{
    /**
     * @param list<string> $slotNames
     */
    public function __construct(
        private readonly string $recipientEmail,
        private readonly ?string $recipientDisplayName,
        private readonly string $eventTitle,
        private readonly string $host,
        private readonly int $port,
        private readonly string $password,
        private readonly array $slotNames,
    ) {
    }

    public function to(): string
    {
        return $this->recipientEmail;
    }

    public function toName(): ?string
    {
        return $this->recipientDisplayName;
    }

    public function subject(): string
    {
        return "Votre session Archipelago est prête - {$this->eventTitle}";
    }

    public function textBody(): string
    {
        $name = $this->recipientDisplayName ?? $this->recipientEmail;
        $uri = ArchipelagoConnectionUri::build($this->host, $this->port);
        $slotList = [] === $this->slotNames
            ? '  (aucun slot assigné)'
            : implode("\n", array_map(static fn (string $s): string => "  - {$s}", $this->slotNames));

        return <<<TEXT
        Bonjour {$name},

        Votre session Archipelago pour l'événement "{$this->eventTitle}" est maintenant en cours !

        🎮 Vos slots :
        {$slotList}

        📡 Informations de connexion :
          Adresse      : {$uri}
          Hôte         : {$this->host}
          Port         : {$this->port}
          Mot de passe : {$this->password}

        Comment se connecter :
        1. Lancez votre client Archipelago.
        2. Allez dans « Connect » et saisissez l'adresse ci-dessus, votre nom de slot et le
           mot de passe.
        3. Rejoignez la room et bonne session !

        Depuis un navigateur, sans rien installer :
        Certains clients web n'ont qu'un champ d'adresse : collez-y « {$this->host}:{$this->port} ».
        D'autres ont un champ hôte et un champ port séparés : utilisez les deux lignes ci-dessus.
        Le port fait partie de l'adresse - sans lui, la connexion échoue aussitôt.
        Ces clients ne sont pas hébergés par ArchiLAN : ils reçoivent l'adresse, votre nom de
        slot et le mot de passe de la partie.

        À tout de suite,
        L'équipe ArchiLAN
        TEXT;
    }
}
