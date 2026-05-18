import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";

const eslintConfig = defineConfig([
  ...nextVitals,
  ...nextTs,
  // Override default ignores of eslint-config-next.
  globalIgnores([
    // Default ignores of eslint-config-next:
    ".next/**",
    "out/**",
    "build/**",
    "next-env.d.ts",
  ]),
  {
    // AC-TS3: ban `as` type assertions at API parse sites and in the guard helpers.
    files: ["src/features/**/*-api.ts", "src/lib/type-guards.ts"],
    rules: {
      "@typescript-eslint/consistent-type-assertions": ["error", { assertionStyle: "never" }],
    },
  },
  {
    // AC2 (20.7): constants.ts must have no imports - it is loaded before process.env is set.
    files: ["src/tests/constants.ts"],
    rules: {
      "no-restricted-syntax": [
        "error",
        {
          selector: "ImportDeclaration",
          message:
            "constants.ts must have no imports - it is evaluated before process.env is set in setup.ts.",
        },
      ],
    },
  },
  {
    // AC-ENV1: ban process.env outside the canonical accessor module.
    // next.config.ts and build files outside src/ legitimately use process.env.
    files: ["src/**/*.{ts,tsx}"],
    // env.ts owns process.env; test files use process.env for MSW base URL setup
    ignores: ["src/lib/env.ts", "**/*.test.ts", "**/*.test.tsx"],
    rules: {
      "no-restricted-syntax": [
        "error",
        {
          // dot access: process.env.FOO
          selector: "MemberExpression[object.name='process'][property.name='env']",
          message: "Use src/lib/env.ts instead of process.env directly (AC-ENV1).",
        },
        {
          // computed access: process["env"].FOO
          selector:
            "MemberExpression[object.name='process'][computed=true][property.value='env']",
          message: "Use src/lib/env.ts instead of process[\"env\"] directly (AC-ENV1).",
        },
        {
          // destructuring: const/let/var { FOO } = process.env
          selector:
            "VariableDeclarator[init.object.name='process'][init.property.name='env']",
          message: "Use src/lib/env.ts instead of destructuring process.env (AC-ENV1).",
        },
      ],
    },
  },
]);

export default eslintConfig;
