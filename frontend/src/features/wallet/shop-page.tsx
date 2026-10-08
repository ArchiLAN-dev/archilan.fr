"use client";

import { useState } from "react";
import Link from "next/link";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Eye, Percent, Sparkles } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Dialog, DialogBody } from "@/components/ui/dialog";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { useAuth } from "@/features/auth/auth-context";
import { fetchMyCommunityProfile } from "@/features/community/community-profile-api";
import { FramePreview, type FramePreviewBanner } from "@/features/community/frame-preview";
import { CENTRED_FRAMING, type ImageFraming } from "@/features/community/image-framing";
import { PelleAmount, pellesLabel } from "./pelle-amount";
import { buyShopItem, fetchShop, isNewItem, timeLeftLabel, type CosmeticType, type ShopItem } from "./shop-api";
import { COSMETIC_TYPE_LABELS, ShopCosmeticPreview, useCosmeticLabel, useTitleBadge } from "./shop-cosmetics";
import { shelfHref, shopShelves, type ShelfKey } from "./shop-shelves";
import { fetchMyWallet } from "./wallet-api";

const untilFormatter = new Intl.DateTimeFormat("fr-FR", { day: "numeric", month: "long", timeZone: "Europe/Paris" });

/** The member trying things on: their photo, frame and banner, as on their profile. */
export type ShopShopper = {
  avatarUrl: string | null;
  name: string;
  framing: ImageFraming | null;
  frame: string | null;
  banner: FramePreviewBanner;
};

const VISITOR: ShopShopper = {
  avatarUrl: null,
  name: "Toi",
  framing: null,
  frame: null,
  banner: { presetKey: "default", imageUrl: null, framing: CENTRED_FRAMING, overlay: 50 },
};

/**
 * The cosmetics tab of « Boutique » (stories 41.7 and 41.12): frames and banners drawn by members, sold for gold
 * pelles. Story 41.31: in sections by kind (frames, banners, titles, name colours), the one shown named in the address. Each card shows the item itself, « Essayer » puts it on the member's own profile, and the balance says what
 * they can afford. Visitors see the shop window.
 */
export function CosmeticShop({ shelf = null }: { shelf?: ShelfKey | null }) {
  const { user, loading } = useAuth();
  const queryClient = useQueryClient();
  const signedIn = user !== null && !loading;
  const { data: items, isLoading } = useQuery({ queryKey: ["shop", signedIn], queryFn: fetchShop, staleTime: DEFAULT_STALE_TIME, retry: false, enabled: !loading });
  const { data: wallet } = useQuery({ queryKey: ["my-wallet", 1], queryFn: () => fetchMyWallet(1), staleTime: DEFAULT_STALE_TIME, retry: false, enabled: signedIn });
  const { data: profile } = useQuery({ queryKey: ["community-my-profile"], queryFn: fetchMyCommunityProfile, staleTime: DEFAULT_STALE_TIME, retry: false, enabled: signedIn });

  if (loading || isLoading) return <p className="text-sm text-muted-foreground">Chargement de la boutique…</p>;
  if (!items) return <p className="text-sm text-danger">Impossible de charger la boutique pour le moment.</p>;

  const shopper: ShopShopper | null = signedIn
    ? profile
      ? {
          avatarUrl: profile.avatarUrl,
          name: profile.displayName ?? profile.accountName ?? user.displayName ?? "?",
          framing: profile.avatarFraming,
          frame: profile.avatarFrame,
          banner: { presetKey: profile.bannerPreset, imageUrl: profile.bannerImageUrl, framing: profile.bannerFraming, overlay: profile.bannerOverlay },
        }
      : { ...VISITOR, name: user.displayName ?? "?" }
    : null;

  return (
    <ShopView
      gold={signedIn ? (wallet?.gold ?? null) : null}
      shelf={shelf}
      items={items}
      onBuy={async (itemId, expectedPrice) => {
        const error = await buyShopItem(itemId, expectedPrice);
        await queryClient.invalidateQueries({ queryKey: ["shop"] });
        await queryClient.invalidateQueries({ queryKey: ["my-wallet"] });
        await queryClient.invalidateQueries({ queryKey: ["community-my-profile"] });
        return error;
      }}
      shopper={shopper}
    />
  );
}

