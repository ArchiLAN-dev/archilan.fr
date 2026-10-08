"use client";

import { useEffect, useMemo, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { DndContext, KeyboardSensor, PointerSensor, closestCorners, useDroppable, useSensor, useSensors, type DragEndEvent, type DragOverEvent } from "@dnd-kit/core";
import { SortableContext, arrayMove, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { DropdownMenu } from "radix-ui";
import { EyeOff, GripVertical, Image as ImageIcon, Library, Loader2, MoreHorizontal, Plus, Search, Trophy, Upload, X } from "lucide-react";

import { Dialog, DialogBody, DialogFooter } from "@/components/ui/dialog";
import { MarkdownEditor } from "@/components/markdown/markdown-editor";
import { CosmeticRewardPicker, type CosmeticReward } from "@/features/community/cosmetic-reward-picker";
import { ACHIEVEMENT_DESCRIPTION_MAX } from "@/lib/content-limits";
import { fetchDirectory, type DirectoryRow } from "@/features/community/community-directory-api";
import {
  createAchievement,
  createAchievementCollection,
  deleteAchievementCollection,
  fetchAchievementDashboard,
  grantAchievement,
  isRuleGroup,
  reorderAchievementCollections,
  reorderAchievements,
  revokeAchievement,
  setAchievementActive,
  updateAchievement,
  updateAchievementCollection,
  uploadAchievementImage,
  type AchievementDefinition,
  type AdminAchievementCollection,
  type AchievementFormOptions,
  type RuleGroup,
  type RuleNode,
} from "./admin-achievements-api";
import { RuleChips } from "./achievement-rule-chips";
import { RuleTreeEditor } from "./achievement-rule-editor";
import { FAMILY_LABELS, FAMILY_ORDER, familyOf, type FactFamily } from "./achievement-rules";
import {
  OTHERS,
  buildSections,
  collectionChanges,
  flattenSections,
  moveAcross,
  moveWithin,
  sectionDropId,
  type AchievementSection,
} from "./achievement-sections";

const QUERY_KEY = ["admin-achievements"] as const;
const STALE_TIME = 15_000;

type EditorState = { mode: "closed" } | { mode: "create" } | { mode: "edit"; definition: AchievementDefinition };

type CollectionEditorState = { mode: "closed" } | { mode: "create" } | { mode: "edit"; collection: AdminAchievementCollection };

/** `collection`: "" for all, `OTHERS` for « Autres succès », or a collection id (story 30.52). */
export type AchievementFilters = { search: string; status: "all" | "active" | "inactive"; family: FactFamily | ""; rewardOnly: boolean; collection: string };

export const NO_FILTERS: AchievementFilters = { search: "", status: "all", family: "", rewardOnly: false, collection: "" };

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
      (!filters.rewardOnly || d.reward !== null) &&
      (filters.collection === "" || (filters.collection === OTHERS ? d.collectionId == null : d.collectionId === filters.collection)),
  );
}

export function isFiltered(filters: AchievementFilters): boolean {
  return filters.search.trim() !== "" || filters.status !== "all" || filters.family !== "" || filters.rewardOnly || filters.collection !== "";
}

/**
 * « Succès » (stories 30.16, 30.51, 30.52): the catalogue in profile order, one section per collection then « Autres
 * succès ». Drag a line by its handle to reorder it or to move it into another collection (or « Déplacer en
 * position… »), find one by name or key, read its rule in chips; create and edit in a side panel; create, edit, order
 * and delete the collections from their section's header.
 */
