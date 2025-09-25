// Jest setup file for DOM environment

// Mock performance API if not available
if (typeof performance === "undefined") {
  global.performance = {
    now: () => Date.now(),
  };
}

// Mock AbortSignal if not available
if (typeof AbortSignal === "undefined") {
  global.AbortSignal = {
    timeout: jest.fn().mockReturnValue({ aborted: false }),
  };
}

// Mock navigator if not available
if (typeof navigator === "undefined") {
  global.navigator = {
    userAgent: "Mozilla/5.0 (Test Environment)",
    maxTouchPoints: 0,
  };
}

// Mock window if not available
if (typeof window === "undefined") {
  global.window = {
    innerWidth: 1024,
    innerHeight: 768,
    location: {
      origin: "http://localhost",
    },
  };
}
