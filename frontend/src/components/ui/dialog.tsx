"use client";

import type { ReactNode } from "react";
import { X } from "lucide-react";
import { Dialog as RadixDialog } from "radix-ui";

import { cn } from "@/lib/utils";

type DialogProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: ReactNode;
  description?: ReactNode;
  /** `center` for a form or a confirmation, `side` for a panel to read next to the list it comes from. */
  variant?: "center" | "side";
  /** `wide`: a centred picker with a preview beside it, or a side panel holding a large form (story 30.51). */
  size?: "default" | "wide";
  children: ReactNode;
};

/**
 * Modal window shared by the admin screens (story 39.11), on Radix: focus trapped inside, Escape and the
 * cross close it, the title is announced. Its content unmounts when it closes, so a form inside starts
 * blank each time it opens.
 */
export function Dialog({ open, onOpenChange, title, description, variant = "center", size = "default", children }: DialogProps) {
  return (
    <RadixDialog.Root onOpenChange={onOpenChange} open={open}>
      <RadixDialog.Portal>
        <RadixDialog.Overlay className="fixed inset-0 z-50 bg-black/60 data-[state=open]:animate-in data-[state=open]:fade-in-0" />
        <RadixDialog.Content
          // Without a description Radix expects the attribute to be explicitly absent.
          {...(description === undefined ? { "aria-describedby": undefined } : {})}
          className={cn(
            "fixed z-50 flex flex-col border-border bg-surface shadow-xl focus:outline-none",
            variant === "side"
              ? cn(
                  "inset-y-0 right-0 h-full w-full border-l data-[state=open]:animate-in data-[state=open]:slide-in-from-right",
                  size === "wide" ? "max-w-2xl" : "max-w-md",
                )
              : cn(
                  "left-1/2 top-1/2 max-h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] -translate-x-1/2 -translate-y-1/2 rounded-xl border data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95",
                  size === "wide" ? "max-w-4xl" : "max-w-lg",
                ),
          )}
        >
          <header className="flex items-start justify-between gap-3 border-b border-border px-5 py-4">
            <div className="grid min-w-0 gap-1">
              <RadixDialog.Title className="font-heading text-lg font-bold text-foreground">{title}</RadixDialog.Title>
              {description !== undefined ? (
                <RadixDialog.Description className="text-sm text-muted-foreground">{description}</RadixDialog.Description>
              ) : null}
            </div>
            <RadixDialog.Close
              aria-label="Fermer"
              className="rounded p-1 text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60"
            >
              <X aria-hidden className="size-5" />
            </RadixDialog.Close>
          </header>
          {children}
        </RadixDialog.Content>
      </RadixDialog.Portal>
    </RadixDialog.Root>
  );
}

/** Scrollable middle of a dialog. */
export function DialogBody({ children, className }: { children: ReactNode; className?: string }) {
  return <div className={cn("grid min-h-0 flex-1 content-start gap-4 overflow-y-auto px-5 py-4", className)}>{children}</div>;
}

/** Bottom bar of a dialog: its buttons, the confirming one last. */
export function DialogFooter({ children }: { children: ReactNode }) {
  return <footer className="flex flex-wrap items-center justify-end gap-2 border-t border-border px-5 py-3">{children}</footer>;
}
