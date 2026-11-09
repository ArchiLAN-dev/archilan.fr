import { renderToStaticMarkup } from "react-dom/server";

import { RegistrationStepper } from "@/features/events/registration-stepper";
import { afterGamesHref, answerLabel, SurveyRecap } from "./registration-questions";
import type { MySurvey, SurveyForm } from "./surveys-api";

jest.mock("next/navigation", () => ({ useRouter: () => ({ push: jest.fn(), replace: jest.fn() }) }));

const survey: MySurvey = {
  id: "s1",
  title: "Ton arrivée",
  audience: "registration",
  anonymous: false,
  closesAt: "2027-06-01T00:00:00+00:00",
  answered: true,
};

const form: SurveyForm = {
  id: "s1",
  eventId: "e1",
  title: "Ton arrivée",
  intro: "",
  anonymous: false,
  closesAt: "2027-06-01T00:00:00+00:00",
  answered: true,
  questions: [
    { id: "q1", kind: "single", label: "Quel jour ?", required: true, options: [{ id: "o1", label: "Samedi" }, { id: "o2", label: "Dimanche" }] },
    { id: "q2", kind: "text", label: "Un mot ?", required: false, options: [] },
  ],
  myAnswers: [{ questionId: "q1", optionIds: ["o1"], text: null, rating: null }],
};

describe("story 51.5 - questions at registration", () => {
  it("adds the Questions step only when the event asks some", () => {
    const plain = renderToStaticMarkup(<RegistrationStepper currentStep={2} />);
    expect(plain).not.toContain("Questions");

    const withQuestions = renderToStaticMarkup(<RegistrationStepper currentStep={3} withQuestions />);
    expect(withQuestions).toContain("Questions");
    expect(withQuestions.indexOf("Questions")).toBeLessThan(withQuestions.indexOf("Récapitulatif"));
    expect(withQuestions.match(/aria-current="step"/g)).toHaveLength(1);
  });

  it("leads from the games to the questions, or straight to the recap", () => {
    expect(afterGamesHref("e1", "r1", [survey])).toBe("/evenements/e1/inscription/r1/questions");
    expect(afterGamesHref("e1", "r1", [])).toBe("/evenements/e1/inscription/r1/recap");
    expect(afterGamesHref("e1", "r1", undefined)).toBe("/evenements/e1/inscription/r1/recap");
  });

  it("shows the answers on the recap with a way to change them", () => {
    const html = renderToStaticMarkup(<SurveyRecap editHref="/evenements/e1/sondages/s1" form={form} survey={survey} />);
    expect(html).toContain("Quel jour ?");
    expect(html).toContain("Samedi");
    expect(html).not.toContain("Dimanche");
    expect(html).toContain("Modifier mes réponses");
  });

  it("flags a survey not answered yet", () => {
    const html = renderToStaticMarkup(
      <SurveyRecap editHref="/evenements/e1/inscription/r1/questions" form={null} survey={{ ...survey, answered: false }} />,
    );
    expect(html).toContain("Pas encore répondu.");
    expect(html).toContain("Répondre");
  });

  it("puts each kind of answer in words", () => {
    const options = [{ id: "o1", label: "Samedi" }];
    expect(answerLabel(options, undefined)).toBe("-");
    expect(answerLabel(options, { questionId: "q", optionIds: ["o1"], text: null, rating: null })).toBe("Samedi");
    expect(answerLabel([], { questionId: "q", optionIds: [], text: "Tard", rating: null })).toBe("Tard");
    expect(answerLabel([], { questionId: "q", optionIds: [], text: null, rating: 4 })).toBe("4 / 5");
  });
});
