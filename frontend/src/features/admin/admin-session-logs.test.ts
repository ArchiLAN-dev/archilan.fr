import { http, HttpResponse } from "msw";
import { server } from "@/tests/setup";
import { TEST_API_BASE_URL } from "@/tests/constants";
import { fetchContainerLogs, forceLaunchSession } from "./admin-sessions-api";

describe("logs of a session container (story 17.33)", () => {
  it("asks for the container and reads its logs", async () => {
    let asked: string | null = null;
    server.use(
      http.get(`${TEST_API_BASE_URL}/admin/sessions/s1/logs`, ({ request }) => {
        asked = new URL(request.url).searchParams.get("container");
        return HttpResponse.json({ data: { container: "archilan-bridge-s1", found: true, logs: "ready\n", fetched_at: "2026-10-24T10:00:00+00:00" } });
      }),
    );

    const result = await fetchContainerLogs("s1", "bridge");

    expect(asked).toBe("bridge");
    expect(result).toEqual({ kind: "ok", data: { container: "archilan-bridge-s1", found: true, logs: "ready\n" } });
  });

  it("tells a container that does not exist", async () => {
    server.use(
      http.get(`${TEST_API_BASE_URL}/admin/sessions/s1/logs`, () =>
        HttpResponse.json({ data: { container: "archilan-gen-s1", found: false, logs: "" } }),
      ),
    );

    const result = await fetchContainerLogs("s1", "gen");

    expect(result.kind === "ok" ? result.data.found : null).toBe(false);
  });

  it("tells an orchestrator that cannot answer from any other failure", async () => {
    server.use(http.get(`${TEST_API_BASE_URL}/admin/sessions/s1/logs`, () => HttpResponse.json({}, { status: 503 })));
    expect(await fetchContainerLogs("s1", "ap")).toEqual({ kind: "unavailable" });

    server.use(http.get(`${TEST_API_BASE_URL}/admin/sessions/s1/logs`, () => HttpResponse.json({ data: { logs: "x" } })));
    expect(await fetchContainerLogs("s1", "ap")).toEqual({ kind: "error" });
  });

  it("reports what the forced launch answered", async () => {
    server.use(
      http.post(`${TEST_API_BASE_URL}/admin/sessions/s1/force-launch`, () =>
        HttpResponse.json({ error: { code: "invalid_transition", message: "Transition refusée." } }, { status: 409 }),
      ),
    );

    expect(await forceLaunchSession("s1")).toEqual({ success: false, output: "Transition refusée." });
  });
});
