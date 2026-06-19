"use client";

import Link from "next/link";
import { Pencil } from "lucide-react";
import { useAuth } from "@/features/auth/auth-context";

/**
 * Inline admin shortcut shown on public pages: links to the backoffice editor.
 * Rendered only for ROLE_ADMIN users - a UX affordance; the admin endpoints are
 * enforced server-side regardless. `className` lets the caller position it
 * (e.g. `justify-self-end` inside a grid).
 */
export function AdminEditLink({
  href,
  label = "Modifier",
  className = "",
}: {
  href: string;
  label?: string;
  className?: string;
}) {
  const { user } = useAuth();

  if (!user?.roles.includes("ROLE_ADMIN")) {
    return null;
  }

  return (
    <Link
      className={`inline-flex min-h-9 w-fit items-center gap-2 rounded border border-accent/50 bg-accent/10 px-3 text-sm font-semibold text-accent-text transition-colors hover:border-accent ${className}`}
      href={href}
    >
      <Pencil aria-hidden="true" className="size-4" />
      {label}
    </Link>
  );
}