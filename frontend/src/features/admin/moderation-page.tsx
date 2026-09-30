"use client";

import { useCallback, type ReactNode } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";

/**
 * A moderation queue's page (story 39.13): its title, then its tab-less content. The view (status, filters,
 * sort, search) lives in the address (story 39.12); `useModerationParams` reads it and writes it back.
 */
export function ModerationPage({ title, description, children }: { title: string; description: string; children: ReactNode }) {
  return (
    <section className="grid w-full min-w-0 grid-cols-1 gap-6 px-4 py-10">
      <header className="grid gap-1">
        <h1 className="font-heading text-2xl font-bold text-foreground">{title}</h1>
        <p className="text-sm text-muted-foreground">{description}</p>
      </header>
      {children}
    </section>
  );
}

export function useModerationParams(): [URLSearchParams, (next: URLSearchParams) => void] {
  const searchParams = useSearchParams();
  const router = useRouter();
  const pathname = usePathname();

  const onParams = useCallback(
    (next: URLSearchParams) => {
      const query = next.toString();
      router.replace(query === "" ? pathname : `${pathname}?${query}`, { scroll: false });
    },
    [router, pathname],
  );

  return [new URLSearchParams(searchParams.toString()), onParams];
}