export function AdminAchievementsDashboard() {
  const queryClient = useQueryClient();
  const { data, isLoading, isError } = useQuery({ queryKey: QUERY_KEY, queryFn: fetchAchievementDashboard, staleTime: STALE_TIME });
  const [editor, setEditor] = useState<EditorState>({ mode: "closed" });
  const [collectionEditor, setCollectionEditor] = useState<CollectionEditorState>({ mode: "closed" });
  const [deleting, setDeleting] = useState<AdminAchievementCollection | null>(null);
  const [filters, setFilters] = useState<AchievementFilters>(NO_FILTERS);
  const [grantingId, setGrantingId] = useState<string | null>(null);
  const [moving, setMoving] = useState<AchievementDefinition | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);
  // The layout shown while dragging and while a drop is saved, so the line stays where it was dropped.
  const [draft, setDraft] = useState<AchievementSection[] | null>(null);
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 4 } }), useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }));

  const definitions = useMemo(() => data?.definitions ?? [], [data]);
  const collections = useMemo(() => data?.collections ?? [], [data]);
  const saved = useMemo(() => buildSections(definitions, collections), [definitions, collections]);
  const sections = draft ?? saved;
  const byId = useMemo(() => new Map(definitions.map((d) => [d.id, d])), [definitions]);
  const ordered = flattenSections(sections).flatMap((id) => {
    const definition = byId.get(id);
    return definition ? [definition] : [];
  });
  const shown = new Set(filterAchievements(ordered, filters).map((d) => d.id));
  const filtered = isFiltered(filters);

  async function refresh(): Promise<void> {
    await queryClient.invalidateQueries({ queryKey: QUERY_KEY });
  }

  async function saveLayout(next: AchievementSection[]): Promise<void> {
    setDraft(next);
    await reorderAchievements(flattenSections(next), collectionChanges(definitions, next));
    await refresh();
    setDraft(null);
  }

  function onDragOver(event: DragOverEvent): void {
    const over = event.over?.id;
    if (over === undefined) return;
    setDraft((current) => moveAcross(current ?? saved, String(event.active.id), String(over)));
  }

  function onDragEnd(event: DragEndEvent): void {
    const over = event.over?.id;
    const current = draft ?? saved;
    if (over === undefined) {
      setDraft(null);
      return;
    }
    const next = moveWithin(current, String(event.active.id), String(over));
    if (next === saved) {
      setDraft(null);
      return;
    }
    void saveLayout(next);
  }

  async function toggleActive(definition: AchievementDefinition): Promise<void> {
    setBusyId(definition.id);
    await setAchievementActive(definition.id, !definition.active);
    await refresh();
    setBusyId(null);
  }

  async function shiftCollection(collection: AdminAchievementCollection, by: -1 | 1): Promise<void> {
    const ids = collections.map((c) => c.id);
    const from = ids.indexOf(collection.id);
    const to = from + by;
    if (from < 0 || to < 0 || to >= ids.length) return;
    await reorderAchievementCollections(arrayMove(ids, from, to));
    await refresh();
  }

  const activeCount = ordered.filter((d) => d.active).length;
  const granting = ordered.find((d) => d.id === grantingId) ?? null;
  const movingSection = moving ? sections.find((s) => s.ids.includes(moving.id)) : undefined;

  return (
    <section className="grid gap-5 p-6 md:p-8">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div className="grid gap-1">
          <h1 className="font-heading text-2xl font-bold text-foreground">Succès</h1>
          {data ? (
            <p className="text-sm text-muted-foreground">
              {ordered.length} succès · {activeCount} actifs · {collections.length} {collections.length > 1 ? "collections" : "collection"} · glisse une ligne par sa
              poignée pour changer l&apos;ordre ou la collection
            </p>
          ) : null}
        </div>
        <div className="flex flex-wrap gap-2">
          <button
            className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-border bg-surface px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:opacity-50"
            disabled={!data}
            onClick={() => setCollectionEditor({ mode: "create" })}
            type="button"
          >
            <Library aria-hidden className="size-4" /> Nouvelle collection
          </button>
          <button
            className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-accent bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
            disabled={!data}
            onClick={() => setEditor({ mode: "create" })}
            type="button"
          >
            <Plus aria-hidden className="size-4" /> Nouveau succès
          </button>
        </div>
      </header>

      {data ? <Toolbar collections={collections} filters={filters} onChange={setFilters} /> : null}

      {isLoading ? (
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 aria-hidden className="size-4 animate-spin" /> Chargement…
        </p>
      ) : isError || !data ? (
        <p className="text-sm text-muted-foreground">Impossible de charger les succès.</p>
      ) : data.definitions.length === 0 && collections.length === 0 ? (
        <p className="rounded-lg border border-border bg-surface px-4 py-8 text-center text-sm text-muted-foreground">Aucun succès défini pour le moment.</p>
      ) : (
        <>
          {filtered ? (
            <p className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
              <strong className="text-foreground">
                {shown.size} succès sur {ordered.length}
              </strong>
              · l&apos;ordre ne se change pas pendant une recherche
              <button className="font-semibold text-accent-text hover:underline" onClick={() => setFilters(NO_FILTERS)} type="button">
                Tout afficher
              </button>
            </p>
          ) : null}
          <DndContext collisionDetection={closestCorners} onDragCancel={() => setDraft(null)} onDragEnd={onDragEnd} onDragOver={onDragOver} sensors={sensors}>
            <div className="grid gap-4">
              {sections.map((section, index) => {
                const rows = section.ids.flatMap((id) => {
                  const definition = byId.get(id);
                  return definition && shown.has(id) ? [definition] : [];
                });
                if (filtered && rows.length === 0) return null;
                const collection = section.collection;
                return (
                  <SectionView
                    canMoveDown={collection !== null && index < collections.length - 1}
                    canMoveUp={collection !== null && index > 0}
                    count={section.ids.length}
                    key={section.id}
                    onDelete={collection ? () => setDeleting(collection) : undefined}
                    onEdit={collection ? () => setCollectionEditor({ mode: "edit", collection }) : undefined}
                    onShift={collection ? (by) => void shiftCollection(collection, by) : undefined}
                    section={section}
                    sortable={!filtered}
                  >
                    {rows.map((definition) => (
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
                  </SectionView>
                );
              })}
            </div>
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
              collections={collections}
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

      {collectionEditor.mode !== "closed" ? (
        <CollectionDialog
          initial={collectionEditor.mode === "edit" ? collectionEditor.collection : null}
          onClose={() => setCollectionEditor({ mode: "closed" })}
          onSaved={async () => {
            await refresh();
            setCollectionEditor({ mode: "closed" });
          }}
        />
      ) : null}

      {deleting ? (
        <DeleteCollectionDialog
          collection={deleting}
          count={sections.find((s) => s.id === deleting.id)?.ids.length ?? 0}
          onClose={() => setDeleting(null)}
          onDeleted={async () => {
            await refresh();
            setDeleting(null);
          }}
        />
      ) : null}

      {moving && movingSection ? (
        <MoveDialog
          count={movingSection.ids.length}
          current={movingSection.ids.indexOf(moving.id) + 1}
          name={moving.name}
          onClose={() => setMoving(null)}
          onMove={async (position) => {
            const ids = movingSection.ids.filter((id) => id !== moving.id);
            ids.splice(position - 1, 0, moving.id);
            setMoving(null);
            await saveLayout(sections.map((s) => (s.id === movingSection.id ? { ...s, ids } : s)));
          }}
          section={movingSection.collection?.name ?? "Autres succès"}
        />
      ) : null}

      {granting ? <GrantPanel definitionId={granting.id} definitionName={granting.name} onClose={() => setGrantingId(null)} /> : null}
    </section>
  );
}

/** A section of the list: its header (a collection's name, secret mark, reward and menu) and its sortable lines. */
function SectionView({
  section,
  count,
  sortable,
  canMoveUp,
  canMoveDown,
  onEdit,
  onShift,
  onDelete,
  children,
}: {
  section: AchievementSection;
  count: number;
  sortable: boolean;
  canMoveUp: boolean;
  canMoveDown: boolean;
  onEdit?: () => void;
  onShift?: (by: -1 | 1) => void;
  onDelete?: () => void;
  children: React.ReactNode;
}) {
  const { setNodeRef, isOver } = useDroppable({ id: sectionDropId(section.id), disabled: !sortable });
  const collection = section.collection;
  const reward = collection ? [collection.reward?.label ?? null, collection.pelles > 0 ? `${collection.pelles} pelles` : null].filter((p) => p !== null).join(" · ") : "";
  const item = "cursor-pointer rounded-lg px-3 py-2 text-foreground outline-none data-[highlighted]:bg-surface-2 data-[disabled]:cursor-default data-[disabled]:opacity-40";

  return (
    <section aria-label={collection?.name ?? "Autres succès"} className={`grid gap-2 rounded-2xl border p-2 transition-colors ${isOver ? "border-accent-text bg-accent/5" : "border-border/60"}`} ref={setNodeRef}>
      <header className="flex flex-wrap items-center gap-2 px-2 pt-1">
        {collection?.imageUrl ? (
          // eslint-disable-next-line @next/next/no-img-element -- remote presigned image, not a local asset
          <img alt="" className="size-7 rounded-md object-cover" src={collection.imageUrl} />
        ) : collection ? (
          <Library aria-hidden className="size-4 text-accent-text" />
        ) : null}
        <h2 className="font-heading text-sm font-semibold text-foreground">{collection?.name ?? "Autres succès"}</h2>
        <span className="text-xs text-muted-foreground">
          {count} succès
        </span>
        {collection?.secret ? (
          <span className="inline-flex items-center gap-1 rounded-full border border-border px-2 py-0.5 text-[11px] text-muted-foreground">
            <EyeOff aria-hidden className="size-3" /> Secrète
          </span>
        ) : null}
        {reward !== "" ? (
          <span className="inline-flex h-6 max-w-64 items-center truncate rounded-full border border-amber-400/35 bg-amber-400/10 px-2.5 text-xs text-amber-300">Complète : {reward}</span>
        ) : null}
        {collection && onEdit && onShift && onDelete ? (
          <DropdownMenu.Root>
            <DropdownMenu.Trigger asChild>
              <button aria-label={`Actions de la collection ${collection.name}`} className="ml-auto inline-flex size-8 items-center justify-center rounded-lg text-muted-foreground hover:bg-surface-2 hover:text-foreground" type="button">
                <MoreHorizontal aria-hidden className="size-4" />
              </button>
            </DropdownMenu.Trigger>
            <DropdownMenu.Portal>
              <DropdownMenu.Content align="end" className="z-50 grid min-w-48 rounded-xl border border-border bg-surface p-1.5 text-sm shadow-xl" sideOffset={4}>
                <DropdownMenu.Item className={item} onSelect={onEdit}>
                  Modifier la collection
                </DropdownMenu.Item>
                <DropdownMenu.Item className={item} disabled={!canMoveUp} onSelect={() => onShift(-1)}>
                  Monter
                </DropdownMenu.Item>
                <DropdownMenu.Item className={item} disabled={!canMoveDown} onSelect={() => onShift(1)}>
                  Descendre
                </DropdownMenu.Item>
                <DropdownMenu.Item className={`${item} text-red-400`} onSelect={onDelete}>
                  Supprimer…
                </DropdownMenu.Item>
              </DropdownMenu.Content>
            </DropdownMenu.Portal>
          </DropdownMenu.Root>
        ) : null}
      </header>
      {collection && collection.description !== "" ? <p className="px-2 text-xs text-muted-foreground">{collection.description}</p> : null}
      <SortableContext disabled={!sortable} items={section.ids} strategy={verticalListSortingStrategy}>
        <ol className="grid min-h-12 gap-1.5">
          {children}
          {count === 0 ? (
            <li className="rounded-xl border border-dashed border-border px-4 py-3 text-center text-xs text-muted-foreground">
              {collection ? "Glisse un succès ici pour le ranger dans cette collection." : "Tous les succès sont rangés dans une collection."}
            </li>
          ) : null}
        </ol>
      </SortableContext>
    </section>
  );
}

function Toolbar({ filters, collections, onChange }: { filters: AchievementFilters; collections: AdminAchievementCollection[]; onChange: (filters: AchievementFilters) => void }) {
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
      {collections.length > 0 ? (
        <select aria-label="Collection" className={field} onChange={(e) => onChange({ ...filters, collection: e.target.value })} value={filters.collection}>
          <option value="">Toutes les collections</option>
          {collections.map((collection) => (
            <option key={collection.id} value={collection.id}>
              {collection.name}
            </option>
          ))}
          <option value={OTHERS}>Autres succès</option>
        </select>
      ) : null}
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

function MoveDialog({
  name,
  section,
  current,
  count,
  onMove,
  onClose,
}: {
  name: string;
  section: string;
  current: number;
  count: number;
  onMove: (position: number) => Promise<void>;
  onClose: () => void;
}) {
  const [position, setPosition] = useState(current);
  const valid = position >= 1 && position <= count && position !== current;
  return (
    <Dialog onOpenChange={(open) => (open ? null : onClose())} open title={`Déplacer « ${name} »`}>
      <DialogBody>
        <label className="grid gap-1.5 text-sm">
          <span className="font-medium text-foreground">
            Nouvelle position dans « {section} » (1 à {count}, actuellement {current})
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

const NEW_COLLECTION = "__new__";

function AchievementForm({
  initial,
  options,
  collections,
  existingKeys,
  onClose,
  onSaved,
}: {
  initial: AchievementDefinition | null;
  options: AchievementFormOptions;
  collections: AdminAchievementCollection[];
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
  // Story 30.52: its collection, or a new one named here and created on save.
  const [collectionId, setCollectionId] = useState<string>(initial?.collectionId ?? "");
  const [newCollection, setNewCollection] = useState("");
  const [uploadingImage, setUploadingImage] = useState(false);
  const [imageError, setImageError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const isEdit = initial !== null;
  const keyValid = isEdit || KEY_PATTERN.test(key);
  const keyDuplicate = !isEdit && existingKeys.includes(key);
  const collectionValid = collectionId !== NEW_COLLECTION || newCollection.trim() !== "";
  const canSubmit = keyValid && !keyDuplicate && name.trim() !== "" && ruleIsComplete(rule) && collectionValid && !saving;

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
    let collection: string | null = collectionId === "" ? null : collectionId;
    if (collectionId === NEW_COLLECTION) {
      const created = await createAchievementCollection({ name: newCollection.trim(), description: "", secret: false, imageKey: null, reward: null, pelles: 0 });
      if (!created.ok) {
        setSaving(false);
        setError(created.error);
        return;
      }
      collection = created.value.id;
      setCollectionId(collection);
    }
    const fields = { name: name.trim(), description: description.trim(), rule, customImageKey: imageKey, reward: reward?.key ? reward : null, collectionId: collection };
    const result = isEdit ? await updateAchievement(initial.id, fields) : await createAchievement({ key, ...fields });
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

        <Field label="Collection">
          <select
            className="min-h-10 rounded-lg border border-border bg-surface px-3 text-sm text-foreground outline-none focus:border-accent"
            onChange={(e) => setCollectionId(e.target.value)}
            value={collectionId}
          >
            <option value="">Aucune (Autres succès)</option>
            {collections.map((collection) => (
              <option key={collection.id} value={collection.id}>
                {collection.name}
                {collection.secret ? " (secrète)" : ""}
              </option>
            ))}
            <option value={NEW_COLLECTION}>+ Nouvelle collection…</option>
          </select>
          {collectionId === NEW_COLLECTION ? (
            <input
              aria-label="Nom de la nouvelle collection"
              className="min-h-10 rounded-lg border border-border bg-surface px-3 text-sm text-foreground outline-none focus:border-accent"
              maxLength={120}
              onChange={(e) => setNewCollection(e.target.value)}
              placeholder="ex. Re:Zero"
              value={newCollection}
            />
          ) : null}
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

// ── Collections (story 30.52) ──────────────────────────────────────────────────

function CollectionDialog({ initial, onClose, onSaved }: { initial: AdminAchievementCollection | null; onClose: () => void; onSaved: () => Promise<void> }) {
  const [name, setName] = useState(initial?.name ?? "");
  const [description, setDescription] = useState(initial?.description ?? "");
  const [secret, setSecret] = useState(initial?.secret ?? false);
  const [imageKey, setImageKey] = useState<string | null>(initial?.imageKey ?? null);
  const [imageUrl, setImageUrl] = useState<string | null>(initial?.imageUrl ?? null);
  const [reward, setReward] = useState<CosmeticReward | null>(initial?.reward ? { type: initial.reward.type, key: initial.reward.key } : null);
  const [pelles, setPelles] = useState(initial?.pelles ?? 0);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const field = "min-h-10 rounded-lg border border-border bg-surface px-3 text-sm text-foreground outline-none focus:border-accent";

  async function pickImage(file: File): Promise<void> {
    setUploading(true);
    setError(null);
    const result = await uploadAchievementImage(file);
    setUploading(false);
    if (result === null) {
      setError("L'upload a échoué (JPEG, PNG ou WebP, 5 Mo max).");
      return;
    }
    setImageKey(result.key);
    setImageUrl(result.imageUrl);
  }

  async function submit(): Promise<void> {
    setSaving(true);
    setError(null);
    const payload = { name: name.trim(), description: description.trim(), secret, imageKey, reward: reward?.key ? reward : null, pelles };
    const result = initial ? await updateAchievementCollection(initial.id, payload) : await createAchievementCollection(payload);
    setSaving(false);
    if (!result.ok) {
      setError(result.error);
      return;
    }
    await onSaved();
  }

  return (
    <Dialog onOpenChange={(open) => (open ? null : onClose())} open title={initial ? `Modifier « ${initial.name} »` : "Nouvelle collection"} variant="side">
      <DialogBody>
        <div className="grid gap-4">
          <Field label="Nom">
            <input className={field} maxLength={120} onChange={(e) => setName(e.target.value)} placeholder="ex. Re:Zero" value={name} />
          </Field>
          <Field label="Description courte">
            <textarea className={`${field} py-2`} maxLength={500} onChange={(e) => setDescription(e.target.value)} rows={2} value={description} />
          </Field>
          <label className="flex items-start gap-3 rounded-lg border border-border bg-surface p-3 text-sm">
            <input checked={secret} className="mt-0.5 size-4 accent-accent" onChange={(e) => setSecret(e.target.checked)} type="checkbox" />
            <span className="grid gap-0.5">
              <span className="font-medium text-foreground">Collection secrète</span>
              <span className="text-xs text-muted-foreground">Invisible pour un membre tant qu&apos;il n&apos;a débloqué aucun de ses succès.</span>
            </span>
          </label>
          <Field label="Image (optionnel)">
            <div className="flex flex-wrap items-center gap-3">
              {imageUrl ? (
                // eslint-disable-next-line @next/next/no-img-element -- remote presigned image, not a local asset
                <img alt="" className="size-12 rounded-lg border border-border object-cover" src={imageUrl} />
              ) : (
                <span className="flex size-12 items-center justify-center rounded-lg border border-dashed border-border text-muted-foreground">
                  <Library aria-hidden className="size-5" />
                </span>
              )}
              <label className="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-border px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:border-accent hover:text-foreground">
                {uploading ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <Upload aria-hidden className="size-4" />}
                {imageUrl ? "Remplacer" : "Choisir une image"}
                <input
                  accept="image/jpeg,image/png,image/webp"
                  className="hidden"
                  onChange={(e) => {
                    const file = e.target.files?.[0];
                    if (file) void pickImage(file);
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
          </Field>
          <div className="grid gap-2 rounded-lg border border-border p-3">
            <span className="text-sm font-semibold text-foreground">Récompense de la collection complète</span>
            <span className="text-xs text-muted-foreground">Donnée une seule fois, quand le dernier de ses succès actifs est débloqué.</span>
            <CosmeticRewardPicker id="collection-reward" onChange={setReward} value={reward} />
            <Field label="Pelles">
              <input
                className={`${field} w-32`}
                inputMode="numeric"
                min={0}
                onChange={(e) => setPelles(Math.max(0, Number.parseInt(e.target.value, 10) || 0))}
                type="number"
                value={pelles}
              />
            </Field>
          </div>
          {error ? <p className="rounded-lg border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-300">{error}</p> : null}
        </div>
      </DialogBody>
      <DialogFooter>
        <button className="inline-flex min-h-10 items-center rounded-lg border border-border px-4 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground" onClick={onClose} type="button">
          Annuler
        </button>
        <button
          className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-accent bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
          disabled={name.trim() === "" || saving}
          onClick={() => void submit()}
          type="button"
        >
          {saving ? <Loader2 aria-hidden className="size-4 animate-spin" /> : null}
          Enregistrer
        </button>
      </DialogFooter>
    </Dialog>
  );
}

function DeleteCollectionDialog({ collection, count, onClose, onDeleted }: { collection: AdminAchievementCollection; count: number; onClose: () => void; onDeleted: () => Promise<void> }) {
  const [busy, setBusy] = useState(false);
  const [failed, setFailed] = useState(false);
  return (
    <Dialog onOpenChange={(open) => (open ? null : onClose())} open title={`Supprimer « ${collection.name} » ?`}>
      <DialogBody>
        <p className="text-sm text-muted-foreground">
          {count > 0 ? `Ses ${count} succès retournent dans « Autres succès ». ` : ""}Les membres qui l&apos;ont complétée gardent leur récompense.
        </p>
        {failed ? <p className="text-sm text-red-400">La suppression a échoué.</p> : null}
      </DialogBody>
      <DialogFooter>
        <button className="min-h-10 rounded-lg border border-border px-4 text-sm text-muted-foreground hover:text-foreground" onClick={onClose} type="button">
          Annuler
        </button>
        <button
          className="min-h-10 rounded-lg bg-red-500 px-4 text-sm font-semibold text-white hover:bg-red-600 disabled:opacity-50"
          disabled={busy}
          onClick={async () => {
            setBusy(true);
            const ok = await deleteAchievementCollection(collection.id);
            setBusy(false);
            if (ok) await onDeleted();
            else setFailed(true);
          }}
          type="button"
        >
          Supprimer
        </button>
      </DialogFooter>
    </Dialog>
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
