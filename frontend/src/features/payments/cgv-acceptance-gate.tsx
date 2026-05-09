"use client";

import Link from "next/link";
import { useId, useState } from "react";

type CgvAcceptanceGateProps = {
  actionLabel: string;
  children: React.ReactNode;
  includeCgu?: boolean;
};

export function CgvAcceptanceGate({
  actionLabel,
  children,
  includeCgu = false,
}: CgvAcceptanceGateProps) {
  const checkboxId = useId();
  const termsId = useId();
  const [accepted, setAccepted] = useState(false);
  const [confirmed, setConfirmed] = useState(false);

  if (confirmed) {
    return <div className="mt-5">{children}</div>;
  }

  return (
    <div className="mt-5 grid gap-4">
      <div className="flex items-start gap-3">
        <input
          aria-describedby={termsId}
          checked={accepted}
          className="mt-0.5 size-4 accent-accent"
          id={checkboxId}
          onChange={(event) => setAccepted(event.target.checked)}
          type="checkbox"
        />
        <p className="text-sm leading-6 text-foreground" id={termsId}>
          <label className="cursor-pointer" htmlFor={checkboxId}>
            J&apos;ai lu et j&apos;accepte
          </label>{" "}
          les{" "}
          <Link className="underline hover:text-accent-text" href="/cgv">
            Conditions Generales de Vente
          </Link>
          {includeCgu ? (
            <>
              {" "}
              et les{" "}
              <Link className="underline hover:text-accent-text" href="/cgu">
                Conditions Generales d&apos;Utilisation
              </Link>
            </>
          ) : null}{" "}
          d&apos;ArchiLAN ainsi que les{" "}
          <a
            className="underline hover:text-accent-text"
            href="https://www.helloasso.com/page/conditions-generales-d-utilisation"
            rel="noopener noreferrer"
            target="_blank"
          >
            CGU HelloAsso
          </a>
          .
        </p>
      </div>

      <button
        className="inline-flex min-h-11 w-fit items-center justify-center rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60"
        disabled={!accepted}
        onClick={() => setConfirmed(true)}
        type="button"
      >
        {actionLabel}
      </button>
    </div>
  );
}
