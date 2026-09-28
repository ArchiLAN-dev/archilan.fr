"use client";

import { useId, useState } from "react";
import { Loader2 } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { Dialog, DialogBody, DialogFooter } from "@/components/ui/dialog";
import { cn } from "@/lib/utils";

import { applyModerationAction, type ModerationCommand } from "./admin-users-api";
import { canSubmitSanction, SANCTION_OPTIONS, sanctionOption, sanctionUntil } from "./sanction-rules";

type SanctionDialogProps = {
  userId: string;
  name: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** The action selected when the window opens ("Note interne" opens it on the note). */
  initialCommand?: ModerationCommand;
  onDone: () => void | Promise<void>;
};

/**
 * Story 39.11 : la seule fenêtre de sanction, ouverte depuis l'onglet Signalements comme depuis la fiche
 * admin. Le serveur fait foi : son refus s'affiche dans la fenêtre, qui reste ouverte.
 */
export function SanctionDialog({ userId, name, open, onOpenChange, initialCommand = "warn", onDone }: SanctionDialogProps) {
  return (
    <Dialog onOpenChange={onOpenChange} open={open} title={`Modérer ${name}`}>
      <SanctionForm
        initialCommand={initialCommand}
        name={name}
        onCancel={() => onOpenChange(false)}
        onSubmit={async (command, reason, until) => {
          const failure = await applyModerationAction(userId, command, reason, sanctionUntil(command, until));
          if (failure === null) {
            onOpenChange(false);
            await onDone();
          }
          return failure;
        }}
      />
    </Dialog>
  );
}

type SanctionFormProps = {
  name: string;
  initialCommand: ModerationCommand;
  /** Resolves to null on success, or the message to show. */
  onSubmit: (command: ModerationCommand, reason: string, until: string) => Promise<string | null>;
  onCancel: () => void;
};

export function SanctionForm({ name, initialCommand, onSubmit, onCancel }: SanctionFormProps) {
  const formId = useId();
  const [command, setCommand] = useState<ModerationCommand>(initialCommand);
  const [reason, setReason] = useState("");
  const [until, setUntil] = useState("");
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // Mount-stable lower bound for the end date (avoids an impure call during render).
  const [minDate] = useState(() => new Date().toISOString().slice(0, 16));

  const option = sanctionOption(command);
  const isNote = command === "note";

  async function submit(event: React.FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    setPending(true);
    setError(null);
    const failure = await onSubmit(command, reason, until);
    setPending(false);
    setError(failure);
  }

  return (
    <>
      <DialogBody>
        <form className="grid gap-4" id={formId} onSubmit={submit}>
          <fieldset className="grid gap-2">
            <legend className="mb-2 text-sm font-medium text-foreground">Action</legend>
            <div className="flex flex-wrap gap-2">
              {SANCTION_OPTIONS.map((choice) => {
                const selected = choice.command === command;
                return (
                  <label
                    className={cn(
                      "inline-flex min-h-9 cursor-pointer items-center rounded-full border px-3 text-sm font-semibold transition-colors has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-accent/60",
                      selected
                        ? choice.danger
                          ? "border-danger bg-danger/15 text-foreground"
                          : "border-accent bg-accent/15 text-foreground"
                        : "border-border text-muted-foreground hover:border-accent hover:text-foreground",
                    )}
                    key={choice.command}
                  >
                    <input
                      checked={selected}
                      className="sr-only"
                      name="sanction-command"
                      onChange={() => setCommand(choice.command)}
                      type="radio"
                      value={choice.command}
                    />
                    {choice.label}
                  </label>
                );
              })}
            </div>
            <p className="text-xs text-muted-foreground">{option.hint}</p>
          </fieldset>

          {command === "suspend" ? (
            <label className="grid gap-1 text-sm">
              <span className="font-medium text-foreground">Jusqu&apos;au</span>
              <input
                className="min-h-10 rounded-lg border border-border bg-background px-3 text-sm text-foreground focus:border-accent focus:outline-none"
                min={minDate}
                onChange={(event) => setUntil(event.target.value)}
                required
                type="datetime-local"
                value={until}
              />
            </label>
          ) : null}

          <label className="grid gap-1 text-sm">
            <span className="font-medium text-foreground">{isNote ? "Note (obligatoire)" : "Motif (obligatoire)"}</span>
            <textarea
              className="min-h-24 rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:border-accent focus:outline-none"
              onChange={(event) => setReason(event.target.value)}
              placeholder={isNote ? `Ce que le staff doit savoir sur ${name}` : "Visible par le membre et conservé dans l'historique"}
              required
              value={reason}
            />
          </label>

          {error !== null ? (
            <p className="text-sm text-danger" role="alert">
              {error}
            </p>
          ) : null}
        </form>
      </DialogBody>
      <DialogFooter>
        <button className={buttonVariants({ variant: "ghost" })} onClick={onCancel} type="button">
          Annuler
        </button>
        <button
          className={buttonVariants({ variant: option.danger ? "danger" : "primary" })}
          disabled={pending || !canSubmitSanction({ command, reason, until })}
          form={formId}
          type="submit"
        >
          {pending ? <Loader2 aria-hidden className="size-4 animate-spin" /> : null}
          {option.submitLabel}
        </button>
      </DialogFooter>
    </>
  );
}
