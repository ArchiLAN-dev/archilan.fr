/** Block-level placeholder for legal sections whose required text is not finalized yet. */
export function LegalPlaceholder({ children }: { children: React.ReactNode }) {
  return (
    <div
      aria-label="Contenu legal requis a completer"
      className="rounded border border-dashed border-accent-warm/50 bg-accent-warm/5 p-4"
      role="note"
    >
      <p className="text-xs font-semibold uppercase tracking-widest text-accent-warm">
        Contenu requis
      </p>
      <p className="mt-1 text-sm leading-6 text-muted-foreground">{children}</p>
    </div>
  );
}

/** Inline placeholder for a single required legal data field inside a definition list. */
export function LegalField({ label }: { label: string }) {
  return (
    <span className="inline-flex flex-wrap items-baseline gap-1.5">
      <span className="rounded border border-dashed border-accent-warm/50 bg-accent-warm/5 px-1.5 py-0.5 text-xs font-semibold text-accent-warm">
        Requis
      </span>
      <span className="text-sm text-muted-foreground">{label}</span>
    </span>
  );
}
