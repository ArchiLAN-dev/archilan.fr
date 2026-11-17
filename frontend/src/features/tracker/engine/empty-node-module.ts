/**
 * Stands for Node's `module` in the browser bundle (story 54.4). wasmoon's Emscripten glue imports it only on its
 * Node branch, which never runs in a Worker; the bundler still has to resolve the import.
 */
export {};
