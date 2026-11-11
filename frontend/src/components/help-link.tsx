import Link from "next/link";
import { CircleHelp } from "lucide-react";

/** Story 53.5: the "C'est quoi ?" link of a tool card, to its help guide. Nothing without a guide to show. */
export function HelpLink({ href }: { href?: string | null }) {
  if (!href) return null;

  return (
    <Link className="inline-flex w-fit items-center gap-1 text-xs font-semibold text-accent-text hover:text-accent-text-hover" href={href}>
      <CircleHelp aria-hidden="true" className="size-3.5" />
      C&apos;est quoi ?
    </Link>
  );
}
