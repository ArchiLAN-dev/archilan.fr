import { arrayMove } from "@dnd-kit/sortable";

import type { AchievementDefinition, AdminAchievementCollection } from "./admin-achievements-api";

/** Story 30.52: the id of the « Autres succès » section (achievements of no collection). */
export const OTHERS = "others";

const SECTION_PREFIX = "section:";

/** A section of the admin list: a collection (or « Autres succès ») and its achievements, in profile order. */
export type AchievementSection = { id: string; collection: AdminAchievementCollection | null; ids: string[] };

export function sectionDropId(sectionId: string): string {
  return `${SECTION_PREFIX}${sectionId}`;
}

/** The collections in their order, then « Autres succès »; an achievement of an unknown collection goes there too. */
export function buildSections(definitions: AchievementDefinition[], collections: AdminAchievementCollection[]): AchievementSection[] {
  const known = new Set(collections.map((c) => c.id));
  return [
    ...collections.map((collection) => ({ id: collection.id, collection, ids: definitions.filter((d) => d.collectionId === collection.id).map((d) => d.id) })),
    { id: OTHERS, collection: null, ids: definitions.filter((d) => d.collectionId == null || !known.has(d.collectionId)).map((d) => d.id) },
  ];
}

/** The index of the section an achievement, or a section's drop zone, belongs to; -1 when none. */
export function sectionIndexOf(sections: AchievementSection[], id: string): number {
  if (id.startsWith(SECTION_PREFIX)) {
    const sectionId = id.slice(SECTION_PREFIX.length);
    return sections.findIndex((s) => s.id === sectionId);
  }
  return sections.findIndex((s) => s.ids.includes(id));
}

/**
 * While dragging over another section: the achievement leaves its section for that one, where the pointer is (before
 * the line it is over), or at its end over the section's empty space.
 */
export function moveAcross(sections: AchievementSection[], activeId: string, overId: string): AchievementSection[] {
  const from = sectionIndexOf(sections, activeId);
  const to = sectionIndexOf(sections, overId);
  if (from < 0 || to < 0 || from === to) return sections;
  const target = sections[to];
  if (target === undefined) return sections;
  const at = target.ids.indexOf(overId);
  const index = at < 0 ? target.ids.length : at;
  return sections.map((section, i) => {
    if (i === from) return { ...section, ids: section.ids.filter((id) => id !== activeId) };
    if (i === to) return { ...section, ids: [...section.ids.slice(0, index), activeId, ...section.ids.slice(index)] };
    return section;
  });
}

/** On the drop, inside one section: the achievement takes the place of the line it is over. */
export function moveWithin(sections: AchievementSection[], activeId: string, overId: string): AchievementSection[] {
  const index = sectionIndexOf(sections, activeId);
  if (index < 0 || index !== sectionIndexOf(sections, overId)) return sections;
  return sections.map((section, i) => {
    if (i !== index) return section;
    const from = section.ids.indexOf(activeId);
    const to = section.ids.indexOf(overId);
    return to < 0 || from === to ? section : { ...section, ids: arrayMove(section.ids, from, to) };
  });
}

/** The profile order: section after section. */
export function flattenSections(sections: AchievementSection[]): string[] {
  return sections.flatMap((s) => s.ids);
}

/** The achievements whose section is no longer their collection, with the one to save (null = « Autres succès »). */
export function collectionChanges(definitions: AchievementDefinition[], sections: AchievementSection[]): Record<string, string | null> {
  const current = new Map(definitions.map((d) => [d.id, d.collectionId ?? null]));
  const changes: Record<string, string | null> = {};
  for (const section of sections) {
    const collectionId = section.collection?.id ?? null;
    for (const id of section.ids) {
      if (current.has(id) && current.get(id) !== collectionId) changes[id] = collectionId;
    }
  }
  return changes;
}
