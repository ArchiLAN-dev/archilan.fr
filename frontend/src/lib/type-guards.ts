// No `as` casts - assertionStyle: "never" enforced by ESLint (see eslint.config.mjs).

export function hasStringProp<K extends string>(v: object, key: K): v is Record<K, string> {
  return key in v && typeof Reflect.get(v, key) === "string";
}

export function hasNumberProp<K extends string>(v: object, key: K): v is Record<K, number> {
  return key in v && typeof Reflect.get(v, key) === "number";
}

export function hasBooleanProp<K extends string>(v: object, key: K): v is Record<K, boolean> {
  return key in v && typeof Reflect.get(v, key) === "boolean";
}

export function hasNullableStringProp<K extends string>(v: object, key: K): v is Record<K, string | null> {
  if (!(key in v)) return false;
  const value = Reflect.get(v, key);
  return value === null || typeof value === "string";
}
