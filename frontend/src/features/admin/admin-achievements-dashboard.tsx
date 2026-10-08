"use client";

import { useEffect, useMemo, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { DndContext, KeyboardSensor, PointerSensor, closestCenter, useSensor, useSensors, type DragEndEvent } from "@dnd-kit/core";
import { SortableContext, arrayMove, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { DropdownMenu } from "radix-ui";
import { GripVertical, Image as ImageIcon, Loader2, MoreHorizontal, Plus, Search, Trophy, Upload, X } from "lucide-react";

import { Dialog, DialogBody, DialogFooter } from "@/components/ui/dialog";
import { MarkdownEditor } from "@/components/markdown/markdown-editor";
import { CosmeticRewardPicker, type CosmeticReward } from "@/features/community/cosmetic-reward-picker";
import { ACHIEVEMENT_DESCRIPTION_MAX } from "@/lib/content-limits";
import { fetchDirectory, type DirectoryRow } from "@/features/community/community-directory-api";
import {
  createAchievement,
  fetchAchievementDashboard,
  grantAchievement,
  isRuleGroup,
  reorderAchievements,
  revokeAchievement,
  setAchievementActive,
  updateAchievement,
  uploadAchievementImage,
  type AchievementDefinition,
  type AchievementFormOptions,
  type RuleGroup,
  type RuleNode,
} from "./admin-achievements-api";
import { RuleChips } from "./achievement-rule-chips";
import { RuleTreeEditor } from "./achievement-rule-editor";
import { FAMILY_LABELS, FAMILY_ORDER, familyOf, type FactFamily } from "./achievement-rules";

const QUERY_KEY = ["admin-achievements"] as const;
const STALE_TIME = 15_000;

type EditorState = { mode: "closed" } | { mode: "create" } | { mode: "edit"; definition: AchievementDefinition };

export type AchievementFilters = { search: string; status: "all" | "active" | "inactive"; family: FactFamily | ""; rewardOnly: boolean };

export const NO_FILTERS: AchievementFilters = { search: "", status: "all", family: "", rewardOnly: false };

function fold(value: string): string {
  return value.normalize("NFD").replace(/\p{Diacritic}/gu, "").toLowerCase();
}

function families(node: RuleNode): FactFamily[] {
  return isRuleGroup(node) ? node.rules.flatMap(families) : [familyOf(node.fact)];
}

/** Story 30.51: the definitions a search and the filters keep, in catalogue order. */
export function filterAchievements(definitions: AchievementDefinition[], filters: AchievementFilters): AchievementDefinition[] {
  const needle = fold(filters.search.trim());
  return definitions.filter(
    (d) =>
      (needle === "" || fold(d.name).includes(needle) || fold(d.key).includes(needle)) &&
      (filters.status === "all" || (filters.status === "active") === d.active) &&
      (filters.family === "" || families(d.rule).includes(filters.family)) &&
      (!filters.rewardOnly || d.reward !== null),
  );
}

export function isFiltered(filters: AchievementFilters): boolean {
  return filters.search.trim() !== "" || filters.status !== "all" || filters.family !== "" || filters.rewardOnly;
}

/**
 * « Succès » (stories 30.16, 30.51): the catalogue in profile order. Drag a line by its handle to reorder it (or
 * « Déplacer en position… »), find one by name or key, read its rule in chips; create and edit in a side panel.
 */
export function AdminAchievementsDashboard() {
  const queryClient = useQueryClient();
  const { data, isLoading, isError } = useQuery({ queryKey: QUERY_KEY, queryFn: fetchAchievementDashboard, staleTime: STALE_TIME });
  const [editor, setEditor] = useState<EditorState>({ mode: "closed" });
  const [filters, setFilters] = useState<AchievementFilters>(NO_FILTERS);
  const [grantingId, setGrantingId] = useState<string | null>(null);
  const [moving, setMoving] = useState<AchievementDefinition | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);
  // The order shown while a reorder is saved, so the line stays where it was dropped.
  const [pendingOrder, setPendingOrder] = useState<string[] | null>(null);
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 4 } }), useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }));

  const ordered = useMemo(() => {
    const definitions = data?.definitions ?? [];
    if (pendingOrder === null) return definitions;
    const byId = new Map(definitions.map((d) => [d.id, d]));
    return pendingOrder.flatMap((id) => (byId.has(id) ? [byId.get(id) as AchievementDefinition] : []));
  }, [data, pendingOrder]);
  const shown = filterAchievements(ordered, filters);
  const filtered = isFiltered(filters);

  async function refresh(): Promise<void> {
    await queryClient.invalidateQueries({ queryKey: QUERY_KEY });
  }

  async function reorder(ids: string[]): Promise<void> {
    setPendingOrder(ids);
    await reorderAchievements(ids);
    await refresh();
    setPendingOrder(null);
  }

  function onDragEnd(event: DragEndEvent): void {
    const over = event.over?.id;
    if (over === undefined || over === event.active.id) return;
    const ids = ordered.map((d) => d.id);
    const from = ids.indexOf(String(event.active.id));
    const to = ids.indexOf(String(over));
    if (from < 0 || to < 0) return;
    void reorder(arrayMove(ids, from, to));
  }

  async function toggleActive(definition: AchievementDefinition): Promise<void> {
    setBusyId(definition.id);
    await setAchievementActive(definition.id, !definition.active);
    await refresh();
    setBusyId(null);
  }

  const activeCount = ordered.filter((d) => d.active).length;
  const granting = ordered.find((d) => d.id === grantingId) ?? null;

  return (
    <section className="grid gap-5 p-6 md:p-8">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div className="grid gap-1">
          <h1 className="font-heading text-2xl font-bold text-foreground">Succès</h1>
          {data ? (
            <p className="text-sm text-muted-foreground">
              {ordered.length} succès · {activeCount} actifs · glisse une ligne par sa poignée pour changer l&apos;ordre du profil
            </p>
          ) : null}
        </div>
        <button
          className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-accent bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
          disabled={!data}
          onClick={() => setEditor({ mode: "create" })}
          type="button"
        >
          <Plus aria-hidden className="size-4" /> Nouveau succès
        </button>
      </header>

      {data ? <Toolbar filters={filters} onChange={setFilters} /> : null}

      {isLoading ? (
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 aria-hidden className="size-4 animate-spin" /> Chargement…
        </p>
      ) : isError || !data ? (
        <p className="text-sm text-muted-foreground">Impossible de charger les succès.</p>
      ) : data.definitions.length === 0 ? (
        <p className="rounded-lg border border-border bg-surface px-4 py-8 text-center text-sm text-muted-foreground">Aucun succès défini pour le moment.</p>
      ) : (
        <>
          {filtered ? (
            <p className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
              <strong className="text-foreground">
                {shown.length} succès sur {ordered.length}
              </strong>
              · l&apos;ordre ne se change pas pendant une recherche
              <button className="font-semibold text-accent-text hover:underline" onClick={() => setFilters(NO_FILTERS)} type="button">
                Tout afficher
              </button>
            </p>
          ) : null}
          <DndContext collisionDetection={closestCenter} onDragEnd={onDragEnd} sensors={sensors}>
            <SortableContext disabled={filtered} items={shown.map((d) => d.id)} strategy={verticalListSortingStrategy}>
              <ol className="grid gap-1.5">
                {shown.map((definition) => (
                  <AchievementRow
                    busy={busyId === definition.id}
                    definition={definition}
                    key={definition.id}
                    onEdit={() => setEditor({ mode: "edit", definition })}
                    onGrant={() => setGrantingId(definition.id)}
                    onMove={() => setMoving(definition)}
                    onToggle={() => void toggleActive(definition)}
                    options={data.options}
                    position={ordered.indexOf(definition) + 1}
                    sortable={!filtered}
                  />
                ))}
              </ol>
            </SortableContext>
          </DndContext>
        </>
      )}

      {data ? (
        <Dialog
          onOpenChange={(open) => (open ? null : setEditor({ mode: "closed" }))}
          open={editor.mode !== "closed"}
          size="wide"
          title={editor.mode === "edit" ? "Modifier le succès" : "Nouveau succès"}
          variant="side"
        >
          {editor.mode !== "closed" ? (
            <AchievementForm
              existingKeys={data.definitions.map((d) => d.key)}
              initial={editor.mode === "edit" ? editor.definition : null}
              onClose={() => setEditor({ mode: "closed" })}
              onSaved={async () => {
                await refresh();
                setEditor({ mode: "closed" });
              }}
              options={data.options}
            />
          ) : null}
        </Dialog>
      ) : null}

      {moving ? (
        <MoveDialog
          count={ordered.length}
          current={ordered.indexOf(moving) + 1}
          name={moving.name}
          onClose={() => setMoving(null)}
          onMove={async (position) => {
            const ids = ordered.map((d) => d.id).filter((id) => id !== moving.id);
            ids.splice(position - 1, 0, moving.id);
            setMoving(null);
            await reorder(ids);
          }}
        />
      ) : null}

      {granting ? <GrantPanel definitionId={granting.id} definitionName={granting.name} onClose={() => setGrantingId(null)} /> : null}
    </section>
  );
}

