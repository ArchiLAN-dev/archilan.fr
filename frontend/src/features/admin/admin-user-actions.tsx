"use client";

import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { KeyRound, Loader2, MailCheck } from "lucide-react";

import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { applyAdminUserAction, type AdminUserActionKind } from "./admin-users-api";

type Props = {
  userId: string;
  isSelf: boolean;
  emailVerified: boolean;
};

/** What the admin reads before each action (in a modal since story 39.14). */
const CONFIRMATIONS: Record<AdminUserActionKind, { title: string; description: string; confirmLabel: string; tone: "default" | "danger" }> = {
  "revoke-sessions": {
    title: "Révoquer toutes les sessions de ce membre ?",
    description: "Il sera déconnecté de tous ses appareils et devra se reconnecter partout.",
    confirmLabel: "Révoquer",
    tone: "danger",
  },
  "verify-email": {
    title: "Valider l'email de ce membre ?",
    description: "Son adresse sera marquée comme vérifiée à sa place, sans qu'il clique sur le lien reçu.",
    confirmLabel: "Valider l'email",
    tone: "default",
  },
};

/**
 * Account-level admin actions on a member (story 36.6). Deliberately a short, closed list: each one
 * answers a real operational case, and each is recorded against the acting admin.
 */
export function AdminUserActions({ userId, isSelf, emailVerified }: Props) {
  const queryClient = useQueryClient();
  const [pending, setPending] = useState<AdminUserActionKind | null>(null);
  const [confirming, setConfirming] = useState<AdminUserActionKind | null>(null);
  const [message, setMessage] = useState<{ tone: "ok" | "error"; text: string } | null>(null);

  async function run(action: AdminUserActionKind): Promise<void> {
    setPending(action);
    setMessage(null);
    const error = await applyAdminUserAction(userId, action);

    if (error === null) {
      setMessage({ tone: "ok", text: "Action appliquée." });
      // The sheet's other panels show what changed (verification badge, activity timeline).
      await queryClient.invalidateQueries({ queryKey: ["admin-user-detail", userId] });
      await queryClient.invalidateQueries({ queryKey: ["admin-user-activity", userId] });
    } else {
      setMessage({ tone: "error", text: error });
    }
    setPending(null);
    setConfirming(null);
  }

  const confirmation = confirming !== null ? CONFIRMATIONS[confirming] : null;

  return (
    <div className="grid gap-3">
      <div className="flex flex-wrap gap-3">
        <ActionButton
          disabled={isSelf}
          disabledReason="Tu ne peux pas révoquer tes propres sessions."
          icon={KeyRound}
          label="Révoquer les sessions"
          onClick={() => setConfirming("revoke-sessions")}
          pending={pending === "revoke-sessions"}
        />
        <ActionButton
          disabled={isSelf || emailVerified}
          disabledReason={isSelf ? "Action indisponible sur ton propre compte." : "Cet email est déjà vérifié."}
          icon={MailCheck}
          label="Valider l'email"
          onClick={() => setConfirming("verify-email")}
          pending={pending === "verify-email"}
        />
      </div>

      {message !== null ? (
        <p className={`text-sm ${message.tone === "ok" ? "text-success" : "text-danger"}`}>{message.text}</p>
      ) : null}

      <ConfirmDialog
        confirmLabel={confirmation?.confirmLabel ?? ""}
        description={confirmation?.description ?? ""}
        onConfirm={() => {
          if (confirming !== null) void run(confirming);
        }}
        onOpenChange={(open) => {
          if (!open && pending === null) setConfirming(null);
        }}
        open={confirming !== null}
        pending={pending !== null}
        title={confirmation?.title ?? ""}
        tone={confirmation?.tone ?? "default"}
      />
    </div>
  );
}

function ActionButton({
  label,
  icon: Icon,
  onClick,
  pending,
  disabled,
  disabledReason,
}: {
  label: string;
  icon: typeof KeyRound;
  onClick: () => void;
  pending: boolean;
  disabled: boolean;
  disabledReason: string;
}) {
  return (
    <button
      className="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-40"
      disabled={disabled || pending}
      onClick={onClick}
      // Why it is unavailable, rather than a dead button with no explanation.
      title={disabled ? disabledReason : undefined}
      type="button"
    >
      {pending ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <Icon aria-hidden className="size-4" />}
      {label}
    </button>
  );
}
