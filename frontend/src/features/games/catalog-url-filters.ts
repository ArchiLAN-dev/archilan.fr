import type { AvailabilityFilter, SortOrder } from "./games-filter";

/** The filter state the catalog carries in the URL (story 28.12). */
export type CatalogFilters = {
  query: string;
  availability: AvailabilityFilter;
  ownedOnly: boolean;
  sort: SortOrder;
  categories: string[];
};

export const DEFAULT_FILTERS: CatalogFilters = {
  availability: "all",
  categories: [],
  ownedOnly: false,
  query: "",
  sort: "name-asc",
};

const AVAILABILITIES: readonly AvailabilityFilter[] = ["all", "available", "experimental"];
const SORTS: readonly SortOrder[] = ["name-asc", "name-desc"];

/** A minimal read view of URLSearchParams - what Next's useSearchParams provides. */
type ReadableParams = {
  get(name: string): string | null;
  getAll(name: string): string[];
};

/**
 * Reads the filters out of the URL, defensively: an unknown or forged value falls back to the
 * default rather than producing a state the UI cannot represent (story 28.12, AC6).
 */
export function readFiltersFromParams(params: ReadableParams): CatalogFilters {
  const availability = params.get("dispo");
  const sort = params.get("tri");

  return {
    availability: isAvailability(availability) ? availability : DEFAULT_FILTERS.availability,
    categories: params.getAll("cat").filter((value) => "" !== value),
    ownedOnly: "1" === params.get("mes-jeux"),
    query: params.get("q") ?? DEFAULT_FILTERS.query,
    sort: isSort(sort) ? sort : DEFAULT_FILTERS.sort,
  };
}

/**
 * The query string for a filter state - **empty when nothing is filtered**, so a pristine `/jeux`
 * never grows a trailing `?`. The page is served from the ISR cache under a canonical URL; letting
 * every visit mint a distinct one would be a needless indexing problem (AC3).
 */
export function filtersToQueryString(filters: CatalogFilters): string {
  const params = new URLSearchParams();

  const query = filters.query.trim();
  if ("" !== query) params.set("q", query);
  if (DEFAULT_FILTERS.availability !== filters.availability) params.set("dispo", filters.availability);
  if (filters.ownedOnly) params.set("mes-jeux", "1");
  if (DEFAULT_FILTERS.sort !== filters.sort) params.set("tri", filters.sort);
  for (const category of filters.categories) params.append("cat", category);

  return params.toString();
}

function isAvailability(value: string | null): value is AvailabilityFilter {
  return null !== value && AVAILABILITIES.some((candidate) => candidate === value);
}

function isSort(value: string | null): value is SortOrder {
  return null !== value && SORTS.some((candidate) => candidate === value);
}
