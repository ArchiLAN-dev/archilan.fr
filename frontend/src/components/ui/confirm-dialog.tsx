"use client";

import type { ReactNode } from "react";
import { Loader2, type LucideIcon } from "lucide-react";
import { AlertDialog } from "radix-ui";

import { cn } from "@/lib/utils";
import { buttonVariants } from "./button";

type ConfirmDialogProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: ReactNode;
  description: ReactNode;
  confirmLabel: string;
  /** The way out, « Annuler » by default - renamed where it would read as the action itself (story 33.27). */
  cancelLabel?: string;
  /** `danger` for what cannot be undone, or hurts someone. */
  tone?: "default" | "danger";
  /** What the action is, at a glance (story 39.15): shown in a badge tinted by the tone. */
  icon?: LucideIcon;
  /** The figures to check before confirming (story 39.15), framed under the description. */
  children?: ReactNode;
  pending?: boolean;
  onConfirm: () => void;
};

/**
 * Confirmation before an action that matters (story 39.11). Radix AlertDialog: a click outside does not
 * dismiss it, and the focus lands on "Annuler". The caller closes it once its action is done.
 */
export function ConfirmDialog({
  open,
  onOpenChange,
  title,
  description,
  confirmLabel,
  cancelLabel = "Annuler",
  tone = "default",
  icon: Icon,
  children,
  pending = false,
  onConfirm,
}: ConfirmDialogProps) {
  return (
    <AlertDialog.Root onOpenChange={onOpenChange} open={open}>
      <AlertDialog.Portal>
        <AlertDialog.Overlay className="fixed inset-0 z-50 bg-black/60 backdrop-blur-[2px] data-[state=open]:animate-in data-[state=open]:fade-in-0" />
        <AlertDialog.Content className="fixed left-1/2 top-1/2 z-50 w-[calc(100vw-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 overflow-hidden rounded-xl border border-border bg-surface shadow-2xl focus:outline-none data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95">
          <div className="grid gap-5 p-5 sm:p-6">
            <div className="flex items-start gap-4">
              {Icon !== undefined ? (
                <span
                  className={cn(
                    "grid size-11 shrink-0 place-items-center rounded-full",
                    tone === "danger" ? "bg-danger/15 text-danger ring-1 ring-danger/30" : "bg-accent/20 text-accent-text ring-1 ring-accent/40",
                  )}
                >
                  <Icon aria-hidden className="size-5" />
                </span>
              ) : null}
              <div className="grid min-w-0 gap-1.5 pt-0.5">
                <AlertDialog.Title className="font-heading text-lg font-bold leading-snug text-foreground">{title}</AlertDialog.Title>
                <AlertDialog.Description className="text-sm leading-6 text-muted-foreground">{description}</AlertDialog.Description>
              </div>
            </div>
            {children !== undefined ? <div className="rounded-lg border border-border bg-background/60 p-4">{children}</div> : null}
          </div>
          <div className="flex flex-wrap justify-end gap-2 border-t border-border bg-background/40 px-5 py-3 sm:px-6">
            <AlertDialog.Cancel className={buttonVariants({ variant: "ghost" })} disabled={pending}>
              {cancelLabel}
            </AlertDialog.Cancel>
            <button
              className={buttonVariants({ variant: tone === "danger" ? "danger" : "primary" })}
              disabled={pending}
              onClick={onConfirm}
              type="button"
            >
              {pending ? <Loader2 aria-hidden className="size-4 animate-spin" /> : null}
              {confirmLabel}
            </button>
          </div>
        </AlertDialog.Content>
      </AlertDialog.Portal>
    </AlertDialog.Root>
  );
}

/** One figure of a confirmation summary: a small label over a large value. */
export function ConfirmFigure({ label, children, className }: { label: string; children: ReactNode; className?: string }) {
  return (
    <div className={cn("grid gap-1", className)}>
      <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</span>
      <span className="font-heading text-lg font-bold tabular-nums text-foreground">{children}</span>
    </div>
  );
}
