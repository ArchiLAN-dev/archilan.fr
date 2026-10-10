import { renderToStaticMarkup } from "react-dom/server";

import { FavoriteFriendButton } from "./favorite-friend-button";
import { FriendIdentity } from "./friend-identity";

jest.mock("./community-friends-api", () => ({
  favoriteFriend: jest.fn(),
  unfavoriteFriend: jest.fn(),
}));

describe("favorite friends (story 43.11a)", () => {
  it("marks a starred friend next to their name, and only them", () => {
    const card = { userId: "u-1", slug: "alice", displayName: "Alice", avatarUrl: null };
    expect(renderToStaticMarkup(<FriendIdentity card={{ ...card, isFavorite: true }} />)).toContain('aria-label="Favori"');
    expect(renderToStaticMarkup(<FriendIdentity card={card} />)).not.toContain("Favori");
  });

  it("says what the star will do", () => {
    const off = renderToStaticMarkup(<FavoriteFriendButton favorite={false} name="Alice" onChange={() => undefined} slug="alice" />);
    expect(off).toContain("Mettre en favori");
    expect(off).toContain('aria-pressed="false"');

    const on = renderToStaticMarkup(<FavoriteFriendButton compact favorite name="Alice" onChange={() => undefined} slug="alice" />);
    expect(on).toContain('aria-label="Retirer Alice des favoris"');
    expect(on).toContain('aria-pressed="true"');
  });
});
