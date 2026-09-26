import { APWORLD_UPDATE_STATUSES, updateStatusLabel, updateStatusTone } from "./apworld-update-status";

/** Story 38.5: the catalogue and the game page say the same thing about an apworld's version. */
describe("apworld update status wording", () => {
  it("names every status the API can send", () => {
    expect(APWORLD_UPDATE_STATUSES).toEqual(["update_available", "up_to_date", "unknown", "undetermined", "not_tracked"]);
    for (const status of APWORLD_UPDATE_STATUSES) {
      expect(updateStatusLabel(status)).not.toBe("");
    }
  });

  it("says plainly when a version could not be read", () => {
    expect(updateStatusLabel("undetermined")).toBe("Version illisible");
    expect(updateStatusTone("undetermined")).toBe("warning");
  });

  it("never passes off an unexpected status as untracked", () => {
    // The catalogue badge used to fall back to "Non suivi" for anything it did not know.
    expect(updateStatusLabel("something_new")).toBe("Statut inconnu (something_new)");
    expect(updateStatusLabel("something_new")).not.toBe(updateStatusLabel("not_tracked"));
  });

  it("keeps the existing wording", () => {
    expect(updateStatusLabel("update_available")).toBe("Mise à jour disponible");
    expect(updateStatusLabel("up_to_date")).toBe("À jour");
    expect(updateStatusLabel("unknown")).toBe("Non vérifié");
    expect(updateStatusLabel("not_tracked")).toBe("Non suivi");
  });
});
