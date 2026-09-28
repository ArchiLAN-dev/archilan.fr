"use client";

import type { ReactNode } from "react";
import { Loader2 } from "lucide-react";
import { AlertDialog } from "radix-ui";

import { buttonVariants } from "./button";

type ConfirmDialogProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: ReactNode;
  description: ReactNode;
  confirmLabel: string;
  /** `danger` for what cannot be undone, or hurts someone. */
  tone?: "default" | "danger";
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
  tone = "default",
  pending = false,
  onConfirm,
}: ConfirmDialogProps) {
  return (
    <AlertDialog.Root onOpenChange={onOpenChange} open={open}>
      <AlertDialog.Portal>
        <AlertDialog.Overlay className="fixed inset-0 z-50 bg-black/60 data-[state=open]:animate-in data-[state=open]:fade-in-0" />
        <AlertDialog.Content className="fixed left-1/2 top-1/2 z-50 grid w-[calc(100vw-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 gap-4 rounded-xl border border-border bg-surface p-5 shadow-xl focus:outline-none data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95">
          <div className="grid gap-2">
            <AlertDialog.Title className="font-heading text-lg font-bold text-foreground">{title}</AlertDialog.Title>
            <AlertDialog.Description className="text-sm text-muted-foreground">{description}</AlertDialog.Description>
          </div>
          <div className="flex flex-wrap justify-end gap-2">
            <AlertDialog.Cancel className={buttonVariants({ variant: "ghost" })} disabled={pending}>
              Annuler
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
