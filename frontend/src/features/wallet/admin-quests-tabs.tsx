"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

export const QUEST_TABS = [
  { href: "/admin/quetes/semaines", label: "Semaines" },
  { href: "/admin/quetes/types", label: "Types de quêtes" },
] as const;

/**
 * The weekly quests' page (story 41.15): one entry in the admin menu, two sub-pages behind tabs - each its own
 * address, so a reload or a shared link lands on the right one.
 */
export function AdminQuestsTabs({ pathname }: { pathname: string }) {
  return (
    <nav aria-label="Quêtes hebdo" className="flex flex-wrap gap-2 border-b border-border">
      {QUEST_TABS.map((tab) => {
        const active = pathname.startsWith(tab.href);
        return (
          <Link
            aria-current={active ? "page" : undefined}
            className={`-mb-px inline-flex min-h-10 items-center border-b-2 px-4 text-sm font-semibold transition-colors ${active ? "border-accent text-foreground" : "border-transparent text-muted-foreground hover:text-foreground"}`}
            href={tab.href}
            key={tab.href}
          >
            {tab.label}
          </Link>
        );
      })}
    </nav>
  );
}

export function AdminQuestsHeader() {
  const pathname = usePathname();

  return (
    <header className="grid gap-4">
      <h1 className="font-heading text-2xl font-bold text-foreground">Quêtes hebdo</h1>
      <AdminQuestsTabs pathname={pathname} />
    </header>
  );
}