function Toolbar({ filters, onChange }: { filters: AchievementFilters; onChange: (filters: AchievementFilters) => void }) {
  const field = "min-h-10 rounded-lg border border-border bg-surface px-3 text-sm text-foreground outline-none focus:border-accent";
  return (
    <div className="flex flex-wrap items-center gap-2">
      <label className="flex min-h-10 min-w-0 flex-[1_1_16rem] items-center gap-2 rounded-lg border border-border bg-surface px-3 text-muted-foreground focus-within:border-accent">
        <Search aria-hidden className="size-4 shrink-0" />
        <span className="sr-only">Rechercher un succès</span>
        <input
          className="min-w-0 flex-1 bg-transparent text-sm text-foreground outline-none"
          onChange={(e) => onChange({ ...filters, search: e.target.value })}
          placeholder="Rechercher par nom ou clé"
          type="search"
          value={filters.search}
        />
      </label>
      <div aria-label="État" className="inline-flex rounded-lg border border-border bg-surface p-0.5 text-sm" role="group">
        {(
          [
            ["all", "Tous"],
            ["active", "Actifs"],
            ["inactive", "Inactifs"],
          ] as const
        ).map(([status, label]) => (
          <button
            aria-pressed={filters.status === status}
            className={`min-h-9 rounded-md px-3 ${filters.status === status ? "bg-accent font-semibold text-white" : "text-muted-foreground hover:text-foreground"}`}
            key={status}
            onClick={() => onChange({ ...filters, status })}
            type="button"
          >
            {label}
          </button>
        ))}
      </div>
      <select
        aria-label="Famille de critère"
        className={field}
        onChange={(e) => onChange({ ...filters, family: FAMILY_ORDER.find((f) => f === e.target.value) ?? "" })}
        value={filters.family}
      >
        <option value="">Tous les critères</option>
        {FAMILY_ORDER.map((family) => (
          <option key={family} value={family}>
            {FAMILY_LABELS[family]}
          </option>
        ))}
      </select>
      <button
        aria-pressed={filters.rewardOnly}
        className={`${field} ${filters.rewardOnly ? "border-amber-400/60 text-amber-300" : "text-muted-foreground"}`}
        onClick={() => onChange({ ...filters, rewardOnly: !filters.rewardOnly })}
        type="button"
      >
        Avec récompense
      </button>
    </div>
  );
}

