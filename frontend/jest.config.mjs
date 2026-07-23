import nextJest from "next/jest.js";

const createJestConfig = nextJest({ dir: "./" });

/**
 * Packages Jest must transform instead of skipping as node_modules.
 *
 * `transformIgnorePatterns` entries are OR-ed, so a pattern added *after* the one next/jest derives
 * from `transpilePackages` can never re-enable a package that one already ignores. The resolved array
 * is therefore replaced below rather than extended, and this list has to cover both:
 *   - what next/jest needs (mirror of `transpilePackages` in next.config.ts, plus its own internals);
 *   - the ESM-only markdown chain pulled in by react-markdown (story 10.10), which is ~80 packages and
 *     far too many to enumerate in `transpilePackages`, hence the prefix patterns.
 *
 * Entries are regex fragments matched against the `.pnpm/<name>@` path segment (pnpm flattens a
 * scope's `/` to `+`).
 */
const TRANSFORMED_PACKAGES = [
  // Mirror of transpilePackages in next.config.ts + next/jest internals.
  "msw",
  "rettime",
  "until-async",
  "@open-draft\\+.*",
  "geist",
  "next\\+.*",
  // ESM-only markdown chain.
  "react-markdown",
  "remark-.*",
  "rehype-.*",
  "unified",
  "unist-.*",
  "mdast-util-.*",
  "micromark.*",
  "hast-util-.*",
  "vfile.*",
  "property-information",
  "space-separated-tokens",
  "comma-separated-tokens",
  "decode-named-character-reference",
  "character-entities.*",
  "html-url-attributes",
  "html-void-elements",
  "stringify-entities",
  "estree-util-.*",
  "markdown-table",
  "longest-streak",
  "escape-string-regexp",
  "is-plain-obj",
  "trim-lines",
  "devlop",
  "trough",
  "zwitch",
  "bail",
  "ccount",
];

/** @type {import('jest').Config} */
const config = {
  testEnvironment: "node",
  setupFilesAfterEnv: ["<rootDir>/jest.setup.ts"],
};

const withNextConfig = createJestConfig(config);

const buildConfig = async () => {
  const resolved = await withNextConfig();

  return {
    ...resolved,
    transformIgnorePatterns: [
      // Separator class matters: on Windows these paths arrive with backslashes.
      `node_modules[\\\\/]\\.pnpm[\\\\/](?!(${TRANSFORMED_PACKAGES.join("|")})@)`,
      "^.+\\.module\\.(css|sass|scss)$",
    ],
  };
};

export default buildConfig;
