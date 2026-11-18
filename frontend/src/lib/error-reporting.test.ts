import type { ErrorEvent } from "@sentry/nextjs";

import { errorReportingOptions, scrubEvent } from "./error-reporting";

describe("errorReportingOptions", () => {
  it("starts nothing without a DSN", () => {
    expect(errorReportingOptions("", "0.72.0", "production")).toBeNull();
    expect(errorReportingOptions("   ", "", "production")).toBeNull();
  });

  it("reports errors only, without personal data", () => {
    const options = errorReportingOptions("https://k@errors.archilan.fr/2", "0.72.0", "production");
    expect(options).toMatchObject({
      dsn: "https://k@errors.archilan.fr/2",
      release: "0.72.0",
      sendDefaultPii: false,
      tracesSampleRate: 0,
    });
    expect(errorReportingOptions("https://k@errors.archilan.fr/2", "", "production")?.release).toBeUndefined();
  });
});

describe("scrubEvent", () => {
  it("keeps the method and path of a request only", () => {
    const event = scrubEvent({
      type: undefined,
      request: {
        method: "GET",
        url: "https://archilan.fr/compte?token=abc",
        headers: { Cookie: "__Host-session=x" },
        cookies: { "__Host-session": "x" },
        data: { password: "hunter2" },
        query_string: "token=abc",
      },
    } as ErrorEvent);
    expect(event.request).toEqual({ method: "GET", url: "https://archilan.fr/compte" });
  });

  it("masks e-mail addresses and keeps only the user id", () => {
    const event = scrubEvent({
      type: undefined,
      message: "no account for alice@example.org",
      exception: { values: [{ type: "Error", value: "send to bob@example.org failed" }] },
      breadcrumbs: [{ message: "typed carol@example.org" }],
      user: { id: "u1", email: "dave@example.org", ip_address: "1.2.3.4" },
    } as ErrorEvent);
    expect(event.message).toBe("no account for [email]");
    expect(event.exception?.values?.[0]?.value).toBe("send to [email] failed");
    expect(event.breadcrumbs?.[0]?.message).toBe("typed [email]");
    expect(event.user).toEqual({ id: "u1" });
  });
});

describe("breadcrumbs and ignored errors", () => {
  it("keeps only the path and status of a request breadcrumb, and drops console arguments", () => {
    const event = scrubEvent({
      type: undefined,
      breadcrumbs: [
        { category: "fetch", data: { method: "POST", url: "https://api.archilan.fr/auth/reset?token=abc", status_code: 500 } },
        { category: "navigation", data: { from: "/compte?token=abc", to: "/confirm?email=eve@example.org" } },
        { category: "console", data: { arguments: ["secret", { token: "abc" }], logger: "console" } },
      ],
    } as ErrorEvent);
    expect(event.breadcrumbs?.[0]?.data).toEqual({ method: "POST", url: "https://api.archilan.fr/auth/reset", status_code: 500 });
    expect(event.breadcrumbs?.[1]?.data).toEqual({ from: "/compte", to: "/confirm" });
    expect(event.breadcrumbs?.[2]?.data).toBeUndefined();
  });

  it("ignores a dropped connection but not a chunk that failed to load", () => {
    const ignored = errorReportingOptions("https://k@errors.archilan.fr/2", "", "production")?.ignoreErrors ?? [];
    const isIgnored = (message: string): boolean =>
      ignored.some((pattern) => (typeof pattern === "string" ? message.includes(pattern) : pattern.test(message)));
    expect(isIgnored("Failed to fetch")).toBe(true);
    expect(isIgnored("TypeError: Load failed")).toBe(true);
    expect(isIgnored("Failed to fetch dynamically imported module: https://archilan.fr/_next/static/chunks/x.js")).toBe(false);
  });
});