function AchievementRow({
  definition,
  options,
  position,
  sortable,
  busy,
  onEdit,
  onToggle,
  onGrant,
  onMove,
}: {
  definition: AchievementDefinition;
  options: AchievementFormOptions;
  position: number;
  sortable: boolean;
  busy: boolean;
  onEdit: () => void;
  onToggle: () => void;
  onGrant: () => void;
  onMove: () => void;
}) {
  const { attributes, listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable({ id: definition.id, disabled: !sortable });
  const holders = definition.holders;

  return (
    <li
      className={`flex flex-wrap items-center gap-3 rounded-xl border bg-surface py-2 pl-1 pr-2 sm:flex-nowrap ${
        isDragging ? "relative z-10 border-accent-text shadow-xl" : "border-border"
      } ${definition.active ? "" : "opacity-60"}`}
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
    >
      {sortable ? (
        <button
          {...attributes}
          {...listeners}
          aria-label={`Déplacer ${definition.name}`}
          className="inline-flex size-9 shrink-0 cursor-grab touch-none items-center justify-center rounded-lg text-muted-foreground hover:text-foreground"
          ref={setActivatorNodeRef}
          type="button"
        >
          <GripVertical aria-hidden className="size-4" />
        </button>
      ) : (
        <span aria-hidden className="w-2 shrink-0" />
      )}
      <span className="w-6 shrink-0 text-right text-xs text-muted-foreground">{position}</span>
      {definition.customImageUrl ? (
        // eslint-disable-next-line @next/next/no-img-element -- remote presigned image, not a local asset
        <img alt="" className="size-9 shrink-0 rounded-full object-cover ring-2 ring-amber-400" src={definition.customImageUrl} />
      ) : (
        <span className="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-amber-400/15 text-amber-400">
          <Trophy aria-hidden className="size-4" />
        </span>
      )}
      <button className="grid min-w-0 flex-1 gap-1 text-left" onClick={onEdit} type="button">
        <span className="flex min-w-0 items-center gap-2">
          <span className="truncate text-sm font-semibold text-foreground">{definition.name}</span>
          <code className="shrink-0 rounded bg-surface-2 px-1.5 py-0.5 text-[11px] text-muted-foreground">{definition.key}</code>
        </span>
        <RuleChips options={options} rule={definition.rule} />
      </button>
      {definition.reward ? (
        <span className="inline-flex h-6 max-w-48 shrink-0 items-center truncate rounded-full border border-amber-400/35 bg-amber-400/10 px-2.5 text-xs text-amber-300">
          {definition.reward.label}
        </span>
      ) : null}
      {holders !== undefined ? (
        <span className="w-24 shrink-0 text-right text-xs text-muted-foreground">
          {holders} {holders > 1 ? "membres" : "membre"}
        </span>
      ) : null}
      <button
        aria-checked={definition.active}
        aria-label={`${definition.name} actif`}
        className={`inline-flex h-6 w-11 shrink-0 items-center rounded-full p-0.5 transition-colors disabled:opacity-50 ${definition.active ? "justify-end bg-success" : "justify-start bg-border"}`}
        disabled={busy}
        onClick={onToggle}
        role="switch"
        type="button"
      >
        <span className="size-5 rounded-full bg-white" />
      </button>
      <DropdownMenu.Root>
        <DropdownMenu.Trigger asChild>
          <button aria-label={`Plus d'actions pour ${definition.name}`} className="inline-flex size-9 shrink-0 items-center justify-center rounded-lg text-muted-foreground hover:bg-surface-2 hover:text-foreground" type="button">
            <MoreHorizontal aria-hidden className="size-4" />
          </button>
        </DropdownMenu.Trigger>
        <DropdownMenu.Portal>
          <DropdownMenu.Content align="end" className="z-50 grid min-w-56 rounded-xl border border-border bg-surface p-1.5 text-sm shadow-xl" sideOffset={4}>
            <DropdownMenu.Item className="cursor-pointer rounded-lg px-3 py-2 text-foreground outline-none data-[highlighted]:bg-surface-2" onSelect={onEdit}>
              Modifier
            </DropdownMenu.Item>
            <DropdownMenu.Item className="cursor-pointer rounded-lg px-3 py-2 text-foreground outline-none data-[highlighted]:bg-surface-2" onSelect={onGrant}>
              Attribuer à un membre…
            </DropdownMenu.Item>
            <DropdownMenu.Item className="cursor-pointer rounded-lg px-3 py-2 text-foreground outline-none data-[highlighted]:bg-surface-2" onSelect={onMove}>
              Déplacer en position…
            </DropdownMenu.Item>
          </DropdownMenu.Content>
        </DropdownMenu.Portal>
      </DropdownMenu.Root>
    </li>
  );
}

function MoveDialog({ name, current, count, onMove, onClose }: { name: string; current: number; count: number; onMove: (position: number) => Promise<void>; onClose: () => void }) {
  const [position, setPosition] = useState(current);
  const valid = position >= 1 && position <= count && position !== current;
  return (
    <Dialog onOpenChange={(open) => (open ? null : onClose())} open title={`Déplacer « ${name} »`}>
      <DialogBody>
        <label className="grid gap-1.5 text-sm">
          <span className="font-medium text-foreground">
            Nouvelle position (1 à {count}, actuellement {current})
          </span>
          <input
            className="min-h-10 w-32 rounded-lg border border-border bg-background px-3 text-sm text-foreground outline-none focus:border-accent"
            max={count}
            min={1}
            onChange={(e) => setPosition(Number.parseInt(e.target.value, 10) || 0)}
            type="number"
            value={position}
          />
        </label>
      </DialogBody>
      <DialogFooter>
        <button className="min-h-10 rounded-lg border border-border px-4 text-sm text-muted-foreground hover:text-foreground" onClick={onClose} type="button">
          Annuler
        </button>
        <button className="min-h-10 rounded-lg bg-accent px-4 text-sm font-semibold text-white hover:bg-accent-hover disabled:opacity-50" disabled={!valid} onClick={() => void onMove(position)} type="button">
          Déplacer
        </button>
      </DialogFooter>
    </Dialog>
  );
}

// ── Manual grant panel (story 30.34) ────────────────────────────────────────────

function GrantPanel({
  definitionId,
  definitionName,
  onClose,
}: {
  definitionId: string;
  definitionName: string;
  onClose: () => void;
}) {
  const [search, setSearch] = useState("");
  const [rows, setRows] = useState<DirectoryRow[]>([]);
  const [searching, setSearching] = useState(false);
  const [searched, setSearched] = useState(false);
  const [busySlug, setBusySlug] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  async function runSearch(): Promise<void> {
    setSearching(true);
    setMessage(null);
    const result = await fetchDirectory({ sort: "xp", search, friendsOnly: false, page: 1 });
    setRows(result?.rows ?? []);
    setSearched(true);
    setSearching(false);
  }

  async function act(action: "grant" | "revoke", row: DirectoryRow): Promise<void> {
    const name = row.displayName ?? row.slug;
    setBusySlug(row.slug);
    const ok =
      action === "grant"
        ? await grantAchievement(definitionId, row.slug)
        : await revokeAchievement(definitionId, row.slug);
    setBusySlug(null);
    setMessage(
      ok
        ? action === "grant"
          ? `« ${definitionName} » attribué à ${name}.`
          : `« ${definitionName} » retiré de ${name}.`
        : "L’opération a échoué.",
    );
  }

  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if (e.key === "Escape") onClose();
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <button aria-label="Fermer" className="absolute inset-0 cursor-default bg-black/60" onClick={onClose} type="button" />
      <div className="relative flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-lg border border-border bg-surface shadow-xl">
        <div className="flex items-center justify-between gap-3 border-b border-border p-4">
          <h3 className="min-w-0 truncate font-heading text-base font-semibold text-foreground">
            Attribuer « {definitionName} » à un joueur
          </h3>
          <button
            aria-label="Fermer"
            className="shrink-0 rounded p-1 text-muted-foreground transition-colors hover:bg-background hover:text-foreground"
            onClick={onClose}
            type="button"
          >
            <X aria-hidden className="size-4" />
          </button>
        </div>

        <div className="grid gap-3 overflow-y-auto p-4">
          <form
        className="flex gap-2"
        onSubmit={(e) => {
          e.preventDefault();
          void runSearch();
        }}
      >
        <input
          className="min-h-9 flex-1 rounded-lg border border-border bg-surface px-3 text-sm text-foreground outline-none focus:border-accent"
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Rechercher un joueur (pseudo)…"
          value={search}
        />
        <button
          className="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-medium text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
          type="submit"
        >
          {searching ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <Search aria-hidden className="size-4" />}
          Rechercher
        </button>
      </form>

      {rows.length > 0 ? (
        <ul className="grid gap-1.5">
          {rows.map((row) => (
            <li
              className="flex items-center justify-between gap-2 rounded border border-border bg-surface px-3 py-2"
              key={row.slug}
            >
              <span className="min-w-0 truncate text-sm text-foreground">
                {row.displayName ?? row.slug} <span className="text-xs text-muted-foreground">@{row.slug}</span>
              </span>
              <span className="flex shrink-0 items-center gap-1.5">
                <button
                  className="rounded border border-accent px-2 py-1 text-xs font-semibold text-accent-text transition-colors hover:bg-accent hover:text-white disabled:opacity-50"
                  disabled={busySlug === row.slug}
                  onClick={() => void act("grant", row)}
                  type="button"
                >
                  Attribuer
                </button>
                <button
                  className="rounded border border-border px-2 py-1 text-xs font-medium text-muted-foreground transition-colors hover:border-red-400 hover:text-red-400 disabled:opacity-50"
                  disabled={busySlug === row.slug}
                  onClick={() => void act("revoke", row)}
                  type="button"
                >
                  Retirer
                </button>
              </span>
            </li>
          ))}
        </ul>
      ) : searched && !searching ? (
        <p className="text-xs text-muted-foreground">Aucun joueur trouvé.</p>
      ) : null}

          {message ? <p className="text-xs text-accent-text">{message}</p> : null}
        </div>
      </div>
    </div>
  );
}

