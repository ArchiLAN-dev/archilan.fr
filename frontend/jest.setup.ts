import { TEST_API_BASE_URL } from "./src/tests/constants";

process.env.NEXT_PUBLIC_API_BASE_URL = TEST_API_BASE_URL;

import { setupServer } from "msw/node";

export const server = setupServer();

beforeAll(() => server.listen({ onUnhandledRequest: "error" }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());
