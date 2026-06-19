import { serializeStepsForSave, type InstallStep } from "./install-steps-editor";

describe("serializeStepsForSave", () => {
  it("drops the transient preview URL when a step has an uploaded imageKey", () => {
    const steps: InstallStep[] = [
      {
        type: "note",
        title: "Étape",
        description: "",
        links: [],
        imageKey: "tutorials/abc.png",
        imageUrl: "http://minio.test/media/tutorials/abc.png?sig=1",
      },
    ];

    const [step] = serializeStepsForSave(steps);
    expect(step.imageKey).toBe("tutorials/abc.png");
    expect(step.imageUrl).toBeNull();
  });

  it("keeps an external imageUrl when there is no imageKey", () => {
    const steps: InstallStep[] = [
      { type: "note", title: "Étape", description: "", links: [], imageUrl: "https://example.org/a.png" },
    ];

    const [step] = serializeStepsForSave(steps);
    expect(step.imageKey).toBeNull();
    expect(step.imageUrl).toBe("https://example.org/a.png");
  });
});
