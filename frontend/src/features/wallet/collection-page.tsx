"use client";

import { useState } from "react";
import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { CheckCircle2, Lock, ShoppingBag, Sparkles, Trophy } from "lucide-react";

import { useAuth } from "@/features/auth/auth-context";
import { fetchMyCommunityProfile } from "@/features/community/community-profile-api";
import { COSMETIC_REWARD_TYPE_LABELS, type CosmeticRewardType } from "@/features/community/cosmetic-reward-picker";
import { TITLE_RARITY_LABELS } from "@/features/community/profile-title-catalog";
import type { ImageFraming } from "@/features/community/image-framing";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { COLLECTION_QUERY_KEY, fetchMyCollection, originText, type Collection, type CollectionItem, type CollectionUnlock } from "./collection-api";
import { PelleAmount } from "./pelle-amount";
import { ShopCosmeticPreview } from "./shop-cosmetics";

type TypeFilter = "all" | CosmeticRewardType;
type StatusFilter = "all" | "owned" | "locked";

const TYPE_FILTERS: readonly { key: TypeFilter; label: string }[] = [
  { key: "all", label: "Tout" },
  { key: "title", label: "Titres" },
  { key: "color", label: "Couleurs" },
  { key: "frame", label: "Cadres" },
  { key: "banner", label: "Bannières" },
];

const STATUS_FILTERS: readonly { key: StatusFilter; label: string }[] = [
  { key: "all", label: "Tout" },
  { key: "owned", label: "Obtenus" },
  { key: "locked", label: "À débloquer" },
];

export type Wearer = { name: string; avatarUrl: string | null; framing: ImageFraming | null };

/** `/compte/collection` (story 41.29): every cosmetic, the member's ones first in mind, and how to get the others. */
export function CollectionPanel() {
  const { user } = useAuth();
  const { data, isLoading } = useQuery({ queryKey: COLLECTION_QUERY_KEY, queryFn: fetchMyCollection, staleTime: DEFAULT_STALE_TIME, retry: false });
  const { data: profile } = useQuery({ queryKey: ["community-my-profile"], queryFn: fetchMyCommunityProfile, staleTime: DEFAULT_STALE_TIME, retry: false });

  if (isLoading) return <p className="text-sm text-muted-foreground">Chargement de ta collection…</p>;
  if (!data) return <p className="text-sm text-danger">Impossible de charger ta collection pour le moment.</p>;

  const wearer: Wearer = {
    name: profile?.displayName ?? profile?.accountName ?? user?.displayName ?? "?",
    avatarUrl: profile?.avatarUrl ?? null,
    framing: profile?.avatarFraming ?? null,
  };

  return <CollectionView collection={data} wearer={wearer} />;
}

export function CollectionView({ collection, wearer }: { collection: Collection; wearer: Wearer }) {
  const [type, setType] = useState<TypeFilter>("all");
  const [status, setStatus] = useState<StatusFilter>("all");
  const percent = collection.total > 0 ? Math.round((collection.owned / collection.total) * 100) : 0;

  const shown = collection.items
    .filter((item) => type === "all" || item.type === type)
    .filter((item) => status === "all" || (status === "owned" ? item.status !== "locked" : item.status === "locked"))
    // What the member has first, then what they may wear, then the rest.
    .sort((a, b) => STATUS_ORDER[a.status] - STATUS_ORDER[b.status]);

  return (
    <section aria-labelledby="collection" className="grid gap-5">
      <div className="grid gap-3 rounded-xl border border-border bg-surface p-5">
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <h2 className="font-heading text-xl font-semibold text-foreground" id="collection">
            Ma collection
          </h2>
          <p className="text-sm text-muted-foreground">
            <span className="font-semibold text-foreground">{collection.owned}</span> / {collection.total} cosmétiques obtenus
          </p>
        </div>
        <div aria-label="Cosmétiques obtenus" aria-valuemax={collection.total} aria-valuemin={0} aria-valuenow={collection.owned} className="h-2 overflow-hidden rounded-full bg-surface-2" role="progressbar">
          <div className="h-full rounded-full bg-accent-text transition-[width]" style={{ width: `${percent}%` }} />
        </div>
        <p className="text-xs text-muted-foreground">
          Titres, couleurs de pseudo, cadres et bannières : ceux que tu as, ceux que tu peux déjà porter, et comment obtenir les autres. Tu les portes depuis{" "}
          <Link className="text-accent-text hover:underline" href="/compte/profil">
            ton profil
          </Link>
          .
        </p>
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <FilterGroup label="Type" onChange={setType} options={TYPE_FILTERS} value={type} />
        <FilterGroup label="Statut" onChange={setStatus} options={STATUS_FILTERS} value={status} />
      </div>

      {shown.length === 0 ? (
        <p className="rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">Rien ici pour l&apos;instant.</p>
      ) : (
        <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {shown.map((item) => (
            <CollectionCard item={item} key={`${item.type}:${item.key}`} wearer={wearer} />
          ))}
        </ul>
      )}
    </section>
  );
}

