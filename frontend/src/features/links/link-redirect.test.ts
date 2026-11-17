import { isRobot, linkRedirect, redirectUrl, referrerHost, shouldCount } from "./link-redirect";

/** Story 55.1: `/l/{code}`. */
const APP = "https://archilan.fr";
const BROWSER = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36";

function request(method = "GET", headers: Record<string, string> = {}): Request {
  return new Request("http://frontend:3000/l/discord-nov", { method, headers: { "user-agent": BROWSER, ...headers } });
}

describe("linkRedirect", () => {
  it("counts a browser's visit with the host it came from, then sends to the page", async () => {
    const record = jest.fn(async () => "/evenements/archilan-4");
    const response = await linkRedirect(request("GET", { referer: "https://www.discord.com/channels/1" }), "discord-nov", APP, record);

    expect(record).toHaveBeenCalledWith("discord-nov", "www.discord.com", true);
    expect(response.status).toBe(302);
    expect(response.headers.get("location")).toBe("https://archilan.fr/evenements/archilan-4");
    expect(response.headers.get("cache-control")).toBe("no-store");
  });

  it("sends to an outbound target", async () => {
    const response = await linkRedirect(request(), "ludo", APP, async () => "https://ludo.example/jeux?x=1");
    expect(response.headers.get("location")).toBe("https://ludo.example/jeux?x=1");
  });

  it.each([
    ["Discord's preview", request("GET", { "user-agent": "Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)" })],
    ["a HEAD", request("HEAD")],
    ["a prefetch", request("GET", { "sec-purpose": "prefetch;prerender" })],
    ["no user agent", new Request("http://frontend:3000/l/x")],
  ])("redirects %s without counting it", async (_name, req) => {
    const record = jest.fn(async () => "/");
    await linkRedirect(req, "discord-nov", APP, record);
    expect(record).toHaveBeenCalledWith("discord-nov", "", false);
  });

  it("goes home when the link is unknown, turned off or the API is down", async () => {
    const response = await linkRedirect(request(), "nope", APP, async () => null);
    expect(response.headers.get("location")).toBe("https://archilan.fr/");
  });
});

describe("redirectUrl", () => {
  it.each(["//evil.example", "/\\evil.example", "http://evil.example", "javascript:alert(1)", "evenements"])("never leaves for %s", (target) => {
    expect(redirectUrl(target, APP)).toBe("https://archilan.fr/");
  });
});

describe("helpers", () => {
  it("reads the referrer host, or nothing", () => {
    expect(referrerHost("https://t.co/abc")).toBe("t.co");
    expect(referrerHost(null)).toBe("");
    expect(referrerHost("not a url")).toBe("");
  });

  it("tells robots from browsers", () => {
    expect(isRobot(BROWSER)).toBe(false);
    for (const agent of ["Twitterbot/1.0", "facebookexternalhit/1.1", "WhatsApp/2.23", "Googlebot/2.1", "TelegramBot (like TwitterBot)", "Slackbot-LinkExpanding 1.0"]) {
      expect(isRobot(agent)).toBe(true);
    }
  });

  it("counts only a GET", () => {
    expect(shouldCount("GET", new Headers({ "user-agent": BROWSER }))).toBe(true);
    expect(shouldCount("POST", new Headers({ "user-agent": BROWSER }))).toBe(false);
  });
});
