"use client";

import { SecretField } from "@/components/secret-field";

/**
 * Les champs de connexion d'une run, dans la seule forme qui marche partout : il n'y en a pas une.
 *
 * Mesuré le 2026-08-14 sur des clients web tiers réels (voir `docs/archipelago-web-clients.md`) :
 * l'adresse jointe `archilan.fr:35000` connecte les clients à champ unique, et **casse** ceux qui
 * ont un champ hôte et un champ port séparés. D'où les trois formes, chacune étiquetée par son
 * usage plutôt que par sa nature - un joueur doit pouvoir choisir sans lire de documentation.
 *
 * Toutes les valeurs sont masquées par défaut : un mot de passe visible à l'écran d'un streamer est
 * un mot de passe public (stories 17.21 / 17.22).
 */
export function ConnectionFields({
  host,
  port,
  uri,
  password,
  adminPassword,
}: {
  host: string;
  port: number;
  /** Adresse chiffrée complète fournie par l'API. Jamais reconstruite ici (story 37.4). */
  uri: string | null;
  /** Null quand la run a été lancée sans mot de passe (story 16.13) - la ligne est alors omise. */
  password: string | null;
  adminPassword?: string | null;
}) {
  const joined = `${host}:${port}`;

  return (
    <div className="flex flex-col gap-3">
      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
        {uri != null && <SecretField label="Adresse - client Archipelago" value={uri} />}
        <SecretField label="Adresse - client web" value={joined} />
        <SecretField label="Hôte" value={host} />
        <SecretField label="Port" value={String(port)} />
        {password != null && <SecretField label="Mot de passe" value={password} />}
        {adminPassword != null && <SecretField label="Mot de passe admin" value={adminPassword} />}
      </div>

      {password == null && (
        <p className="text-xs text-muted-foreground">
          Pas de mot de passe : le lien d&apos;invitation suffit pour rejoindre.
        </p>
      )}

      <p className="text-xs text-muted-foreground">
        <strong className="font-semibold text-foreground">Le port fait partie de l&apos;adresse.</strong>{" "}
        Sans lui, un client cherche le port 38281 et échoue aussitôt - certains conseillent alors de
        passer sur une version non chiffrée, ce qui ne marchera pas davantage. Si ton client a deux
        champs séparés, utilise l&apos;hôte et le port ci-dessus.
      </p>

      <p className="text-xs text-muted-foreground">
        Un client web tiers reçoit l&apos;adresse, ton nom de slot et le mot de passe de la partie.
        Ces clients ne sont pas hébergés par ArchiLAN.
      </p>
    </div>
  );
}