// ── Form ───────────────────────────────────────────────────────────────────────

const KEY_PATTERN = /^[a-z0-9_]{1,64}$/;

function AchievementForm({
  initial,
  options,
  existingKeys,
  onClose,
  onSaved,
}: {
  initial: AchievementDefinition | null;
  options: AchievementFormOptions;
  existingKeys: string[];
  onClose: () => void;
  onSaved: () => Promise<void>;
}) {
  const [key, setKey] = useState(initial?.key ?? "");
  const [name, setName] = useState(initial?.name ?? "");
  const [description, setDescription] = useState(initial?.description ?? "");
  const [rule, setRule] = useState<RuleGroup>(initial?.rule ?? newGroup(options));
  const [imageKey, setImageKey] = useState<string | null>(initial?.customImageKey ?? null);
  // Story 41.28: the cosmetic it unlocks.
  const [reward, setReward] = useState<CosmeticReward | null>(initial?.reward ? { type: initial.reward.type, key: initial.reward.key } : null);
  const [imageUrl, setImageUrl] = useState<string | null>(initial?.customImageUrl ?? null);
  const [uploadingImage, setUploadingImage] = useState(false);
  const [imageError, setImageError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const isEdit = initial !== null;
  const keyValid = isEdit || KEY_PATTERN.test(key);
  const keyDuplicate = !isEdit && existingKeys.includes(key);
  const canSubmit = keyValid && !keyDuplicate && name.trim() !== "" && ruleIsComplete(rule) && !saving;

  async function handlePickImage(file: File): Promise<void> {
    setUploadingImage(true);
    setImageError(null);
    const result = await uploadAchievementImage(file);
    setUploadingImage(false);
    if (result === null) {
      setImageError("L'upload a échoué (JPEG, PNG ou WebP, 5 Mo max).");
      return;
    }
    setImageKey(result.key);
    setImageUrl(result.imageUrl);
  }

  async function submit(): Promise<void> {
    setSaving(true);
    setError(null);
    const result = isEdit
      ? await updateAchievement(initial.id, { name: name.trim(), description: description.trim(), rule, customImageKey: imageKey, reward: reward?.key ? reward : null })
      : await createAchievement({ key, name: name.trim(), description: description.trim(), rule, customImageKey: imageKey, reward: reward?.key ? reward : null });
    setSaving(false);
    if (!result.ok) {
      setError(result.error);
      return;
    }
    await onSaved();
  }

  return (
    <>
      <DialogBody>
        <p className="text-xs text-muted-foreground">
          Une règle assouplie débloque rétroactivement ; un succès déjà obtenu n’est jamais retiré.
        </p>
      <div className="grid gap-4">
        <Field label="Clé (immuable)">
          <input
            className="min-h-10 rounded-lg border border-border bg-surface px-3 text-sm text-foreground outline-none focus:border-accent disabled:opacity-60"
            disabled={isEdit}
            onChange={(e) => setKey(e.target.value)}
            placeholder="ex. night_owl"
            value={key}
          />
          {!isEdit && key !== "" && !keyValid ? (
            <span className="text-xs text-red-400">Minuscules, chiffres et underscore uniquement.</span>
          ) : null}
          {keyDuplicate ? <span className="text-xs text-red-400">Cette clé existe déjà.</span> : null}
        </Field>

        <Field label="Nom">
          <input
            className="min-h-10 rounded-lg border border-border bg-surface px-3 text-sm text-foreground outline-none focus:border-accent"
            onChange={(e) => setName(e.target.value)}
            placeholder="ex. Oiseau de nuit"
            value={name}
          />
        </Field>

        <Field label="Description">
          {/* Rendered inline in a dense achievement card, so the preview uses the same subset. */}
          <MarkdownEditor
            inline
            maxLength={ACHIEVEMENT_DESCRIPTION_MAX}
            onChange={setDescription}
            placeholder="Ce que le joueur doit accomplir."
            rows={3}
            value={description}
          />
        </Field>

        <Field label="Image (optionnel, remplace le trophée)">
          <div className="flex flex-wrap items-center gap-3">
            {imageUrl ? (
              // eslint-disable-next-line @next/next/no-img-element -- remote presigned image, not a local asset
              <img alt="" className="size-12 rounded-lg border border-border object-cover" src={imageUrl} />
            ) : (
              <span className="flex size-12 items-center justify-center rounded-lg border border-dashed border-border text-muted-foreground">
                <ImageIcon aria-hidden className="size-5" />
              </span>
            )}
            <label className="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-border px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:border-accent hover:text-foreground">
              {uploadingImage ? (
                <Loader2 aria-hidden className="size-4 animate-spin" />
              ) : (
                <Upload aria-hidden className="size-4" />
              )}
              {imageUrl ? "Remplacer" : "Choisir une image"}
              <input
                accept="image/jpeg,image/png,image/webp"
                className="hidden"
                onChange={(e) => {
                  const file = e.target.files?.[0];
                  if (file) void handlePickImage(file);
                  e.target.value = "";
                }}
                type="file"
              />
            </label>
            {imageUrl ? (
              <button
                className="text-sm text-muted-foreground transition-colors hover:text-red-400"
                onClick={() => {
                  setImageKey(null);
                  setImageUrl(null);
                }}
                type="button"
              >
                Retirer
              </button>
            ) : null}
          </div>
          {imageError ? <span className="text-xs text-red-400">{imageError}</span> : null}
        </Field>

        <CosmeticRewardPicker id="achievement-reward" onChange={setReward} value={reward} />
        {initial && reward?.key && !(initial.reward?.type === reward.type && initial.reward.key === reward.key) ? (
          <p className="text-xs text-muted-foreground">Les membres qui ont déjà ce succès recevront le cosmétique à l&apos;enregistrement.</p>
        ) : null}

        <div className="grid gap-2">
          <span className="text-sm font-semibold text-foreground">Règle de déblocage</span>
          <RuleTreeEditor onChange={setRule} options={options} rule={rule} />
          {!ruleIsComplete(rule) ? (
            <span className="text-xs text-amber-400">Chaque groupe doit contenir au moins une règle.</span>
          ) : null}
        </div>
      </div>

      {error ? (
        <p className="rounded-lg border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-300">{error}</p>
      ) : null}
      </DialogBody>

      <DialogFooter>
        <button
          className="inline-flex min-h-10 items-center rounded-lg border border-border px-4 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
          onClick={onClose}
          type="button"
        >
          Annuler
        </button>
        <button
          className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-accent bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
          disabled={!canSubmit}
          onClick={() => void submit()}
          type="button"
        >
          {saving ? <Loader2 aria-hidden className="size-4 animate-spin" /> : null}
          Enregistrer
        </button>
      </DialogFooter>
    </>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="grid gap-1.5">
      <span className="text-sm font-medium text-foreground">{label}</span>
      {children}
    </label>
  );
}


function newGroup(options: AchievementFormOptions): RuleGroup {
  return { op: options.groupOps[0] ?? "all", rules: [] };
}

function ruleIsComplete(node: RuleNode): boolean {
  if (!isRuleGroup(node)) return true;
  if (node.rules.length === 0) return false;
  return node.rules.every(ruleIsComplete);
}
