import { describe, it, expect, beforeEach, vi } from "vitest";
import { renderHook, act } from "@testing-library/react";
import { useLocalStorage } from "../useLocalStorage";

describe("useLocalStorage", () => {
  beforeEach(() => {
    localStorage.clear();
    vi.clearAllMocks();
  });

  it("returns initial value when no stored value exists", () => {
    const { result } = renderHook(() =>
      useLocalStorage("test-key", "initial-value")
    );

    expect(result.current[0]).toBe("initial-value");
  });

  it("stores and retrieves value from localStorage", () => {
    const { result } = renderHook(() =>
      useLocalStorage("test-key", "initial-value")
    );

    act(() => {
      result.current[1]("new-value");
    });

    expect(result.current[0]).toBe("new-value");
    expect(localStorage.getItem("test-key")).toBe(JSON.stringify("new-value"));
  });

  it("retrieves existing value from localStorage on mount", () => {
    localStorage.setItem("test-key", JSON.stringify("stored-value"));

    const { result } = renderHook(() =>
      useLocalStorage("test-key", "initial-value")
    );

    expect(result.current[0]).toBe("stored-value");
  });

  it("handles complex objects", () => {
    const complexObject = { name: "test", count: 42, nested: { value: true } };

    const { result } = renderHook(() =>
      useLocalStorage("test-key", complexObject)
    );

    act(() => {
      result.current[1]({ ...complexObject, count: 100 });
    });

    expect(result.current[0]).toEqual({ ...complexObject, count: 100 });
  });

  it("handles localStorage errors gracefully", () => {
    const consoleWarnSpy = vi
      .spyOn(console, "warn")
      .mockImplementation(() => {});

    // Mock localStorage.setItem to throw an error
    vi.spyOn(Storage.prototype, "setItem").mockImplementation(() => {
      throw new Error("Storage full");
    });

    const { result } = renderHook(() =>
      useLocalStorage("test-key", "initial-value")
    );

    act(() => {
      result.current[1]("new-value");
    });

    // Warning should be logged when localStorage fails
    expect(consoleWarnSpy).toHaveBeenCalled();

    consoleWarnSpy.mockRestore();
  });
});
