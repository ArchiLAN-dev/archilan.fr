"use client";

import { useSearchParams } from "next/navigation";
import { Suspense } from "react";

import { AdminGuidedGameCreation } from "@/features/admin/admin-guided-game-creation";
import AdminNewGamePage from "@/features/admin/admin-new-game-page";

function NewGameRouter() {
  const params = useSearchParams();
  const name = params.get("name");

  if (name) {
    const links = (() => {
      try {
        return JSON.parse(params.get("links") ?? "[]") as { label: string; url: string | null }[];
      } catch {
        return [];
      }
    })();

    return (
      <AdminGuidedGameCreation
        preset={{
          name,
          availability: params.get("availability") ?? "available",
          bundledWithAp: params.get("bundled") === "1",
          adultContent: params.get("adult") === "1",
          links,
        }}
      />
    );
  }

  return <AdminNewGamePage />;
}

export default function Page() {
  return (
    <Suspense>
      <NewGameRouter />
    </Suspense>
  );
}