export function ShopView({
  items,
  gold,
  shopper,
  onBuy,
  shelf = null,
  now = new Date(),
}: {
  items: ShopItem[];
  /** Story 41.31: the section the address names, null for « Tout ». */
  shelf?: ShelfKey | null;
  /** The gold balance; null for a visitor, or while it loads. */
  gold: number | null;
  /** Null for a visitor. */
  shopper: ShopShopper | null;
  /** Story 41.14: with the price shown, which the server checks before charging. */
  onBuy: (itemId: string, expectedPrice: number) => Promise<string | null>;
  now?: Date;
}) {
  const label = useCosmeticLabel();
  const titleBadge = useTitleBadge();
  const [message, setMessage] = useState<{ tone: "ok" | "error"; text: string; wear?: boolean } | null>(null);
  const [buying, setBuying] = useState<ShopItem | null>(null);
  const [pending, setPending] = useState(false);
  const [trying, setTrying] = useState<ShopItem | null>(null);
  // Story 41.14: the « En promo » filter, shown only while something is on promotion.
  const [promoOnly, setPromoOnly] = useState(false);
  const promoCount = items.filter((item) => item.promotion != null).length;
  // Story 41.31: the sections, filtered by « En promo »; the chips count what each would show.
  const shelves = shopShelves(items, { promoOnly: promoOnly && promoCount > 0, now });
  const shownShelves = shelf === null ? shelves : shelves.filter((s) => s.key === shelf);

  async function confirmBuy(): Promise<void> {
    if (buying === null) return;
    setPending(true);
    const error = await onBuy(buying.id, buying.price);
    setMessage(error === null ? { tone: "ok", text: `« ${label(buying.type, buying.cosmeticKey)} » est à toi.`, wear: true } : { tone: "error", text: error });
    setPending(false);
    setBuying(null);
  }

  return (
    <div className="grid gap-6">
      <section className="card-glow flex flex-wrap items-center justify-between gap-4 rounded-xl border border-border p-5">
        <div className="grid gap-1">
          <p className="flex items-center gap-2 font-heading text-lg font-semibold text-foreground">
            <Sparkles aria-hidden className="size-5 text-warning" />
            Cadres et bannières dessinés par des membres, titres et couleurs de pseudo
          </p>
          <p className="text-sm text-muted-foreground">À gagner en jouant, à dépenser ici : ce que tu achètes est à toi pour de bon.</p>
        </div>
        {shopper !== null ? (
          <div className="grid justify-items-end gap-1">
            <p className="text-xs text-muted-foreground">Ton solde</p>
            {gold !== null ? <PelleAmount amount={gold} className="font-heading text-3xl font-bold text-warning" /> : <span className="text-sm text-muted-foreground">…</span>}
            <Link className="text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline" href="/compte/portefeuille">
              Historique de mes pelles
            </Link>
          </div>
        ) : (
          <Link className={buttonVariants({ variant: "primary" })} href="/connexion?returnTo=/boutique">
            Connecte-toi pour acheter
          </Link>
        )}
      </section>

      {message !== null ? (
        <p className={`text-sm ${message.tone === "ok" ? "text-success" : "text-danger"}`} role="status">
          {message.text}
          {message.wear ? (
            <>
              {" "}
              <Link className="font-semibold underline underline-offset-2" href="/compte/profil">
                Le porter
              </Link>
            </>
          ) : null}
        </p>
      ) : null}

      {items.length > 0 ? (
        <div className="flex flex-wrap items-center justify-between gap-2">
          <nav aria-label="Rayons de cosmétiques" className="flex flex-wrap gap-2">
            <ShelfChip active={shelf === null} count={shelves.reduce((sum, s) => sum + s.items.length, 0)} href={shelfHref(null)} label="Tout" />
            {shelves.map((s) => (
              <ShelfChip active={shelf === s.key} count={s.items.length} href={shelfHref(s.key)} key={s.key} label={s.label} />
            ))}
          </nav>
          {promoCount > 0 ? <FilterChip active={promoOnly} count={promoCount} icon label="En promo" onClick={() => setPromoOnly(!promoOnly)} /> : null}
        </div>
      ) : null}

      {/* Story 41.31: room for « À la une » - a collection of cosmetics put forward - once collections exist. */}

      {items.length === 0 ? (
        <p className="rounded-lg border border-dashed border-border px-4 py-10 text-center text-sm text-muted-foreground">
          La boutique est vide pour l&apos;instant : les premiers objets arrivent avec les dessins des membres.
        </p>
      ) : shownShelves.length === 0 ? (
        <p className="rounded-lg border border-dashed border-border px-4 py-10 text-center text-sm text-muted-foreground">
          Rien dans ce rayon pour l&apos;instant.{" "}
          <Link className="font-semibold text-accent-text hover:underline" href={shelfHref(null)}>
            Voir toute la boutique
          </Link>
        </p>
      ) : (
        shownShelves.map((s) => (
          <section aria-labelledby={`rayon-${s.key}`} className="grid gap-3" key={s.key}>
            <h2 className="flex items-baseline gap-2 font-heading text-xl font-semibold text-foreground" id={`rayon-${s.key}`}>
              {s.label}
              <span className="text-sm font-normal tabular-nums text-muted-foreground">{s.items.length}</span>
            </h2>
            <ul className={`grid gap-4 sm:grid-cols-2 ${s.dense ? "lg:grid-cols-4" : "lg:grid-cols-3"}`}>{s.items.map(renderCard)}</ul>
          </section>
        ))
      )}

      {trying !== null ? (
        <TryOnDialog
          item={trying}
          label={label(trying.type, trying.cosmeticKey)}
          onClose={() => setTrying(null)}
          shopper={shopper ?? VISITOR}
        />
      ) : null}

      <ConfirmDialog
        confirmLabel="Acheter"
        description={
          buying !== null && gold !== null
            ? `${pellesLabel(buying.price)} en or${buying.promotion != null ? ` au lieu de ${pellesLabel(buying.regularPrice ?? buying.price)} (-${buying.promotion.percent} %)` : ""}. Il te restera ${pellesLabel(gold - buying.price)}. Un achat est définitif.`
            : ""
        }
        onConfirm={() => void confirmBuy()}
        onOpenChange={(open) => {
          if (!open && !pending) setBuying(null);
        }}
        open={buying !== null}
        pending={pending}
        title={buying !== null ? `Acheter « ${label(buying.type, buying.cosmeticKey)} » ?` : ""}
      />
    </div>
  );

  /** One item of a section: its preview, its badges, and what the member can do with it. */
  function renderCard(item: ShopItem) {
    const name = label(item.type, item.cosmeticKey);
    const missing = gold !== null && gold < item.price ? item.price - gold : 0;
    return (
      <li className="group grid overflow-hidden rounded-xl border border-border bg-surface transition-colors hover:border-accent/60" key={item.id}>
        <div className="relative">
          <ShopCosmeticPreview
            avatarUrl={shopper?.avatarUrl ?? null}
            cosmeticKey={item.cosmeticKey}
            framing={shopper?.framing ?? null}
            label={name}
            title={item.type === "title" ? titleBadge(item.cosmeticKey) : null}
            name={shopper?.name ?? "?"}
            type={item.type}
          />
          {isNewItem(item.listedAt, now) && !item.owned ? (
            <span className="absolute left-3 top-3 rounded-full bg-accent px-2 py-0.5 text-xs font-semibold text-white">Nouveau</span>
          ) : null}
          {item.owned ? (
            <span className="absolute left-3 top-3 rounded-full bg-success px-2 py-0.5 text-xs font-semibold text-white">Possédé</span>
          ) : null}
          {item.promotion != null && !item.owned ? (
            <span className="absolute right-3 top-3 rounded-full bg-danger px-2 py-0.5 text-xs font-bold text-white">-{item.promotion.percent} %</span>
          ) : null}
        </div>
        <div className="grid gap-3 p-4">
          <div className="min-w-0">
            <p className="truncate font-semibold text-foreground">{name}</p>
            <p className="text-xs text-muted-foreground">
              {COSMETIC_TYPE_LABELS[item.type]}
              {item.availableUntil !== null ? ` · jusqu'au ${untilFormatter.format(new Date(item.availableUntil))}` : ""}
            </p>
            {item.promotion != null && !item.owned ? (
              <p className="mt-1 flex flex-wrap items-center gap-x-2 text-xs">
                <span className="text-muted-foreground line-through">
                  <PelleAmount amount={item.regularPrice ?? item.price} />
                </span>
                <PelleAmount amount={item.promotion.price} className="font-semibold text-warning" />
                <span className="font-medium text-danger">· {timeLeftLabel(item.promotion.endsAt, now.getTime())}</span>
              </p>
            ) : null}
          </div>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <button className={buttonVariants({ variant: "ghost" })} onClick={() => setTrying(item)} type="button">
              <Eye aria-hidden className="size-4" />
              Essayer
            </button>
            {item.owned ? (
              <Link className={buttonVariants({ variant: "secondary" })} href="/compte/profil">
                Le porter
              </Link>
            ) : shopper === null ? (
              <PelleAmount amount={item.price} className="font-semibold text-warning" />
            ) : missing > 0 ? (
              <span className="grid justify-items-end text-xs text-muted-foreground">
                <PelleAmount amount={item.price} className="text-sm font-semibold text-foreground" />
                Il te manque {pellesLabel(missing)}
              </span>
            ) : (
              <button className={buttonVariants({ variant: "primary" })} disabled={gold === null} onClick={() => setBuying(item)} type="button">
                Acheter · <PelleAmount amount={item.price} />
              </button>
            )}
          </div>
        </div>
      </li>
    );
  }
}

