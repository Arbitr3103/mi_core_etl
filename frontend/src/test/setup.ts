/**
 * Test setup file for Vitest
 *
 * Configures testing environment and global test utilities
 */

import "@testing-library/jest-dom";
import { vi } from "vitest";

// Mock IntersectionObserver for virtual scrolling tests
global.IntersectionObserver = class IntersectionObserver {
  constructor() {}
  disconnect() {}
  observe() {}
  unobserve() {}
};

// Mock ResizeObserver for responsive components
global.ResizeObserver = class ResizeObserver {
  constructor() {}
  disconnect() {}
  observe() {}
  unobserve() {}
};

// Mock window.matchMedia for responsive tests
Object.defineProperty(window, "matchMedia", {
  writable: true,
  value: vi.fn().mockImplementation((query) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: vi.fn(), // deprecated
    removeListener: vi.fn(), // deprecated
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  })),
});

// Mock URL.createObjectURL for file download tests
global.URL.createObjectURL = vi.fn(() => "mocked-url");
global.URL.revokeObjectURL = vi.fn();

// Mock fetch for API tests
global.fetch = vi.fn();

// Suppress console warnings in tests
const originalConsoleWarn = console.warn;
console.warn = (...args) => {
  // Suppress specific React warnings that are expected in tests
  if (
    typeof args[0] === "string" &&
    (args[0].includes("Warning: ReactDOM.render is no longer supported") ||
      args[0].includes("Warning: An invalid form control"))
  ) {
    return;
  }
  originalConsoleWarn(...args);
};