const STATUS_ORDER: Record<CollectionItem["status"], number> = { owned: 0, available: 1, locked: 2 };

function FilterGroup<T extends string>({ label, options, value, onChange }: { label: string; options: readonly { key: T; label: string }[]; value: T; onChange: (value: T) => void }) {
  return (
    <div aria-label={label} className="flex flex-wrap gap-1" role="group">
      {options.map((option) => (
        <button
          aria-pressed={option.key === value}
          className={`rounded-full border px-3 py-1 text-xs font-medium transition-colors ${option.key === value ? "border-accent bg-accent/20 text-foreground" : "border-border text-muted-foreground hover:border-accent/50 hover:text-foreground"}`}
          key={option.key}
          onClick={() => onChange(option.key)}
          type="button"
        >
          {option.label}
        </button>
      ))}
    </div>
  );
}

export function CollectionCard({ item, wearer }: { item: CollectionItem; wearer: Wearer }) {
  const locked = item.status === "locked";
  const title = item.type === "title" ? { label: item.label, rarity: item.rarity ?? "common", icon: item.icon, access: item.access } : null;

  return (
    <li className={`grid overflow-hidden rounded-xl border bg-surface ${locked ? "border-border" : "border-accent/40"}`}>
      <div className={`relative ${locked ? "opacity-45 grayscale-[60%]" : ""}`}>
        <ShopCosmeticPreview avatarUrl={wearer.avatarUrl} className="h-32" cosmeticKey={item.key} framing={wearer.framing} label={item.label} name={wearer.name} title={title} type={item.type} />
        {locked ? (
          <span className="absolute right-2 top-2 grid size-7 place-items-center rounded-full bg-background/80 text-muted-foreground">
            <Lock aria-hidden className="size-3.5" />
          </span>
        ) : null}
      </div>
      <div className="grid gap-2 p-4">
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <p className="font-semibold text-foreground">{item.label}</p>
          <span className="text-xs text-muted-foreground">
            {COSMETIC_REWARD_TYPE_LABELS[item.type]}
            {item.rarity ? ` · ${TITLE_RARITY_LABELS[item.rarity]}` : null}
          </span>
        </div>
        {item.status === "owned" && item.origin ? (
          <p className="flex items-center gap-1.5 text-xs text-success">
            <CheckCircle2 aria-hidden className="size-3.5" />
            Obtenu · {originText(item.origin)}
          </p>
        ) : null}
        {item.status === "available" ? (
          <p className="flex items-center gap-1.5 text-xs text-success">
            <CheckCircle2 aria-hidden className="size-3.5" />
            Tu peux le porter
          </p>
        ) : null}
        {locked ? (
          <ul className="grid gap-1.5">
            {item.unlock.map((way) => (
              <UnlockLine key={`${way.kind}:${way.label}`} way={way} />
            ))}
          </ul>
        ) : null}
      </div>
    </li>
  );
}

function UnlockLine({ way }: { way: CollectionUnlock }) {
  const Icon = way.kind === "achievement" ? Trophy : way.kind === "quest" ? Sparkles : way.kind === "shop" ? ShoppingBag : Lock;

  return (
    <li className="grid gap-0.5 text-xs">
      <span className="flex items-center gap-1.5 text-foreground">
        <Icon aria-hidden className="size-3.5 shrink-0 text-accent-text" />
        {way.kind === "shop" && way.price !== null ? (
          <Link className="hover:text-accent-text hover:underline" href="/boutique">
            En boutique : <PelleAmount amount={way.price} className="font-semibold text-warning" />
          </Link>
        ) : (
          way.label
        )}
      </span>
      {way.detail ? <span className="pl-5 text-muted-foreground">{way.detail}</span> : null}
    </li>
  );
}
