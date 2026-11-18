"use client";

import { Map as MapIcon, PanelLeftClose, PanelLeftOpen, Package, Settings2 } from "lucide-react";
import { useMemo, useState } from "react";

import { buttonVariants } from "@/components/ui/button";
import { SelectField } from "@/components/ui/select-field";
import { TAB_LIST_CLASS, tabClassName } from "@/components/ui/tabs";

import { isLayoutNode } from "./layout-attributes";
import { mapLabel, mapPathLabel, outlineLayout, type OutlineSection } from "./layout-outline";
import { useTracker } from "./tracker-context";
import { MapLegend } from "./tracker-legend";
import { LayoutWidget, ROOT, TrackerLayout } from "./tracker-layout";
import { TrackerMap } from "./tracker-map";
import { countPins, type PinCategory } from "./tracker-view";

/**
 * Story 54.9: the site's layout of a pack. The maps the pack's layout holds are shown one at a time in the middle,
 * the items in a side panel that folds away; on a phone, two tabs. A pack whose layout gives neither is drawn the way
 * it is written, kept inside the width of the screen.
 */

/** Past this many maps, a list rather than a row of tabs. */
const MAX_MAP_TABS = 6;

type MobileView = "items" | "maps";

export function TrackerBoard({ root }: { root: string }) {
  const { pack, view: trackerView, settings } = useTracker();
  const outline = useMemo(() => {
    const node = pack.layouts[root];
    return isLayoutNode(node) ? outlineLayout(node, pack.layouts, pack.maps.map((map) => map.name)) : { maps: [], sections: [] };
  }, [pack.layouts, pack.maps, root]);
  // Only the maps the pack defines: a layout may name one its maps.json lacks.
  const maps = useMemo(
    () => outline.maps.filter((entry) => entry.page !== undefined || pack.maps.some((map) => map.name === entry.name)),
    [outline.maps, pack.maps],
  );
  const [chosenMap, setChosenMap] = useState<string | null>(null);
  // Story 54.9: the legend's counters narrow the map to some pins; a new map starts with all of them.
  const [only, setOnly] = useState<ReadonlySet<PinCategory>>(() => new Set());
  const [lastMapName, setLastMapName] = useState<string | null>(null);
  const chooseMap = (name: string) => {
    setChosenMap(name);
    if (!name.startsWith("page:")) setLastMapName(name);
    setOnly(new Set());
  };
  const [folded, setFolded] = useState(false);
  const [mobileView, setMobileView] = useState<MobileView>("maps");

  const hasItems = outline.sections.length > 0;
  const hasMaps = maps.length > 0;
  if (!hasItems && !hasMaps) {
    return (
      <div className="min-w-0 max-w-full overflow-x-auto" data-board="pack">
        <TrackerLayout name={root} />
      </div>
    );
  }

  const shownMap = maps.find((entry) => entry.name === chosenMap) ?? maps[0];
  // Story 54.9: the pack's pages (a settings tab beside its maps) stand apart from the maps in a long list.
  const onlyMaps = maps.filter((entry) => entry.page === undefined);
  const pages = maps.filter((entry) => entry.page !== undefined);
  const lastMap = onlyMaps.find((entry) => entry.name === lastMapName) ?? onlyMaps[0] ?? maps[0];
  const both = hasItems && hasMaps;
  const view: MobileView = both ? mobileView : hasMaps ? "maps" : "items";

  return (
    <div className="flex min-h-0 min-w-0 flex-1 flex-col gap-3" data-board="archilan">
      {both ? (
        <div className={`${TAB_LIST_CLASS} lg:hidden`} role="tablist">
          <button aria-selected={view === "maps"} className={tabClassName(view === "maps")} onClick={() => setMobileView("maps")} role="tab" type="button">
            <MapIcon aria-hidden="true" className="size-4" />
            Cartes
          </button>
          <button aria-selected={view === "items"} className={tabClassName(view === "items")} onClick={() => setMobileView("items")} role="tab" type="button">
            <Package aria-hidden="true" className="size-4" />
            Objets
          </button>
        </div>
      ) : null}

      <div className="flex min-h-0 min-w-0 flex-1 flex-col gap-3 lg:flex-row">
        {hasItems ? (
          <ItemsPanel
            folded={both && folded}
            fullWidth={!hasMaps}
            onFold={both ? setFolded : null}
            sections={outline.sections}
            shown={view === "items"}
          />
        ) : null}

        {hasMaps && shownMap !== undefined ? (
          <section
            aria-label="Cartes"
            className={`${view === "maps" ? "flex" : "hidden"} min-h-0 min-w-0 flex-1 flex-col gap-2 lg:flex`}
          >
            {maps.length > MAX_MAP_TABS ? (
              // A long list: the maps in a list, the pack's pages (its settings) as buttons beside it, not lost at the
              // end of a list of dungeon floors.
              <div className="flex flex-wrap items-center gap-2">
                <SelectField
                  className="w-full sm:w-auto sm:max-w-md"
                  label="Carte"
                  onChange={chooseMap}
                  options={onlyMaps.map((entry) => ({ value: entry.name, label: mapPathLabel(entry) }))}
                  value={(shownMap.page === undefined ? shownMap : lastMap).name}
                />
                {pages.map((entry) => (
                  <button
                    aria-pressed={entry === shownMap}
                    className={`${buttonVariants({ variant: entry === shownMap ? "primary" : "secondary" })} gap-2`}
                    key={entry.name}
                    onClick={() => chooseMap(entry === shownMap ? lastMap.name : entry.name)}
                    type="button"
                  >
                    <Settings2 aria-hidden="true" className="size-4" />
                    {mapLabel(entry)}
                  </button>
                ))}
              </div>
            ) : maps.length > 1 ? (
                <div className={TAB_LIST_CLASS} role="tablist">
                  {maps.map((entry) => (
                    <button
                      aria-selected={entry === shownMap}
                      className={tabClassName(entry === shownMap)}
                      key={entry.name}
                      onClick={() => chooseMap(entry.name)}
                      role="tab"
                      type="button"
                    >
                      {mapLabel(entry)}
                    </button>
                  ))}
                </div>
            ) : null}
            {shownMap.page !== undefined ? (
              // A page of the pack (its settings): drawn as the pack lays it out, shrunk to the width if need be.
              <div className="min-w-0 rounded-md border border-border bg-surface p-2" data-page={mapLabel(shownMap)} key={shownMap.name}>
                <LayoutWidget inherited={ROOT} node={shownMap.page} />
              </div>
            ) : (
              <>
                <MapLegend counts={countPins(pack.locations, shownMap.name, trackerView, settings)} only={only} onOnly={setOnly} />
                {/* A new map is a new view: its zoom starts on the whole image. */}
                <TrackerMap key={shownMap.name} name={shownMap.name} only={only} />
              </>
            )}
          </section>
        ) : null}
      </div>
    </div>
  );
}

