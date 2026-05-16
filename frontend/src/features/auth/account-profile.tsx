"use client";

import Link from "next/link";
import { useId, useState } from "react";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

// ── Shared types ──────────────────────────────────────────────────────────────

export type Profile = {
  id: string;
  email: string;
  displayName: string | null;
  roles: string[];
  emailVerifiedAt: string | null;
  createdAt: string;
  updatedAt: string;
};

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

