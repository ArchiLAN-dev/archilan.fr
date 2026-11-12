import { negotiateWhep, parseWhepTestUrls, STALL_TICKS, watchStall, whepHeaders, whepPathName } from "./whep-player";

describe("story 47.4 - WHEP player", () => {
  it("keeps up to nine http(s) WHEP URLs", () => {
    const params = new URLSearchParams();
    params.append("url", "https://media.lan.archilan.fr:8889/p-abc/whep");
    params.append("url", "javascript:alert(1)");
    params.append("url", "pas une url");
    for (let i = 0; i < 10; i += 1) {
      params.append("url", `http://192.168.1.10:8889/p-${i}/whep`);
    }

    const urls = parseWhepTestUrls(params);

    expect(urls).toHaveLength(9);
    expect(urls[0]).toBe("https://media.lan.archilan.fr:8889/p-abc/whep");
    expect(urls.some((url) => url.startsWith("javascript"))).toBe(false);
  });

  it("captions a player with its path", () => {
    expect(whepPathName("https://media.lan.archilan.fr:8889/p-abc/whep")).toBe("p-abc");
    expect(whepPathName("nope")).toBe("nope");
  });

  function fakePeer() {
    const calls: string[] = [];
    let remote: RTCSessionDescriptionInit | null = null;
    const peer = {
      addTransceiver: (kind: string) => {
        calls.push(`recv:${kind}`);
        return {} as RTCRtpTransceiver;
      },
      createOffer: async () => ({ type: "offer", sdp: "v=0 offer" }) as RTCSessionDescriptionInit,
      setLocalDescription: async () => undefined,
      setRemoteDescription: async (description: RTCSessionDescriptionInit) => {
        remote = description;
      },
      localDescription: { type: "offer", sdp: "v=0 offer" } as RTCSessionDescription,
      iceGatheringState: "complete" as RTCIceGatheringState,
      addEventListener: () => undefined,
      removeEventListener: () => undefined,
    };
    return { peer, calls, remote: () => remote };
  }

  it("posts the offer and applies the answer, keeping the session URL", async () => {
    const { peer, calls, remote } = fakePeer();
    const fetcher = jest.fn(async () => new Response("v=0 answer", { status: 201, headers: { Location: "/p-abc/whep/session-1" } }));

    const session = await negotiateWhep(peer, "https://media.lan.archilan.fr:8889/p-abc/whep", fetcher as unknown as typeof fetch);

    expect(calls).toEqual(["recv:video", "recv:audio"]);
    expect(fetcher).toHaveBeenCalledWith("https://media.lan.archilan.fr:8889/p-abc/whep", expect.objectContaining({ method: "POST", body: "v=0 offer" }));
    expect(remote()).toEqual({ type: "answer", sdp: "v=0 answer" });
    expect(session).toBe("https://media.lan.archilan.fr:8889/p-abc/whep/session-1");
  });

  it("sends the régie token as Bearer token (v0.66.1)", async () => {
    const { peer } = fakePeer();
    const fetcher = jest.fn(async () => new Response("v=0 answer", { status: 201 }));

    await negotiateWhep(peer, "https://media.lan.archilan.fr:8889/p-abc/whep", fetcher as unknown as typeof fetch, "d".repeat(32));

    expect(fetcher).toHaveBeenCalledWith(
      "https://media.lan.archilan.fr:8889/p-abc/whep",
      expect.objectContaining({ headers: { "Content-Type": "application/sdp", Authorization: `Bearer ${"d".repeat(32)}` } }),
    );
    expect(whepHeaders(null)).toEqual({});
  });

  it("fails on a refused or missing path", async () => {
    const { peer } = fakePeer();
    const fetcher = jest.fn(async () => new Response("path not found", { status: 404 }));

    await expect(negotiateWhep(peer, "https://media.lan.archilan.fr:8889/p-x/whep", fetcher as unknown as typeof fetch)).rejects.toThrow("whep_status_404");
  });

  it("counts the seconds without a new frame and starts over when frames come back", () => {
    let watch = watchStall({ framesDecoded: 0, stillTicks: 0 }, 30);
    expect(watch).toEqual({ framesDecoded: 30, stillTicks: 0 });

    for (let i = 0; i < STALL_TICKS; i += 1) {
      watch = watchStall(watch, 30);
    }
    expect(watch.stillTicks).toBe(STALL_TICKS);

    expect(watchStall(watch, 61)).toEqual({ framesDecoded: 61, stillTicks: 0 });
  });
});
