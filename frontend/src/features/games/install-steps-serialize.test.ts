import { serializeStepsForSave, type InstallStep } from "./install-steps-editor";

describe("serializeStepsForSave", () => {
  // Until story 31.11 this stripped the transient presigned preview URL off an uploaded image. A step
  // no longer has a link list or an image field - both live in the markdown description - so the only
  // thing left to guarantee is that saving does not alter what the author typed.
  it("passes the authored step through untouched", () => {
    const steps: InstallStep[] = [
      {
        type: "apworld",
        title: "Installer l'apworld",
        description:
          "Télécharge-le puis :\n\n![](https://api.test/api/v1/tutorial-images/abc.png)\n\n- [Source](https://example.org)",
        videoUrl: "https://www.youtube.com/watch?v=dQw4w9WgXcQ",
      },
    ];

    expect(serializeStepsForSave(steps)).toEqual(steps);
  });

  it("returns a new array rather than mutating the input", () => {
    const steps: InstallStep[] = [{ type: "note", title: "Étape", description: "" }];

    const result = serializeStepsForSave(steps);

    expect(result).not.toBe(steps);
    expect(result[0]).not.toBe(steps[0]);
  });
});
