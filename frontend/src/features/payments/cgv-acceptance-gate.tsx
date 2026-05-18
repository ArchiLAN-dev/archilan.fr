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
  const [accepted, setAccepted] = useState(false);
  const [confirmed, setConfirmed] = useState(false);

  if (confirmed) {
    return <div className="mt-6">{children}</div>;
  }

  return (
    <div className="mt-6 grid gap-5">
      <label
        className="flex cursor-pointer items-start gap-3"
        htmlFor={checkboxId}
      >
        <input
          checked={accepted}
          className="mt-0.5 size-4 shrink-0 accent-accent"
          id={checkboxId}
          onChange={(e) => setAccepted(e.target.checked)}
          type="checkbox"
        />
        <span className="text-sm leading-6 text-foreground">
          J&apos;ai lu et j&apos;accepte les{" "}
          <Link
            className="underline underline-offset-2 hover:text-accent-text"
            href="/cgv"
            onClick={(e) => e.stopPropagation()}
          >
            Conditions Générales de Vente
          </Link>
          {includeCgu && (
            <>
              {" "}
              et les{" "}
              <Link
                className="underline underline-offset-2 hover:text-accent-text"
                href="/cgu"
                onClick={(e) => e.stopPropagation()}
              >
                Conditions Générales d&apos;Utilisation
              </Link>
            </>
          )}{" "}
          d&apos;ArchiLAN, ainsi que les{" "}
          <a
            className="underline underline-offset-2 hover:text-accent-text"
            href="https://www.helloasso.com/page/conditions-generales-d-utilisation"
            onClick={(e) => e.stopPropagation()}
            rel="noopener noreferrer"
            target="_blank"
          >
            CGU HelloAsso
          </a>
          .
        </span>
      </label>

      <button
        className="inline-flex min-h-11 w-fit items-center justify-center rounded bg-accent px-5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-50"
        disabled={!accepted}
        onClick={() => setConfirmed(true)}
        type="button"
      >
        {actionLabel}
      </button>
    </div>
  );
}
