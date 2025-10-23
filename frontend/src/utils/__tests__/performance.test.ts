import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import {
  markPerformance,
  measurePerformance,
  clearPerformance,
  debounce,
  throttle,
} from "../performance";

describe("performance utilities", () => {
  describe("markPerformance", () => {
    it("creates a performance mark", () => {
      const markSpy = vi.spyOn(performance, "mark");
      markPerformance("test-mark");
      expect(markSpy).toHaveBeenCalledWith("test-mark");
      markSpy.mockRestore();
    });
  });

  describe("measurePerformance", () => {
    beforeEach(() => {
      performance.clearMarks();
      performance.clearMeasures();
    });

    it("measures performance between two marks", () => {
      performance.mark("start");
      performance.mark("end");

      const duration = measurePerformance("test-measure", "start", "end");
      expect(duration).toBeGreaterThanOrEqual(0);
    });

    it("returns null when marks do not exist", () => {
      const duration = measurePerformance(
        "test-measure",
        "nonexistent-start",
        "nonexistent-end"
      );
      expect(duration).toBeNull();
    });
  });

  describe("clearPerformance", () => {
    it("clears specific performance marks and measures", () => {
      performance.mark("test-mark");
      clearPerformance("test-mark");

      const marks = performance.getEntriesByName("test-mark");
      expect(marks.length).toBe(0);
    });

    it("clears all performance marks and measures when no name provided", () => {
      performance.mark("mark1");
      performance.mark("mark2");
      clearPerformance();

      const allMarks = performance.getEntriesByType("mark");
      expect(allMarks.length).toBe(0);
    });
  });

  describe("debounce", () => {
    beforeEach(() => {
      vi.useFakeTimers();
    });

    afterEach(() => {
      vi.useRealTimers();
    });

    it("delays function execution", () => {
      const func = vi.fn();
      const debouncedFunc = debounce(func, 100);

      debouncedFunc();
      expect(func).not.toHaveBeenCalled();

      vi.advanceTimersByTime(100);
      expect(func).toHaveBeenCalledTimes(1);
    });

    it("cancels previous calls", () => {
      const func = vi.fn();
      const debouncedFunc = debounce(func, 100);

      debouncedFunc();
      debouncedFunc();
      debouncedFunc();

      vi.advanceTimersByTime(100);
      expect(func).toHaveBeenCalledTimes(1);
    });

    it("passes arguments correctly", () => {
      const func = vi.fn();
      const debouncedFunc = debounce(func, 100);

      debouncedFunc("arg1", "arg2");
      vi.advanceTimersByTime(100);

      expect(func).toHaveBeenCalledWith("arg1", "arg2");
    });
  });

  describe("throttle", () => {
    beforeEach(() => {
      vi.useFakeTimers();
    });

    afterEach(() => {
      vi.useRealTimers();
    });

    it("executes function immediately on first call", () => {
      const func = vi.fn();
      const throttledFunc = throttle(func, 100);

      throttledFunc();
      expect(func).toHaveBeenCalledTimes(1);
    });

    it("ignores calls within throttle period", () => {
      const func = vi.fn();
      const throttledFunc = throttle(func, 100);

      throttledFunc();
      throttledFunc();
      throttledFunc();

      expect(func).toHaveBeenCalledTimes(1);
    });

    it("allows calls after throttle period", () => {
      const func = vi.fn();
      const throttledFunc = throttle(func, 100);

      throttledFunc();
      expect(func).toHaveBeenCalledTimes(1);

      vi.advanceTimersByTime(100);

      throttledFunc();
      expect(func).toHaveBeenCalledTimes(2);
    });

    it("passes arguments correctly", () => {
      const func = vi.fn();
      const throttledFunc = throttle(func, 100);

      throttledFunc("arg1", "arg2");
      expect(func).toHaveBeenCalledWith("arg1", "arg2");
    });
  });
});
