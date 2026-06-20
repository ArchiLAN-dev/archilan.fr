"use client";

import Link from "next/link";
import { useId, useState } from "react";
import { FaDiscord, FaSteam } from "react-icons/fa";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { removeSteamAccount, saveSteamAccount } from "./steam-account-api";

// ── Shared types ──────────────────────────────────────────────────────────────

export type Profile = {
  id: string;
  email: string;
  displayName: string;
  discordUsername: string | null;
  steamProfile: string | null;
  roles: string[];
  emailVerifiedAt: string | null;
  createdAt: string;
  updatedAt: string;
};

// ── DiscordSection ────────────────────────────────────────────────────────────

type DiscordSectionProps = {
  discordUsername: string | null;
  linkFeedback?: string;
};

function initMessage(linkFeedback: string | undefined): { text: string; isError: boolean } | null {
  if (!linkFeedback) return null;
  if (linkFeedback === "1") return { text: "Compte Discord lié avec succès.", isError: false };
  if (linkFeedback === "already_used") return { text: "Ce compte Discord est déjà associé à un autre compte ArchiLAN.", isError: true };
  if (linkFeedback === "access_denied") return { text: "Liaison Discord annulée.", isError: true };
  return { text: "Une erreur s'est produite lors de la liaison Discord.", isError: true };
}

export function DiscordSection({ discordUsername, linkFeedback }: DiscordSectionProps) {
  const [unlinking, setUnlinking] = useState(false);
  const [message, setMessage] = useState<{ text: string; isError: boolean } | null>(
    initMessage(linkFeedback),
  );
  const [linked, setLinked] = useState<boolean>(discordUsername !== null);
  const [linkedUsername, setLinkedUsername] = useState<string | null>(discordUsername);

  async function handleUnlink() {
    setUnlinking(true);
    setMessage(null);

    try {
      const response = await apiFetch(`${env.apiBaseUrl}/account/discord`, { method: "DELETE" });

      if (!response.ok) {
        setMessage({ text: "Impossible de délier Discord pour le moment.", isError: true });
        return;
      }

      setLinked(false);
      setLinkedUsername(null);
      setMessage({ text: "Compte Discord délié.", isError: false });
    } catch {
      setMessage({ text: "Impossible de délier Discord pour le moment.", isError: true });
    } finally {
      setUnlinking(false);
    }
  }

  return (
    <section className="card-glow grid gap-4 rounded-lg border border-border p-6">
      <div>
        <h2 className="font-heading text-xl font-semibold text-foreground">Discord</h2>
        <p className="mt-2 text-sm leading-6 text-muted-foreground">
          Lier ton compte Discord te permet de te connecter directement via Discord.
        </p>
      </div>

      {message ? (
        <p className="rounded border border-border bg-background p-3 text-sm text-muted-foreground" role={message.isError ? "alert" : "status"}>
          {message.text}
        </p>
      ) : null}

      {linked ? (
        <div className="flex flex-wrap items-center gap-4">
          <p className="text-sm text-foreground">
            Lié en tant que{" "}
            <span className="font-semibold text-[#5865F2]">{linkedUsername}</span>
          </p>
          <button
            className="inline-flex min-h-9 items-center justify-center rounded border border-border bg-surface px-4 text-sm font-semibold text-foreground transition-colors hover:border-danger hover:text-danger disabled:cursor-not-allowed disabled:opacity-60"
            disabled={unlinking}
            type="button"
            onClick={handleUnlink}
          >
            {unlinking ? "Déliaison..." : "Délier Discord"}
          </button>
        </div>
      ) : (
        <div>
          <a
            className="inline-flex min-h-9 items-center justify-center gap-2 rounded bg-[#5865F2] px-4 text-sm font-semibold text-white transition-colors hover:bg-[#4752C4]"
            href={`${env.apiBaseUrl}/account/discord/link`}
          >
            <FaDiscord aria-hidden="true" size={16} />
            Lier Discord
          </a>
        </div>
      )}
    </section>
  );
}

// ── SteamSection ──────────────────────────────────────────────────────────────

type SteamSectionProps = {
  steamProfile: string | null;
};

