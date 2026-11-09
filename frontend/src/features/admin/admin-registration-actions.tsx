"use client";

import { CheckCircle2, RotateCcw } from "lucide-react";
import { useId, useState } from "react";

import { confirmAdminRegistration, reactivateAdminRegistration } from "./admin-registration-api";
import { AdminWarningsDialog, useAcknowledgedAction } from "./admin-warnings";

type Props = {
  eventId: string;
  registrationId: string;
  status: "reserved" | "cancelled";
  submittedAt: string | null;
  onDone: () => void;
};

/**
 * Story 5.10: what the admin does in the member's place. A cancelled registration (a release at closing included) comes
 * back with its games; an unconfirmed one is confirmed, with or without the member's e-mail. Rules a member would meet
 * are listed first, then crossed on the admin's word.
 */
export function AdminRegistrationActions({ eventId, registrationId, status, submittedAt, onDone }: Props) {
  if (status === "cancelled") {
    return <ReactivateAction eventId={eventId} onDone={onDone} registrationId={registrationId} />;
  }
  if (submittedAt === null) {
    return <ConfirmAction eventId={eventId} onDone={onDone} registrationId={registrationId} />;
  }
  return null;
}

function ReactivateAction({ eventId, registrationId, onDone }: { eventId: string; registrationId: string; onDone: () => void }) {
  const action = useAcknowledgedAction(
    (acknowledgeWarnings) => reactivateAdminRegistration(eventId, registrationId, acknowledgeWarnings),
    () => { onDone(); },
  );

  return (
    <section aria-label="Réactiver l'inscription" className="grid gap-3 border border-border bg-surface p-5">
      <p className="text-sm text-muted-foreground">
        L&apos;inscription est annulée. La réactiver la remet telle qu&apos;elle était, avec ses jeux et leur YAML.
      </p>
      <div>
        <button
          className="inline-flex min-h-10 items-center justify-center gap-2 rounded border border-accent px-3 text-sm font-semibold text-foreground hover:bg-accent/10 disabled:opacity-60"
          disabled={action.busy}
          onClick={action.run}
          type="button"
        >
          <RotateCcw aria-hidden="true" className="size-4" />
          {action.busy ? "Réactivation..." : "Réactiver l'inscription"}
        </button>
      </div>
      {action.error ? <p className="text-sm text-danger" role="alert">{action.error}</p> : null}
      {action.warnings ? (
        <AdminWarningsDialog
          busy={action.busy}
          confirmLabel="Réactiver quand même"
          onCancel={action.dismiss}
          onConfirm={action.confirm}
          warnings={action.warnings}
        />
      ) : null}
    </section>
  );
}

function ConfirmAction({ eventId, registrationId, onDone }: { eventId: string; registrationId: string; onDone: () => void }) {
  const emailId = useId();
  const [sendEmail, setSendEmail] = useState(true);
  const action = useAcknowledgedAction(
    (acknowledgeWarnings) => confirmAdminRegistration(eventId, registrationId, sendEmail, acknowledgeWarnings),
    () => { onDone(); },
  );

  return (
    <section aria-label="Confirmer pour le joueur" className="grid gap-3 border border-border bg-surface p-5">
      <p className="text-sm text-muted-foreground">
        Le joueur n&apos;a pas encore confirmé. Sans confirmation, il n&apos;a pas accès à la session et son inscription
        sera libérée à la clôture.
      </p>
      <label className="flex items-center gap-2 text-sm text-foreground" htmlFor={emailId}>
        <input
          checked={sendEmail}
          id={emailId}
          onChange={(event) => { setSendEmail(event.target.checked); }}
          type="checkbox"
        />
        Envoyer le mail de confirmation au joueur
      </label>
      <div>
        <button
          className="inline-flex min-h-10 items-center justify-center gap-2 rounded border border-accent bg-accent px-3 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-60"
          disabled={action.busy}
          onClick={action.run}
          type="button"
        >
          <CheckCircle2 aria-hidden="true" className="size-4" />
          {action.busy ? "Confirmation..." : "Confirmer pour le joueur"}
        </button>
      </div>
      {action.error ? <p className="text-sm text-danger" role="alert">{action.error}</p> : null}
      {action.warnings ? (
        <AdminWarningsDialog
          busy={action.busy}
          confirmLabel="Confirmer quand même"
          onCancel={action.dismiss}
          onConfirm={action.confirm}
          warnings={action.warnings}
        />
      ) : null}
    </section>
  );
}