/** Story 41.31: a section of the shop, a link so the address names it. */
function ShelfChip({ label, href, active, count }: { label: string; href: string; active: boolean; count: number }) {
  return (
    <Link
      aria-current={active ? "page" : undefined}
      className={`inline-flex min-h-8 items-center gap-1.5 rounded-full border px-3 text-sm font-medium transition-colors ${
        active ? "border-accent bg-accent/15 text-foreground" : "border-border text-muted-foreground hover:border-accent hover:text-foreground"
      }`}
      href={href}
      scroll={false}
    >
      {label}
      <span className="tabular-nums text-xs text-muted-foreground">{count}</span>
    </Link>
  );
}

function FilterChip({ label, active, onClick, count, icon = false }: { label: string; active: boolean; onClick: () => void; count?: number; icon?: boolean }) {
  return (
    <button
      aria-pressed={active}
      className={`inline-flex min-h-8 items-center gap-1.5 rounded-full border px-3 text-sm font-medium transition-colors ${
        active ? "border-accent bg-accent/15 text-foreground" : "border-border text-muted-foreground hover:border-accent hover:text-foreground"
      }`}
      onClick={onClick}
      type="button"
    >
      {icon ? <Percent aria-hidden className="size-3.5 text-danger" /> : null}
      {label}
      {count !== undefined ? <span className="tabular-nums text-xs text-muted-foreground">{count}</span> : null}
    </button>
  );
}