export function SteamSection({ steamProfile }: SteamSectionProps) {
  const inputId = useId();
  const [saved, setSaved] = useState<string | null>(steamProfile);
  const [value, setValue] = useState<string>(steamProfile ?? "");
  const [editing, setEditing] = useState<boolean>(steamProfile === null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ text: string; isError: boolean } | null>(null);

  async function handleSave() {
    const trimmed = value.trim();
    if (trimmed === "") {
      setMessage({ text: "Renseigne ton profil Steam.", isError: true });
      return;
    }

    setBusy(true);
    setMessage(null);

    const result = await saveSteamAccount(trimmed);

    if (result.ok) {
      setSaved(trimmed);
      setEditing(false);
      setMessage({ text: "Compte Steam enregistré.", isError: false });
    } else if (result.invalid) {
      setMessage({
        text: "Profil Steam non reconnu - colle l'URL de ton profil, ton pseudo Steam, ou ton SteamID64.",
        isError: true,
      });
    } else {
      setMessage({ text: "Impossible d'enregistrer le compte Steam pour le moment.", isError: true });
    }

    setBusy(false);
  }

  async function handleRemove() {
    setBusy(true);
    setMessage(null);

    const ok = await removeSteamAccount();

    if (ok) {
      setSaved(null);
      setValue("");
      setEditing(true);
      setMessage({ text: "Compte Steam retiré.", isError: false });
    } else {
      setMessage({ text: "Impossible de retirer le compte Steam pour le moment.", isError: true });
    }

    setBusy(false);
  }

  return (
    <section className="card-glow grid gap-4 rounded-lg border border-border p-6">
      <div>
        <h2 className="flex items-center gap-2 font-heading text-xl font-semibold text-foreground">
          <FaSteam aria-hidden="true" size={18} />
          Compte Steam
        </h2>
        <p className="mt-2 text-sm leading-6 text-muted-foreground">
          Enregistre ton compte Steam pour retrouver, sur la page Jeux, les titres de ta
          bibliothèque jouables aux événements ArchiLAN.
        </p>
      </div>

      {message ? (
        <p
          className="rounded border border-border bg-background p-3 text-sm text-muted-foreground"
          role={message.isError ? "alert" : "status"}
        >
          {message.text}
        </p>
      ) : null}

      {!editing && saved !== null ? (
        <div className="flex flex-wrap items-center gap-4">
          <p className="text-sm text-foreground">
            Enregistré : <span className="font-semibold text-accent-text">{saved}</span>
          </p>
          <button
            className="inline-flex min-h-9 items-center justify-center rounded border border-border bg-surface px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-60"
            disabled={busy}
            type="button"
            onClick={() => setEditing(true)}
          >
            Modifier
          </button>
          <button
            className="inline-flex min-h-9 items-center justify-center rounded border border-border bg-surface px-4 text-sm font-semibold text-foreground transition-colors hover:border-danger hover:text-danger disabled:cursor-not-allowed disabled:opacity-60"
            disabled={busy}
            type="button"
            onClick={handleRemove}
          >
            {busy ? "..." : "Retirer"}
          </button>
        </div>
      ) : (
        <div className="grid gap-3">
          <label className="text-sm font-semibold text-foreground" htmlFor={inputId}>
            URL de profil, pseudo Steam, ou SteamID64
          </label>
          <input
            className="min-h-11 rounded border border-border bg-background px-3 text-sm text-foreground outline-none transition-colors focus:border-accent"
            id={inputId}
            placeholder="https://steamcommunity.com/id/ton-pseudo"
            type="text"
            value={value}
            onChange={(event) => setValue(event.target.value)}
          />
          <div className="flex flex-wrap gap-3">
            <button
              className="inline-flex min-h-9 items-center justify-center rounded border border-border bg-surface px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-60"
              disabled={busy}
              type="button"
              onClick={handleSave}
            >
              {busy ? "Enregistrement..." : "Enregistrer"}
            </button>
            {saved !== null ? (
              <button
                className="inline-flex min-h-9 items-center justify-center rounded border border-border bg-surface px-4 text-sm font-semibold text-muted-foreground transition-colors hover:text-foreground disabled:cursor-not-allowed disabled:opacity-60"
                disabled={busy}
                type="button"
                onClick={() => {
                  setValue(saved);
                  setEditing(false);
                  setMessage(null);
                }}
              >
                Annuler
              </button>
            ) : null}
          </div>
        </div>
      )}
    </section>
  );
}

type PrivacyRightType = "access" | "rectification" | "erasure" | "portability" | "opposition";

