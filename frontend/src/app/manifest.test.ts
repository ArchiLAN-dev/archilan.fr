import manifest from "./manifest";

/** Story 40.4: the site installs like an app, which iOS requires for push notifications. */
describe("web app manifest", () => {
  test("opens full screen from the home screen, with PNG icons including a maskable one", () => {
    const app = manifest();

    expect(app.display).toBe("standalone");
    expect(app.start_url).toBe("/");
    expect(app.icons?.map((icon) => icon.sizes)).toEqual(["192x192", "512x512", "512x512"]);
    expect(app.icons?.every((icon) => icon.type === "image/png")).toBe(true);
    expect(app.icons?.some((icon) => icon.purpose === "maskable")).toBe(true);
  });
});
