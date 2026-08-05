import type { AvailabilityFilter, ListFilter, SortOrder } from "./games-filter";

/** The filter state the catalog carries in the URL (story 28.12). */
export type CatalogFilters = {
  query: string;
  availability: AvailabilityFilter;
  list: ListFilter;
  sort: SortOrder;
  categories: string[];
};

export const DEFAULT_FILTERS: CatalogFilters = {
  availability: "all",
  categories: [],
  list: "all",
  query: "",
  sort: "name-asc",
};

const AVAILABILITIES: readonly AvailabilityFilter[] = ["all", "available", "experimental"];
const SORTS: readonly SortOrder[] = ["name-asc", "name-desc"];

/** French slugs for the list filter - the URL is user-facing, like `dispo` and `tri` (story 28.14). */
const LIST_SLUGS: Record<Exclude<ListFilter, "all">, string> = {
  owned: "mes-jeux",
  planned: "a-essayer",
};

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
    list: readListFilter(params),
    query: params.get("q") ?? DEFAULT_FILTERS.query,
    sort: isSort(sort) ? sort : DEFAULT_FILTERS.sort,
  };
}

/**
 * The list filter, still honouring the `mes-jeux=1` flag story 28.12 used to write. Reading it costs
 * a line and keeps every URL shared back then landing on the same list; writing it too would have
 * frozen two spellings of one state, so 28.14 only writes `liste=`.
 */
function readListFilter(params: ReadableParams): ListFilter {
  const value = params.get("liste");
  if (LIST_SLUGS.owned === value) return "owned";
  if (LIST_SLUGS.planned === value) return "planned";
  if ("1" === params.get("mes-jeux")) return "owned";

  return DEFAULT_FILTERS.list;
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
  if ("all" !== filters.list) params.set("liste", LIST_SLUGS[filters.list]);
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