const PRIVACY_RIGHTS: Array<{ type: PrivacyRightType; label: string; description: string }> = [
  {
    type: "access",
    label: "Accès",
    description: "Demander quelles données de compte ArchiLAN conserve à ton sujet.",
  },
  {
    type: "rectification",
    label: "Rectification",
    description: "Signaler une donnée inexacte ou demander une correction.",
  },
  {
    type: "erasure",
    label: "Effacement",
    description:
      "Utiliser la suppression de compte ci-dessous ou demander un traitement complémentaire.",
  },
  {
    type: "portability",
    label: "Portabilité",
    description:
      "Demander un traitement manuel. Aucun export automatisé n'est disponible à ce stade.",
  },
  {
    type: "opposition",
    label: "Opposition",
    description: "Demander l'arrêt d'un traitement quand le cadre RGPD le permet.",
  },
];

// ── PrivacySection ────────────────────────────────────────────────────────────

export function PrivacySection() {
  const privacyDetailsId = useId();
  const [privacyRightType, setPrivacyRightType] = useState<PrivacyRightType>("access");
  const [privacyDetails, setPrivacyDetails] = useState("");
  const [privacyErrors, setPrivacyErrors] = useState<
    Partial<Record<"rightType" | "details", string>>
  >({});
  const [privacySubmitting, setPrivacySubmitting] = useState(false);
  const [privacyMessage, setPrivacyMessage] = useState<string | null>(null);

  async function handlePrivacyRequest(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPrivacySubmitting(true);
    setPrivacyErrors({});
    setPrivacyMessage(null);

    try {
      const response = await apiFetch(`${env.apiBaseUrl}/account/privacy-requests`, {
        body: JSON.stringify({ rightType: privacyRightType, details: privacyDetails }),
        headers: { "Content-Type": "application/json" },
        method: "POST",
      });
      const payload = (await response.json()) as
        | {
            data: { id: string; rightType: PrivacyRightType; status: string };
            meta: { message: string; contactFollowUp: string };
          }
        | {
            error: {
              code: string;
              message: string;
              details: Partial<Record<"rightType" | "details", string[]>>;
            };
          };

      if ("error" in payload) {
        setPrivacyErrors({
          rightType: payload.error.details.rightType?.[0],
          details: payload.error.details.details?.[0],
        });
        setPrivacyMessage(payload.error.message);
        return;
      }

      setPrivacyDetails("");
      setPrivacyMessage(`${payload.meta.message} ${payload.meta.contactFollowUp}`);
    } catch {
      setPrivacyMessage("Impossible de transmettre la demande RGPD pour le moment.");
    } finally {
      setPrivacySubmitting(false);
    }
  }

  return (
    <div className="grid gap-6">
      <div className="card-glow rounded-lg border border-border p-6">
        <h2 className="font-heading text-xl font-semibold text-foreground">
          Données et confidentialité
        </h2>
        <p className="mt-2 text-sm leading-6 text-muted-foreground">
          Tu peux exercer tes droits RGPD depuis ce formulaire. Les demandes sont enregistrées
          puis traitées manuellement par une personne habilitée.
        </p>
        <Link
          className="mt-3 inline-flex text-sm font-semibold text-accent-text hover:text-accent-text-hover"
          href="/confidentialite"
        >
          Lire la politique de confidentialité →
        </Link>
      </div>

      <ul className="grid gap-3 sm:grid-cols-2">
        {PRIVACY_RIGHTS.map((right) => (
          <li className="rounded border border-border bg-background p-4" key={right.type}>
            <h3 className="font-semibold text-foreground">{right.label}</h3>
            <p className="mt-1 text-sm leading-6 text-muted-foreground">{right.description}</p>
          </li>
        ))}
      </ul>

      <form
        className="card-glow grid gap-5 rounded-lg border border-border p-6"
        onSubmit={handlePrivacyRequest}
      >
        <h2 className="font-heading text-xl font-semibold text-foreground">
          Soumettre une demande
        </h2>

        <div className="grid gap-2">
          <label className="text-sm font-semibold text-foreground" htmlFor="privacy-right-type">
            Droit concerné
          </label>
          <select
            aria-invalid={privacyErrors.rightType ? true : undefined}
            className="min-h-12 rounded border border-border bg-background px-3 text-foreground outline-none transition-colors focus:border-accent"
            id="privacy-right-type"
            onChange={(event) => setPrivacyRightType(event.target.value as PrivacyRightType)}
            value={privacyRightType}
          >
            {PRIVACY_RIGHTS.map((right) => (
              <option key={right.type} value={right.type}>
                {right.label}
              </option>
            ))}
          </select>
          {privacyErrors.rightType ? (
            <p className="text-sm text-danger">{privacyErrors.rightType}</p>
          ) : null}
        </div>

        <div className="grid gap-2">
          <label className="text-sm font-semibold text-foreground" htmlFor={privacyDetailsId}>
            Précisions utiles
          </label>
          <textarea
            aria-describedby={`${privacyDetailsId}-hint`}
            aria-invalid={privacyErrors.details ? true : undefined}
            className="min-h-28 rounded border border-border bg-background px-3 py-2 text-foreground outline-none transition-colors focus:border-accent"
            id={privacyDetailsId}
            maxLength={1000}
            onChange={(event) => setPrivacyDetails(event.target.value)}
            value={privacyDetails}
          />
          <p className="text-sm text-muted-foreground" id={`${privacyDetailsId}-hint`}>
            1000 caractères maximum. La portabilité est traitée par revue manuelle, aucun export
            automatique n&apos;est promis.
          </p>
          {privacyErrors.details ? (
            <p className="text-sm text-danger">{privacyErrors.details}</p>
          ) : null}
        </div>

        <button
          className="inline-flex min-h-12 items-center justify-center rounded border border-border bg-surface px-5 font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-60"
          disabled={privacySubmitting}
          type="submit"
        >
          {privacySubmitting ? "Transmission..." : "Transmettre la demande RGPD"}
        </button>

        {privacyMessage ? (
          <p
            className="rounded border border-border bg-background p-3 text-sm text-muted-foreground"
            role="status"
          >
            {privacyMessage}
          </p>
        ) : null}
      </form>
    </div>
  );
}

