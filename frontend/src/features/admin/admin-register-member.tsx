"use client";

import { UserPlus, X } from "lucide-react";
import { useRouter } from "next/navigation";
import { useId, useState } from "react";

import type { UserSearchResult } from "./admin-membership-api";
import { createAdminRegistration } from "./admin-registration-api";
import { UserCombobox } from "./admin-user-combobox";
import { AdminWarningsDialog, useAcknowledgedAction } from "./admin-warnings";

/** Where the admin lands once the member is registered: the registration, to pick games and confirm. */
export function adminRegistrationPath(eventId: string, registrationId: string): string {
  return `/admin/evenements/${eventId}/inscriptions/${registrationId}`;
}

/**
 * Story 5.10: the admin registers a member on their behalf, from the event's registrations. An existing registration
 * is opened instead of duplicated; a cancelled one comes back.
 */
export function AdminRegisterMember({ eventId }: { eventId: string }) {
  const [open, setOpen] = useState(false);

  return (
    <>
      <button
        className="inline-flex min-h-9 items-center justify-center gap-2 rounded border border-accent px-3 text-sm font-semibold text-foreground hover:bg-accent/10"
        onClick={() => { setOpen(true); }}
        type="button"
      >
        <UserPlus aria-hidden="true" className="size-4" />
        Inscrire un joueur
      </button>
      {open ? <RegisterDialog eventId={eventId} onClose={() => { setOpen(false); }} /> : null}
    </>
  );
}

function RegisterDialog({ eventId, onClose }: { eventId: string; onClose: () => void }) {
  const titleId = useId();
  const router = useRouter();
  const [member, setMember] = useState<UserSearchResult | null>(null);
  const action = useAcknowledgedAction(
    (acknowledgeWarnings) => (member === null
      ? Promise.resolve({ kind: "error" as const, message: "Choisis un joueur." })
      : createAdminRegistration(eventId, member.id, acknowledgeWarnings)),
    (created) => { router.push(adminRegistrationPath(eventId, created.registrationId)); },
  );

  return (
    <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/50 p-4">
      <div
        aria-labelledby={titleId}
        aria-modal="true"
        className="grid w-full max-w-md gap-4 border border-border bg-surface p-6 shadow-xl"
        role="dialog"
      >
        <div className="flex items-center justify-between gap-3">
          <h2 className="font-heading text-xl font-semibold text-foreground" id={titleId}>
            Inscrire un joueur
          </h2>
          <button
            aria-label="Fermer"
            className="inline-flex min-h-9 items-center rounded border border-border px-2 text-muted-foreground hover:text-foreground"
            onClick={onClose}
            type="button"
          >
            <X aria-hidden="true" className="size-4" />
          </button>
        </div>
        <p className="text-sm text-muted-foreground">
          L&apos;inscription est créée à sa place ; tu choisis ensuite ses jeux et tu la confirmes depuis sa fiche.
          Si le joueur est déjà inscrit, sa fiche s&apos;ouvre.
        </p>
        <UserCombobox onSelect={setMember} selected={member} />
        {action.error ? <p className="text-sm text-danger" role="alert">{action.error}</p> : null}
        <div className="flex justify-end gap-3">
          <button
            className="inline-flex min-h-10 items-center justify-center rounded border border-border px-3 text-sm font-semibold text-foreground hover:border-accent"
            onClick={onClose}
            type="button"
          >
            Annuler
          </button>
          <button
            className="inline-flex min-h-10 items-center justify-center rounded border border-accent bg-accent px-3 text-sm font-semibold text-white hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
            disabled={member === null || action.busy}
            onClick={action.run}
            type="button"
          >
            {action.busy ? "Inscription..." : "Inscrire"}
          </button>
        </div>
      </div>
      {action.warnings ? (
        <AdminWarningsDialog
          busy={action.busy}
          confirmLabel="Inscrire quand même"
          onCancel={action.dismiss}
          onConfirm={action.confirm}
          warnings={action.warnings}
        />
      ) : null}
    </div>
  );
}
