"use client";

import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { KeyRound, Loader2, MailCheck } from "lucide-react";

import { applyAdminUserAction, type AdminUserActionKind } from "./admin-users-api";

type Props = {
  userId: string;
  isSelf: boolean;
  emailVerified: boolean;
};

/**
 * Account-level admin actions on a member (story 36.6). Deliberately a short, closed list: each one
 * answers a real operational case, and each is recorded against the acting admin.
 */
export function AdminUserActions({ userId, isSelf, emailVerified }: Props) {
  const queryClient = useQueryClient();
  const [pending, setPending] = useState<AdminUserActionKind | null>(null);
  const [message, setMessage] = useState<{ tone: "ok" | "error"; text: string } | null>(null);

  async function run(action: AdminUserActionKind, confirmation: string): Promise<void> {
    if (!window.confirm(confirmation)) return;

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
  }

  return (
    <div className="grid gap-3">
      <div className="flex flex-wrap gap-3">
        <ActionButton
          disabled={isSelf}
          disabledReason="Tu ne peux pas révoquer tes propres sessions."
          icon={KeyRound}
          label="Révoquer les sessions"
          onClick={() =>
            void run(
              "revoke-sessions",
              "Révoquer toutes les sessions actives de ce membre ? Il devra se reconnecter partout.",
            )
          }
          pending={pending === "revoke-sessions"}
        />
        <ActionButton
          disabled={isSelf || emailVerified}
          disabledReason={isSelf ? "Action indisponible sur ton propre compte." : "Cet email est déjà vérifié."}
          icon={MailCheck}
          label="Valider l'email"
          onClick={() =>
            void run("verify-email", "Marquer l'email de ce membre comme vérifié, à sa place ?")
          }
          pending={pending === "verify-email"}
        />
      </div>

      {message !== null ? (
        <p className={`text-sm ${message.tone === "ok" ? "text-success" : "text-danger"}`}>{message.text}</p>
      ) : null}
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
