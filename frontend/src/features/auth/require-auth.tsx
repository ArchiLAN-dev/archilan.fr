"use client";

import { useEffect } from "react";
import { usePathname, useRouter } from "next/navigation";
import { useAuth } from "./auth-context";

type RequireAuthProps = {
  children: React.ReactNode;
};

/**
 * Client-side guard for member-only pages.
 *
 * Mirrors the protection used by `AdminShellInner`: while the session is still
 * being resolved we render a skeleton, and once we know there is no
 * authenticated user we redirect to `/connexion` with a `returnTo` so the user
 * lands back on the page after logging in.
 */
export function RequireAuth({ children }: RequireAuthProps) {
  const { user, loading } = useAuth();
  const router = useRouter();
  const pathname = usePathname();

  useEffect(() => {
    if (loading) return;
    if (!user) {
      router.replace(`/connexion?returnTo=${encodeURIComponent(pathname)}`);
    }
  }, [loading, user, router, pathname]);

  if (loading) {
    return (
      <div aria-hidden className="grid gap-4">
        <div className="h-8 w-48 animate-pulse rounded bg-surface" />
        <div className="h-24 animate-pulse rounded-xl bg-surface" />
      </div>
    );
  }

  if (!user) return null;

  return <>{children}</>;
}
