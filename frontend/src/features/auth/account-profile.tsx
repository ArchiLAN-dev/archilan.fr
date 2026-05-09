"use client";

import Link from "next/link";
import { useEffect, useId, useState } from "react";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

type Profile = {
  id: string;
  email: string;
  displayName: string | null;
  roles: string[];
  createdAt: string;
  updatedAt: string;
};

type ProfileResponse =
  | {
      data: Profile;
      meta: {
        message?: string;
      };
    }
  | {
      error: {
        code: string;
        message: string;
        details: Partial<Record<"displayName" | "body", string[]>>;
      };
    };

type PrivacyRightType = "access" | "rectification" | "erasure" | "portability" | "opposition";

type PrivacyRequestResponse =
  | {
      data: {
        id: string;
        rightType: PrivacyRightType;
        status: "received";
        handlingMode: "manual_review";
        submittedAt: string;
      };
      meta: {
        message: string;
        contactFollowUp: string;
      };
    }
  | {
      error: {
        code: string;
        message: string;
        details: Partial<Record<"rightType" | "details", string[]>>;
      };
    };

const privacyRights: Array<{ type: PrivacyRightType; label: string; description: string }> = [
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
    description: "Utiliser la suppression de compte ci-dessous ou demander un traitement complémentaire.",
  },
  {
    type: "portability",
    label: "Portabilité",
    description: "Demander un traitement manuel. Aucun export automatisé n'est disponible à ce stade.",
  },
  {
    type: "opposition",
    label: "Opposition",
    description: "Demander l'arrêt d'un traitement quand le cadre RGPD le permet.",
  },
];