/** The member's own profile header with the item in place of theirs. Nothing is saved. */
function TryOnDialog({ item, label, shopper, onClose }: { item: ShopItem; label: string; shopper: ShopShopper; onClose: () => void }) {
  const frame = item.type === "frame" ? item.cosmeticKey : shopper.frame;
  // A banner tried on shows alone: the member's own image would hide it.
  const banner: FramePreviewBanner = item.type === "banner" ? { presetKey: item.cosmeticKey, imageUrl: null, framing: CENTRED_FRAMING, overlay: 50 } : shopper.banner;

  return (
    <Dialog
      description={`${COSMETIC_TYPE_LABELS[item.type as CosmeticType]} sur ton profil, rien n'est enregistré.`}
      onOpenChange={(open) => {
        if (!open) onClose();
      }}
      open
      size="wide"
      title={`Essayer « ${label} »`}
    >
      <DialogBody>
        {item.type === "title" || item.type === "color" ? (
          // Story 41.22: a title is a text under the name, tried on the member's own avatar.
          <ShopCosmeticPreview avatarUrl={shopper.avatarUrl} className="h-48 rounded-lg" cosmeticKey={item.cosmeticKey} framing={shopper.framing} label={label} name={shopper.name} type={item.type} />
        ) : (
          <FramePreview avatarUrl={shopper.avatarUrl} banner={banner} frame={frame} framing={shopper.framing} name={shopper.name} />
        )}
      </DialogBody>
    </Dialog>
  );
}
