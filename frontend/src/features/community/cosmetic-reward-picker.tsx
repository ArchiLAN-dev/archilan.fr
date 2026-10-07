"use client";

import { useQuery } from "@tanstack/react-query";

import { AVATAR_FRAME_CATALOG_QUERY_KEY, fetchAvatarFrameCatalog, type AvatarFrameAccess } from "./avatar-frame-catalog";
import { NAME_COLORS } from "./name-colors";
import { PROFILE_BANNER_CATALOG_QUERY_KEY, fetchProfileBannerCatalog } from "./profile-banner-catalog";
import { PROFILE_TITLE_CATALOG_QUERY_KEY, fetchProfileTitleCatalog } from "./profile-title-catalog";

/** Story 41.28: a cosmetic an achievement or a quest unlocks. */
export type CosmeticRewardType = "frame" | "banner" | "title" | "color";
export type CosmeticReward = { type: CosmeticRewardType; key: string };

export const COSMETIC_REWARD_TYPES: readonly CosmeticRewardType[] = ["title", "color", "frame", "banner"];
export const COSMETIC_REWARD_TYPE_LABELS: Record<CosmeticRewardType, string> = {
  frame: "Cadre d'avatar",
  banner: "Bannière",
  title: "Titre de profil",
  color: "Couleur de pseudo",
};

export function isCosmeticReward(v: unknown): v is CosmeticReward {
  return typeof v === "object" && v !== null && "type" in v && COSMETIC_REWARD_TYPES.some((type) => type === v.type) && "key" in v && typeof v.key === "string";
}

/** A reward as an API answer carries it: the cosmetic and its name in words. */
export type CosmeticRewardView = CosmeticReward & { label: string };

export function isCosmeticRewardView(v: unknown): v is CosmeticRewardView {
  return isCosmeticReward(v) && "label" in v && typeof v.label === "string";
}

type Option = { key: string; label: string; access?: AvatarFrameAccess };

const fieldClass = "min-h-9 rounded-lg border border-border bg-background px-3 text-sm text-foreground";
const CATALOG_STALE_TIME = 5 * 60_000;

/**
 * Story 41.28: picks the cosmetic an achievement or a quest unlocks - a kind, then one of its catalog. The ones in
 * « Récompense » access come first: they are the ones nobody can buy.
 */
export function CosmeticRewardPicker({ value, onChange, id = "cosmetic-reward" }: { value: CosmeticReward | null; onChange: (reward: CosmeticReward | null) => void; id?: string }) {
  const type = value?.type ?? null;
  const { data: frames = [] } = useQuery({ queryKey: AVATAR_FRAME_CATALOG_QUERY_KEY, queryFn: fetchAvatarFrameCatalog, staleTime: CATALOG_STALE_TIME, enabled: type === "frame" });
  const { data: banners = [] } = useQuery({ queryKey: PROFILE_BANNER_CATALOG_QUERY_KEY, queryFn: fetchProfileBannerCatalog, staleTime: CATALOG_STALE_TIME, enabled: type === "banner" });
  const { data: titles = [] } = useQuery({ queryKey: PROFILE_TITLE_CATALOG_QUERY_KEY, queryFn: fetchProfileTitleCatalog, staleTime: CATALOG_STALE_TIME, enabled: type === "title" });

  const options: Option[] = type === "frame" ? frames : type === "banner" ? banners : type === "title" ? titles : type === "color" ? [...NAME_COLORS] : [];

  return (
    <CosmeticRewardFields id={id} onChange={onChange} options={options} value={value} />
  );
}

/** The two selects of the picker, its catalog given (the picker loads it). */
export function CosmeticRewardFields({ value, options, onChange, id }: { value: CosmeticReward | null; options: readonly Option[]; onChange: (reward: CosmeticReward | null) => void; id: string }) {
  const sorted = [...options].sort((a, b) => Number(b.access === "reward") - Number(a.access === "reward"));

  return (
    <fieldset className="grid gap-2 rounded-lg border border-border p-3">
      <legend className="px-1 text-sm font-medium text-foreground">Récompense cosmétique (optionnelle)</legend>
      <div className="grid gap-2 sm:grid-cols-2">
        <label className="grid gap-1 text-sm" htmlFor={`${id}-type`}>
          <span className="text-muted-foreground">Type</span>
          <select
            className={fieldClass}
            id={`${id}-type`}
            onChange={(e) => {
              const next = COSMETIC_REWARD_TYPES.find((candidate) => candidate === e.target.value) ?? null;
              onChange(next === null ? null : { type: next, key: "" });
            }}
            value={value?.type ?? ""}
          >
            <option value="">Aucune</option>
            {COSMETIC_REWARD_TYPES.map((candidate) => (
              <option key={candidate} value={candidate}>
                {COSMETIC_REWARD_TYPE_LABELS[candidate]}
              </option>
            ))}
          </select>
        </label>
        {value ? (
          <label className="grid gap-1 text-sm" htmlFor={`${id}-key`}>
            <span className="text-muted-foreground">Cosmétique</span>
            <select className={fieldClass} id={`${id}-key`} onChange={(e) => onChange({ type: value.type, key: e.target.value })} value={value.key}>
              <option value="">Choisir…</option>
              {sorted.map((option) => (
                <option key={option.key} value={option.key}>
                  {option.access === "reward" ? `${option.label} (récompense)` : option.label}
                </option>
              ))}
            </select>
          </label>
        ) : null}
      </div>
      <p className="text-xs text-muted-foreground">
        Un cosmétique en accès « Récompense » ne s&apos;achète pas : il se gagne. Un membre qui l&apos;a déjà ne le reçoit pas deux fois.
      </p>
    </fieldset>
  );
}