// ── DangerSection ─────────────────────────────────────────────────────────────

export function DangerSection() {
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [messageIsError, setMessageIsError] = useState(false);

  async function handleDeleteAccount() {
    setDeleting(true);
    setMessage(null);

    try {
      const response = await apiFetch(`${env.apiBaseUrl}/account`, { method: "DELETE" });

      if (!response.ok) {
        setMessageIsError(true);
        setMessage("Impossible de supprimer le compte pour le moment.");
        return;
      }

      setDeleteDialogOpen(false);
      setMessage("Compte supprimé. Tu es déconnecté.");
    } catch {
      setMessageIsError(true);
      setMessage("Impossible de supprimer le compte pour le moment.");
    } finally {
      setDeleting(false);
    }
  }

  return (
    <div className="grid gap-5">
      {message ? (
        <p
          className="rounded border border-border bg-background p-3 text-sm text-muted-foreground"
          role={messageIsError ? "alert" : "status"}
        >
          {message}
        </p>
      ) : null}

      <section className="grid gap-4 rounded-lg border border-danger/60 bg-surface/40 p-6 backdrop-blur-md">
        <div>
          <h2 className="font-heading text-xl font-semibold text-foreground">
            Suppression du compte
          </h2>
          <p className="mt-2 text-sm leading-6 text-muted-foreground">
            Cette action anonymise tes données personnelles de compte et te déconnecte
            immédiatement. Elle ne peut pas être annulée.
          </p>
        </div>
        <div>
          <button
            className="inline-flex min-h-11 items-center justify-center rounded bg-danger px-5 text-sm font-semibold text-white transition-colors hover:opacity-90"
            type="button"
            onClick={() => setDeleteDialogOpen(true)}
          >
            Supprimer mon compte
          </button>
        </div>
      </section>

      {deleteDialogOpen ? (
        <div
          aria-labelledby="delete-account-title"
          aria-modal="true"
          className="fixed inset-0 z-50 grid place-items-center bg-background/80 px-6"
          role="alertdialog"
        >
          <div className="grid max-w-md gap-5 rounded-lg border border-danger/60 bg-surface/40 p-6 shadow-lg backdrop-blur-md">
            <div>
              <h2
                className="font-heading text-2xl font-semibold text-foreground"
                id="delete-account-title"
              >
                Confirmer la suppression
              </h2>
              <p className="mt-3 text-sm leading-6 text-muted-foreground">
                Ton compte sera anonymisé et la session en cours sera fermée. Cette action ne peut
                pas être annulée.
              </p>
            </div>
            <div className="flex flex-col gap-3 sm:flex-row sm:justify-end">
              <button
                className="inline-flex min-h-11 items-center justify-center rounded border border-border bg-surface px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
                disabled={deleting}
                type="button"
                onClick={() => setDeleteDialogOpen(false)}
              >
                Annuler
              </button>
              <button
                className="inline-flex min-h-11 items-center justify-center rounded bg-danger px-4 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
                disabled={deleting}
                type="button"
                onClick={handleDeleteAccount}
              >
                {deleting ? "Suppression..." : "Confirmer la suppression"}
              </button>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  );
}

