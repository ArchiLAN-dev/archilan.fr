"use client";

import { createContext, useContext } from "react";

import { LocationAutocompleteInput } from "@/features/events/location-autocomplete-input";
import { isKnownItemName, type ItemCatalog } from "@/lib/item-catalog";

/**
 * Story 9.58: the game's items, for every item field of the YAML editor however deep it sits (lists, dict keys,
 * plando blocks, item links). Null when the game has none introspected.
 */
export const ItemCatalogContext = createContext<ItemCatalog | null>(null);

type Props = {
  value: string;
  onChange: (value: string) => void;
  disabled?: boolean;
  placeholder?: string;
};

/**
 * A free-text item name with the game's items and groups as suggestions. A name the game does not declare is
 * flagged, not refused: a world may create items from its options.
 */
export function ItemNameInput({ value, onChange, disabled = false, placeholder = "Nom de l'item" }: Props) {
  const catalog = useContext(ItemCatalogContext);
  const known = isKnownItemName(catalog, value);

  return (
    <div className="grid min-w-0 flex-1 gap-1">
      <LocationAutocompleteInput
        annotations={catalog?.annotations}
        disabled={disabled}
        placeholder={placeholder}
        suggestions={catalog?.suggestions ?? null}
        value={value}
        onChange={onChange}
      />
      {known ? null : <p className="text-xs text-warning">Inconnu de ce jeu : vérifie l&apos;orthographe.</p>}
    </div>
  );
}