export function AccountProfile() {
  const displayNameId = useId();
  const privacyDetailsId = useId();
  const [profile, setProfile] = useState<Profile | null>(null);
  const [displayNameError, setDisplayNameError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [messageIsError, setMessageIsError] = useState(false);
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [privacyRightType, setPrivacyRightType] = useState<PrivacyRightType>("access");
  const [privacyDetails, setPrivacyDetails] = useState("");
  const [privacyErrors, setPrivacyErrors] = useState<Partial<Record<"rightType" | "details", string>>>({});
  const [privacySubmitting, setPrivacySubmitting] = useState(false);
  const [privacyMessage, setPrivacyMessage] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    async function loadProfile() {
      try {
        const response = await apiFetch(`${env.apiBaseUrl}/account/profile`);
        const payload = (await response.json()) as ProfileResponse;

        if (cancelled) {
          return;
        }

        if ("error" in payload) {
          setMessage(payload.error.message);
          return;
        }

        setProfile(payload.data);
      } catch {
        if (!cancelled) {
          setMessage("Impossible de charger le profil pour le moment.");
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    }

    void loadProfile();

    return () => {
      cancelled = true;
    };
  }, []);

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSubmitting(true);
    setDisplayNameError(null);
    setMessage(null);
    setMessageIsError(false);

    const formData = new FormData(event.currentTarget);

    try {
      const response = await apiFetch(`${env.apiBaseUrl}/account/profile`, {
        method: "PATCH",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          displayName: formData.get("displayName"),
        }),
      });
      const payload = (await response.json()) as ProfileResponse;

      if ("error" in payload) {
        setDisplayNameError(payload.error.details.displayName?.[0] ?? null);
        setMessageIsError(true);
        setMessage(payload.error.message);
        return;
      }

      setProfile(payload.data);
      setMessage(payload.meta.message ?? "Profil mis à jour.");
    } catch {
      setMessageIsError(true);
      setMessage("Impossible de mettre à jour le profil pour le moment.");
    } finally {
      setSubmitting(false);
    }
  }

  async function handlePrivacyRequest(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPrivacySubmitting(true);
    setPrivacyErrors({});
    setPrivacyMessage(null);

    try {
      const response = await apiFetch(`${env.apiBaseUrl}/account/privacy-requests`, {
        body: JSON.stringify({
          rightType: privacyRightType,
          details: privacyDetails,
        }),
        headers: {
          "Content-Type": "application/json",
        },
        method: "POST",
      });
      const payload = (await response.json()) as PrivacyRequestResponse;

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

  async function handleDeleteAccount() {
    setDeleting(true);
    setMessage(null);

    try {
      const response = await apiFetch(`${env.apiBaseUrl}/account`, {
        method: "DELETE",
      });

      if (!response.ok) {
        setMessageIsError(true);
        setMessage("Impossible de supprimer le compte pour le moment.");
        return;
      }

      setProfile(null);
      setDeleteDialogOpen(false);
      setMessage("Compte supprimé. Tu es déconnecté.");
    } catch {
      setMessageIsError(true);
      setMessage("Impossible de supprimer le compte pour le moment.");
    } finally {
      setDeleting(false);
    }
  }

  if (loading) {
    return <p className="text-muted-foreground">Chargement du profil...</p>;
  }

  if (!profile) {
    return (
      <div className="card-glow rounded-lg border border-border p-6">
        <p className="text-muted-foreground">{message ?? "Connecte-toi pour accéder à ton compte."}</p>
      </div>
    );
  }

  return (
    <div className="grid gap-6">
      {message ? (
        <p className="rounded border border-border bg-background p-3 text-sm text-muted-foreground" role={messageIsError ? "alert" : "status"}>
          {message}
        </p>
      ) : null}

      <section className="grid gap-4 card-glow rounded-lg border border-border p-6">
        <h2 className="font-heading text-2xl font-semibold text-foreground">Informations du compte</h2>
        <dl className="grid gap-3 text-sm sm:grid-cols-[10rem_1fr]">
          <dt className="text-muted-foreground">Email</dt>
          <dd className="font-semibold text-foreground">{profile.email}</dd>
          <dt className="text-muted-foreground">Rôle</dt>
          <dd className="font-semibold text-foreground">{formatRoles(profile.roles)}</dd>
          <dt className="text-muted-foreground">Créé le</dt>
          <dd className="font-semibold text-foreground">{formatDate(profile.createdAt)}</dd>
          <dt className="text-muted-foreground">Mis à jour le</dt>
          <dd className="font-semibold text-foreground">{formatDate(profile.updatedAt)}</dd>
        </dl>
      </section>

      <form className="grid gap-5 card-glow rounded-lg border border-border p-6" key={profile.updatedAt} onSubmit={handleSubmit}>
        <div>
          <h2 className="font-heading text-2xl font-semibold text-foreground">Profil public</h2>
          <p className="mt-2 text-sm text-muted-foreground">
            Le rôle et l&apos;email ne sont pas modifiables depuis ce formulaire.
          </p>
        </div>
        <div className="grid gap-2">
          <label className="text-sm font-semibold text-foreground" htmlFor={displayNameId}>
            Nom affiché
          </label>
          <input
            aria-describedby={displayNameError ? `${displayNameId}-error ${displayNameId}-hint` : `${displayNameId}-hint`}
            aria-invalid={displayNameError ? true : undefined}
            className="min-h-12 rounded border border-border bg-background px-3 text-foreground outline-none transition-colors focus:border-accent focus:ring-2 focus:ring-accent/40"
            defaultValue={profile.displayName ?? ""}
            id={displayNameId}
            maxLength={80}
            name="displayName"
            type="text"
          />
          <p className="text-sm text-muted-foreground" id={`${displayNameId}-hint`}>
            80 caractères maximum.
          </p>
          {displayNameError ? (
            <p className="text-sm text-danger" id={`${displayNameId}-error`}>
              {displayNameError}
            </p>
          ) : null}
        </div>
        <button
          className="inline-flex min-h-12 items-center justify-center rounded bg-accent px-5 font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60"
          disabled={submitting}
          type="submit"
        >
          {submitting ? "Enregistrement..." : "Enregistrer"}
        </button>
      </form>

      <section className="grid gap-5 card-glow rounded-lg border border-border p-6">
        <div>
          <h2 className="font-heading text-2xl font-semibold text-foreground">Données et confidentialité</h2>
          <p className="mt-2 text-sm leading-6 text-muted-foreground">
            Tu peux exercer tes droits RGPD depuis ce compte. Les demandes sont enregistrées puis traitées manuellement par une personne habilitée.
          </p>
          <Link className="mt-3 inline-flex text-sm font-semibold text-accent-text hover:text-accent-text-hover" href="/confidentialite">
            Lire la politique de confidentialité
          </Link>
        </div>

        <ul className="grid gap-3 sm:grid-cols-2">
          {privacyRights.map((right) => (
            <li className="rounded border border-border bg-background p-3" key={right.type}>
              <h3 className="font-semibold text-foreground">{right.label}</h3>
              <p className="mt-1 text-sm leading-6 text-muted-foreground">{right.description}</p>
            </li>
          ))}
        </ul>

        <form className="grid gap-4 border-t border-border pt-5" onSubmit={handlePrivacyRequest}>
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
              {privacyRights.map((right) => (
                <option key={right.type} value={right.type}>
                  {right.label}
                </option>
              ))}
            </select>
            {privacyErrors.rightType ? <p className="text-sm text-danger">{privacyErrors.rightType}</p> : null}
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
              1000 caractères maximum. La portabilité est traitée par revue manuelle, aucun export automatique n&apos;est promis.
            </p>
            {privacyErrors.details ? <p className="text-sm text-danger">{privacyErrors.details}</p> : null}
          </div>

          <button
            className="inline-flex min-h-12 items-center justify-center rounded border border-border bg-surface px-5 font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-60"
            disabled={privacySubmitting}
            type="submit"
          >
            {privacySubmitting ? "Transmission..." : "Transmettre la demande RGPD"}
          </button>

          {privacyMessage ? (
            <p className="rounded border border-border bg-background p-3 text-sm text-muted-foreground" role="status">
              {privacyMessage}
            </p>
          ) : null}
        </form>
      </section>

      <section className="grid gap-4 rounded-lg border border-danger/60 bg-surface/40 backdrop-blur-md p-6">
        <div>
          <h2 className="font-heading text-2xl font-semibold text-foreground">Suppression du compte</h2>
          <p className="mt-2 text-sm leading-6 text-muted-foreground">
            Cette action anonymise tes données personnelles de compte et te déconnecte immédiatement.
          </p>
        </div>
        <button
          className="inline-flex min-h-12 items-center justify-center rounded bg-danger px-5 font-semibold text-white transition-colors hover:opacity-90"
          type="button"
          onClick={() => setDeleteDialogOpen(true)}
        >
          Supprimer mon compte
        </button>
      </section>

      {deleteDialogOpen ? (
        <div
          aria-labelledby="delete-account-title"
          aria-modal="true"
          className="fixed inset-0 z-50 grid place-items-center bg-background/80 px-6"
          role="alertdialog"
        >
          <div className="grid max-w-md gap-5 rounded-lg border border-danger/60 bg-surface/40 backdrop-blur-md p-6 shadow-lg">
            <div>
              <h2 className="font-heading text-2xl font-semibold text-foreground" id="delete-account-title">
                Confirmer la suppression
              </h2>
              <p className="mt-3 text-sm leading-6 text-muted-foreground">
                Ton compte sera anonymisé et la session en cours sera fermée. Cette action ne peut pas être annulée.
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

function formatRoles(roles: string[]) {
  if (roles.includes("ROLE_ADMIN")) {
    return "Admin";
  }

  if (roles.includes("ROLE_MEMBER")) {
    return "Membre";
  }

  return "Lambda";
}

function formatDate(value: string) {
  return new Intl.DateTimeFormat("fr-FR", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(value));
}
