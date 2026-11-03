import { renderToStaticMarkup } from "react-dom/server";
import { ConfigureSlotYamlLink, resolveSlotYamlStatus, SlotYamlStatusBadge } from "./slot-yaml-status";

const game = { isApworldReady: true, defaultYaml: "name: Player\ngame: Celeste\n" };

describe("slot yaml status (story 16.26)", () => {
  it("does not take the game's default yaml, copied into a new slot, for a configured one", () => {
    expect(resolveSlotYamlStatus({ playerYaml: game.defaultYaml }, game)).toBe("default");
    expect(resolveSlotYamlStatus({ playerYaml: null }, game)).toBe("default");
    expect(resolveSlotYamlStatus({ playerYaml: "" }, game)).toBe("default");
  });

  it("is custom only once the player changed the options", () => {
    expect(resolveSlotYamlStatus({ playerYaml: "name: Player\ngame: Celeste\ngoal: 2\n" }, game)).toBe("custom");
  });

  it("asks for a configuration when the game has no default, and nothing when its apworld is not ready", () => {
    expect(resolveSlotYamlStatus({ playerYaml: null }, { isApworldReady: true, defaultYaml: null })).toBe("required");
    expect(resolveSlotYamlStatus({ playerYaml: "x" }, { isApworldReady: false, defaultYaml: null })).toBe("none");
    expect(resolveSlotYamlStatus({ playerYaml: "x" }, undefined)).toBe("none");
  });

  it("shows the status as plain text and the action as a button", () => {
    expect(renderToStaticMarkup(<SlotYamlStatusBadge status="default" />)).toContain("Options par défaut");
    expect(renderToStaticMarkup(<SlotYamlStatusBadge status="custom" />)).toContain("Personnalisé");
    expect(renderToStaticMarkup(<SlotYamlStatusBadge status="required" />)).toContain("À configurer");
    const link = renderToStaticMarkup(<ConfigureSlotYamlLink href="/runs/r1/slots/s1" label="Celeste" />);
    expect(link).toContain('href="/runs/r1/slots/s1"');
    expect(link).toContain("Configurer");
    expect(link).toContain('aria-label="Configurer les options de Celeste"');
  });
});
