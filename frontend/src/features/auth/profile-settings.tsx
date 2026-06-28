"use client";

import { Check, Loader2, Save } from "lucide-react";
import { useCallback, useRef, useState } from "react";

import { CommunityProfileCustomizationForm } from "@/features/community/community-profile-customization-form";
import { ProfileSlugEditor } from "./profile-slug-editor";

type Saver = () => Promise<boolean>;

/**
 * Orchestrates the two profile-edit surfaces (the URL/slug card and the community customization form)
 * behind a single shared save bar. Each child reports its dirty state and registers its own save
 * handler; the bar saves only what changed (the slug and the profile use different endpoints) and
 * shows a combined status. Detailed, per-field errors stay inline in each child.
 */
export function ProfileSettings() {
  const [slugDirty, setSlugDirty] = useState(false);
  const [profileDirty, setProfileDirty] = useState(false);
  const [saving, setSaving] = useState(false);
  const [justSaved, setJustSaved] = useState(false);

  const slugSave = useRef<Saver | null>(null);
  const profileSave = useRef<Saver | null>(null);

  const registerSlugSave = useCallback((fn: Saver) => {
    slugSave.current = fn;
  }, []);
  const registerProfileSave = useCallback((fn: Saver) => {
    profileSave.current = fn;
  }, []);

  // Editing again clears the "saved" confirmation so it never lingers over stale state.
  const handleSlugDirty = useCallback((dirty: boolean) => {
    setSlugDirty(dirty);
    if (dirty) setJustSaved(false);
  }, []);
  const handleProfileDirty = useCallback((dirty: boolean) => {
    setProfileDirty(dirty);
    if (dirty) setJustSaved(false);
  }, []);

  const dirty = slugDirty || profileDirty;
  const pending = [slugDirty ? "URL" : null, profileDirty ? "Profil" : null].filter(
    (p): p is string => p !== null,
  );

  async function handleSave() {
    setSaving(true);
    setJustSaved(false);
    const tasks: Promise<boolean>[] = [];
    if (slugDirty && slugSave.current) tasks.push(slugSave.current());
    if (profileDirty && profileSave.current) tasks.push(profileSave.current());
    const results = await Promise.all(tasks);
    setSaving(false);
    if (results.length > 0 && results.every(Boolean)) setJustSaved(true);
  }

  return (
    <div className="grid gap-6">
      <ProfileSlugEditor onDirtyChange={handleSlugDirty} registerSave={registerSlugSave} />
      <CommunityProfileCustomizationForm onDirtyChange={handleProfileDirty} registerSave={registerProfileSave} />

      <div className="sticky bottom-0 z-20 pt-2 pb-[max(env(safe-area-inset-bottom),0.5rem)]">
        <div
          className={`flex flex-wrap items-center justify-between gap-3 rounded-2xl border px-4 py-3 shadow-[0_8px_30px_-12px_rgba(0,0,0,0.45)] backdrop-blur transition-colors ${
            dirty ? "border-accent/50 bg-surface/95" : "border-border bg-surface/90"
          }`}
        >
          <SaveStatus dirty={dirty} saving={saving} justSaved={justSaved} pending={pending} />
          <button
            className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-accent px-5 text-sm font-semibold text-white shadow-sm transition-all hover:bg-accent-hover active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-40 disabled:shadow-none"
            disabled={!dirty || saving}
            onClick={() => void handleSave()}
            type="button"
          >
            {saving ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <Save aria-hidden className="size-4" />}
            {saving ? "Enregistrement…" : "Enregistrer"}
          </button>
        </div>
      </div>
    </div>
  );
}

function SaveStatus({
  dirty,
  saving,
  justSaved,
  pending,
}: {
  dirty: boolean;
  saving: boolean;
  justSaved: boolean;
  pending: string[];
}) {
  if (saving) {
    return (
      <span className="inline-flex items-center gap-2 text-sm text-muted-foreground">
        <Loader2 aria-hidden className="size-4 animate-spin text-accent-text" />
        Enregistrement…
      </span>
    );
  }

  if (dirty) {
    return (
      <span className="inline-flex flex-wrap items-center gap-2 text-sm">
        <span aria-hidden className="relative flex size-2.5">
          <span className="absolute inline-flex size-full animate-ping rounded-full bg-amber-400/70" />
          <span className="relative inline-flex size-2.5 rounded-full bg-amber-400" />
        </span>
        <span className="font-medium text-foreground">Modifications non enregistrées</span>
        {pending.length > 0 ? (
          <span className="hidden items-center gap-1 sm:inline-flex">
            {pending.map((p) => (
              <span
                className="rounded-full border border-border bg-background px-2 py-0.5 text-xs text-muted-foreground"
                key={p}
              >
                {p}
              </span>
            ))}
          </span>
        ) : null}
      </span>
    );
  }

  if (justSaved) {
    return (
      <span className="inline-flex items-center gap-2 text-sm text-success">
        <Check aria-hidden className="size-4" />
        Modifications enregistrées
      </span>
    );
  }

  return (
    <span className="inline-flex items-center gap-2 text-sm text-muted-foreground">
      <Check aria-hidden className="size-4" />
      Tout est à jour
    </span>
  );
}
