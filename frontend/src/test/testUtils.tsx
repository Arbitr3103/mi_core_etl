/**
 * Test utilities for React component testing
 */

import React from "react";
import type { ReactElement } from "react";
import { render } from "@testing-library/react";
import type { RenderOptions } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { vi } from "vitest";

/**
 * Custom render function with providers
 */
const AllTheProviders: React.FC<{ children: React.ReactNode }> = ({
  children,
}) => {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: {
        retry: false,
        gcTime: 0,
      },
    },
  });

  return (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
};

const customRender = (
  ui: ReactElement,
  options?: Omit<RenderOptions, "wrapper">
) => render(ui, { wrapper: AllTheProviders, ...options });

export * from "@testing-library/react";
export { customRender as render };

/**
 * Helper to wait for async operations
 */
export const waitForAsync = () =>
  new Promise((resolve) => setTimeout(resolve, 0));

/**
 * Mock fetch response helper
 */
export const mockFetchResponse = (data: any, ok = true, status = 200) => {
  return Promise.resolve({
    ok,
    status,
    json: () => Promise.resolve(data),
    text: () => Promise.resolve(JSON.stringify(data)),
  } as Response);
};

/**
 * Helper to create mock event
 */
export const createMockEvent = (overrides = {}) => ({
  preventDefault: vi.fn(),
  stopPropagation: vi.fn(),
  target: { value: "" },
  ...overrides,
});

/**
 * Helper to simulate user typing with debounce
 */
export const simulateTypingWithDebounce = async (
  element: HTMLElement,
  text: string,
  delay = 300
) => {
  const { userEvent } = await import("@testing-library/user-event");
  const user = userEvent.setup();

  await user.clear(element);
  await user.type(element, text);

  // Wait for debounce
  await new Promise((resolve) => setTimeout(resolve, delay + 50));
};

/**
 * Helper to check if element has specific classes
 */
export const hasClasses = (element: HTMLElement, classes: string[]) => {
  return classes.every((className) => element.classList.contains(className));
};

/**
 * Helper to get element by test id with better error message
 */
export const getByTestId = (container: HTMLElement, testId: string) => {
  const element = container.querySelector(`[data-testid="${testId}"]`);
  if (!element) {
    throw new Error(`Element with data-testid="${testId}" not found`);
  }
  return element as HTMLElement;
};