type PanelProps = {
  sections: OutlineSection[];
  /** On a phone, whether the Items tab is the one shown. */
  shown: boolean;
  /** No map beside it: the panel takes the whole width. */
  fullWidth: boolean;
  folded: boolean;
  onFold: ((folded: boolean) => void) | null;
};

function ItemsPanel({ sections, shown, fullWidth, folded, onFold }: PanelProps) {
  // Folding is for a wide screen; on a phone the Items tab always shows the panel.
  const visibility = `${shown ? "flex" : "hidden"} ${folded ? "lg:hidden" : "lg:flex"}`;
  return (
    <>
      {folded && onFold !== null ? (
        <button
          aria-label="Afficher les objets"
          className="hidden min-h-10 shrink-0 items-center self-start rounded-md border border-border bg-surface px-2 text-muted-foreground hover:text-foreground lg:inline-flex"
          onClick={() => onFold(false)}
          title="Afficher les objets"
          type="button"
        >
          <PanelLeftOpen aria-hidden="true" className="size-4" />
        </button>
      ) : null}
    <aside
      aria-label="Objets"
      className={`${visibility} min-h-0 min-w-0 flex-col gap-2 ${
        fullWidth ? "w-full" : "shrink-0 lg:w-[20rem] lg:overflow-y-auto xl:w-[24rem]"
      }`}
    >
      {onFold !== null ? (
        <button
          className="hidden min-h-9 items-center gap-2 self-end rounded-md px-2 text-xs text-muted-foreground hover:text-foreground lg:inline-flex"
          onClick={() => onFold(true)}
          type="button"
        >
          <PanelLeftClose aria-hidden="true" className="size-4" />
          Replier
        </button>
      ) : null}
      <div className={`grid min-w-0 gap-2 ${fullWidth ? "sm:grid-cols-2 xl:grid-cols-3" : ""}`}>
        {sections.map((section, index) => (
          // Sections come from the pack's layout, which never reorders: the position is the identity.
          <section className="min-w-0 overflow-hidden rounded-md border border-border bg-surface" key={index}>
            {section.title !== null ? (
              <h3 className="border-b border-border px-2 py-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                {section.title}
              </h3>
            ) : null}
            <div className="flex min-w-0 flex-col p-1">
              {section.nodes.map((node, nodeIndex) => (
                // Same: a pack's layout never reorders.
                <LayoutWidget inherited={ROOT} key={nodeIndex} node={node} />
              ))}
            </div>
          </section>
        ))}
      </div>
    </aside>
    </>
  );
}
