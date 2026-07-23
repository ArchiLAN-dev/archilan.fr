import { renderToStaticMarkup } from "react-dom/server";

import { Markdown } from "./markdown";
import { markdownToPlainText } from "./markdown-to-plain-text";

function render(node: React.ReactElement): string {
  return renderToStaticMarkup(node);
}

describe("Markdown - raw HTML is inert (story 10.10, AC 5)", () => {
  // The whole security posture of the feature: react-markdown emits React elements, so authored HTML
  // can never become live markup. These fields are community-authored and the project has no sanitiser.
  test("a script tag renders as escaped text, not as markup", () => {
    const html = render(<Markdown>{'<script>alert("xss")</script>'}</Markdown>);

    expect(html).not.toContain("<script>");
    expect(html).toContain("&lt;script&gt;");
  });

  test("an img with an onerror handler renders as escaped text", () => {
    const html = render(<Markdown>{'<img src=x onerror="alert(1)">'}</Markdown>);

    // The word "onerror" does survive - as displayed characters inside the escaped text, which is
    // harmless. What matters is that no real <img> element is emitted, so no handler can ever fire.
    expect(html).not.toContain("<img");
    expect(html).toContain("&lt;img");
    expect(html).toContain("onerror=&quot;");
  });

  test("an iframe renders as escaped text", () => {
    const html = render(<Markdown>{'<iframe src="https://evil.example"></iframe>'}</Markdown>);

    expect(html).not.toContain("<iframe");
    expect(html).toContain("&lt;iframe");
  });
});

describe("Markdown - supported marks", () => {
  test("renders bold, italic and code", () => {
    const html = render(<Markdown>{"**gras** *ital* `code`"}</Markdown>);

    expect(html).toContain("<strong");
    expect(html).toContain("<em");
    expect(html).toContain("<code");
  });

  test("renders lists and demotes headings to h3 or lower", () => {
    const html = render(<Markdown>{"# Titre\n\n- un\n- deux"}</Markdown>);

    expect(html).toContain("<ul");
    expect(html).toContain("<h3");
    expect(html).not.toContain("<h1");
  });

  test("keeps blank-line-separated paragraphs apart", () => {
    // Regression: `p` was flattened to a fragment in both variants, so consecutive paragraphs were
    // glued together and a multi-paragraph description lost all its structure on the public pages.
    const html = render(<Markdown>{"Para un.\n\nPara deux."}</Markdown>);

    expect(html).toContain("<p");
    expect(html.match(/<p/g)).toHaveLength(2);
  });

  test("keeps single newlines as line breaks (AC 6)", () => {
    // Bio, comments and tutorial steps render with whitespace-pre-line today; standard markdown
    // would collapse these into one paragraph and silently reflow every existing text.
    const html = render(<Markdown>{"ligne un\nligne deux"}</Markdown>);

    expect(html).toContain("<br");
  });
});

describe("Markdown - link policy", () => {
  test("untrusted links get nofollow and ugc", () => {
    const html = render(<Markdown untrusted>{"[lien](https://example.org)"}</Markdown>);

    expect(html).toContain('rel="nofollow ugc noopener noreferrer"');
    expect(html).toContain('target="_blank"');
  });

  test("trusted links stay plain but keep noopener", () => {
    const html = render(<Markdown>{"[lien](https://example.org)"}</Markdown>);

    expect(html).toContain('rel="noopener noreferrer"');
    expect(html).not.toContain("nofollow");
  });

  test("a javascript: href renders as text, never as a link", () => {
    const html = render(<Markdown>{"[clique](javascript:alert(1))"}</Markdown>);

    expect(html).not.toContain("<a");
    expect(html).not.toContain("javascript:");
    expect(html).toContain("clique");
  });
});

describe("Markdown - image policy", () => {
  test("untrusted content drops images entirely", () => {
    const html = render(<Markdown untrusted>{"![alt](https://example.org/a.png)"}</Markdown>);

    expect(html).not.toContain("<img");
  });

  test("trusted content keeps an https image", () => {
    const html = render(<Markdown>{"![alt](https://example.org/a.png)"}</Markdown>);

    expect(html).toContain("<img");
  });
});

describe("Markdown - inline variant", () => {
  test("flattens block structure for dense layouts", () => {
    const html = render(<Markdown inline>{"# Titre\n\n- un\n- deux"}</Markdown>);

    expect(html).not.toContain("<h3");
    expect(html).not.toContain("<ul");
    expect(html).toContain("Titre");
  });

  test("still renders inline marks", () => {
    const html = render(<Markdown inline>{"**gras**"}</Markdown>);

    expect(html).toContain("<strong");
  });
});

describe("Markdown - untrusted policy is one switch (task 3)", () => {
  // Bio, comments and contribution messages are written by members, not admins. Everything the
  // community-authored surfaces rely on has to hold from the single `untrusted` prop.
  test("untrusted content cannot inject markup, images, or a followed link", () => {
    const payload = '<script>alert(1)</script>\n\n![img](https://x.org/a.png)\n\n[l](https://x.org)';
    const html = render(<Markdown untrusted>{payload}</Markdown>);

    expect(html).not.toContain("<script>");
    expect(html).not.toContain("<img");
    expect(html).toContain('rel="nofollow ugc noopener noreferrer"');
  });
});

describe("Markdown - empty input", () => {
  test.each([null, undefined, "", "   "])("renders nothing for %p", (value) => {
    expect(render(<Markdown>{value}</Markdown>)).toBe("");
  });
});

describe("markdownToPlainText (AC 7)", () => {
  test("strips emphasis, headings, links and code", () => {
    const text = markdownToPlainText("## Titre\n\nUn **gras**, un *ital*, un [lien](https://x.org), `code`.");

    expect(text).toBe("Titre Un gras, un ital, un lien, code.");
  });

  test("keeps image alt text and drops the url", () => {
    expect(markdownToPlainText("![une affiche](https://x.org/a.png)")).toBe("une affiche");
  });

  test("strips list bullets and quotes", () => {
    expect(markdownToPlainText("- un\n- deux\n\n> cité")).toBe("un deux cité");
  });

  test("returns an empty string for nullish input", () => {
    expect(markdownToPlainText(null)).toBe("");
    expect(markdownToPlainText(undefined)).toBe("");
  });
});
