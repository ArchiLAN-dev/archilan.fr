import { QueryObserver } from "@tanstack/react-query";

import { DEFAULT_STALE_TIME, makeQueryClient } from "./query-client";

/** Mounts one observer of the query, waits for its fetch to settle, then unmounts it. */
async function mountOnce(client: ReturnType<typeof makeQueryClient>, queryFn: () => Promise<unknown>): Promise<void> {
  const observer = new QueryObserver(client, { queryKey: ["probe"], queryFn, staleTime: DEFAULT_STALE_TIME });
  const unsubscribe = observer.subscribe(() => {});
  await new Promise((resolve) => setTimeout(resolve, 0));
  await client.getQueryCache().find({ queryKey: ["probe"] })?.promise;
  unsubscribe();
}

/** Story 33.27: a failed read (`null`, AC-API2) does not stick for the whole staleTime. */
describe("makeQueryClient", () => {
  test("a cached failure is fetched again on the next mount; a cached success is not", async () => {
    const client = makeQueryClient();
    let calls = 0;
    const queryFn = async () => {
      calls += 1;
      return calls === 1 ? null : { ok: true };
    };

    await mountOnce(client, queryFn);
    expect(calls).toBe(1);
    expect(client.getQueryData(["probe"])).toBeNull();

    await mountOnce(client, queryFn);
    expect(calls).toBe(2);
    expect(client.getQueryData(["probe"])).toEqual({ ok: true });

    // Fresh success: the staleTime holds as before.
    await mountOnce(client, queryFn);
    expect(calls).toBe(2);

    // Drops the cache and its 5-minute gc timers, which would otherwise keep jest alive.
    client.clear();
  });
});
