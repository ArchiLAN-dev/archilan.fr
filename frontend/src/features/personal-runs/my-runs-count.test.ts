import { isActive } from "@/components/public-shell";
import { liveRunCount } from "./my-runs-count";
import type { PersonalRun, PersonalRunStatus } from "./types";

function run(id: string, status: PersonalRunStatus, archived = false): PersonalRun {
  return { id, status, archived } as unknown as PersonalRun;
}

/** Story 43.21: « Mes parties » in the site header. */
describe("my runs in the header", () => {
  test("counts the runs still going, owned or joined, once each", () => {
    expect(
      liveRunCount({
        owned: [run("a", "active"), run("b", "draft"), run("c", "completed"), run("d", "idle", true)],
        joined: [run("e", "starting"), run("f", "cancelled"), run("a", "active")],
      }),
    ).toBe(3);
    expect(liveRunCount({ owned: [], joined: [] })).toBe(0);
  });

  test("the entry lights on the runs pages and on the member space's list", () => {
    for (const path of ["/runs", "/runs/abc", "/compte/parties"]) expect(isActive(path, "/runs", ["/compte/parties"])).toBe(true);
    expect(isActive("/runs-hebdo", "/runs", ["/compte/parties"])).toBe(false);
  });
});
