import { renderToStaticMarkup } from "react-dom/server";

import type { ParticipantStream } from "./participant-streams-api";
import { ParticipantStreamsMosaic, reconcileMosaic, toggleInMosaic } from "./participant-streams-mosaic";

function live(login: string, displayName: string | null = null): ParticipantStream {
  return { userId: `u-${login}`, slug: `slug-${login}`, displayName, twitchLogin: login, avatarUrl: null, live: true, viewerCount: 10 };
}

/** Story 47.3: several lives side by side on the public streams page. */
describe("participant streams mosaic", () => {
  it("starts with the first four lives, then keeps the picks still live", () => {
    expect(reconcileMosaic(null, ["a", "b", "c", "d", "e"])).toEqual(["a", "b", "c", "d"]);
    expect(reconcileMosaic(["e", "b", "z"], ["a", "b", "e"])).toEqual(["e", "b"]);
    expect(reconcileMosaic([], ["a", "b"])).toEqual([]);
  });

  it("adds or removes a live, the oldest leaving a full mosaic", () => {
    expect(toggleInMosaic(["a", "b"], "c")).toEqual(["a", "b", "c"]);
    expect(toggleInMosaic(["a", "b", "c"], "b")).toEqual(["a", "c"]);
    expect(toggleInMosaic(["a", "b", "c", "d"], "e")).toEqual(["b", "c", "d", "e"]);
  });

  it("shows the lives side by side, without sound", () => {
    expect(renderToStaticMarkup(<ParticipantStreamsMosaic focused={null} onFocus={() => undefined} streams={[]} />)).toBe("");

    const html = renderToStaticMarkup(<ParticipantStreamsMosaic focused={null} onFocus={() => undefined} streams={[live("alice", "Alice"), live("bob")]} />);
    expect(html).toContain("grid-cols-2");
    expect(html).not.toContain("Avec le son");
    expect(html).toContain("Mettre Alice en grand avec le son");
    expect(html).toContain("Mettre slug-bob en grand avec le son");
  });

  it("puts one stream in big with its sound, the others as muted thumbnails", () => {
    const html = renderToStaticMarkup(
      <ParticipantStreamsMosaic focused="bob" onFocus={() => undefined} streams={[live("alice", "Alice"), live("bob", "Bob"), live("carol", "Carol")]} />,
    );
    // Bob in big with the sound badge, Alice and Carol as thumbnails (Bob is no thumbnail).
    expect(html.match(/Avec le son/g)).toHaveLength(1);
    expect(html).not.toContain("Mettre Bob en grand");
    expect(html).toContain("Mettre Alice en grand avec le son");
    expect(html).toContain("Mettre Carol en grand avec le son");
    expect(html).toContain("Revenir à la mosaïque");
    expect(html).toContain("grid-cols-3");
  });
});
