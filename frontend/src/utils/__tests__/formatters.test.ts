import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import {
  formatNumber,
  formatCurrency,
  formatDate,
  formatRelativeTime,
  formatPercentage,
  truncate,
  formatStockStatus,
} from "../formatters";

describe("formatters", () => {
  describe("formatNumber", () => {
    it("formats number with thousands separator", () => {
      const result1 = formatNumber(1000);
      const result2 = formatNumber(1000000);
      expect(result1).toContain("1");
      expect(result1).toContain("000");
      expect(result2).toContain("1");
      expect(result2).toContain("000");
    });

    it("handles zero", () => {
      expect(formatNumber(0)).toBe("0");
    });

    it("handles negative numbers", () => {
      const result = formatNumber(-1000);
      expect(result).toContain("-1");
      expect(result).toContain("000");
    });

    it("handles decimal numbers", () => {
      const result = formatNumber(1234.56);
      expect(result).toContain("1");
      expect(result).toContain("234");
      expect(result).toContain("56");
    });
  });

  describe("formatCurrency", () => {
    it("formats currency with RUB by default", () => {
      const result = formatCurrency(1000);
      expect(result).toContain("1");
      expect(result).toContain("000");
      expect(result).toContain("₽");
    });

    it("formats currency with custom currency", () => {
      const result = formatCurrency(1000, "USD");
      expect(result).toContain("1");
      expect(result).toContain("000");
    });

    it("handles zero", () => {
      const result = formatCurrency(0);
      expect(result).toContain("0");
    });

    it("handles decimal values", () => {
      const result = formatCurrency(1234.56);
      expect(result).toContain("1");
      expect(result).toContain("234");
      expect(result).toContain("56");
    });
  });

  describe("formatDate", () => {
    it("formats date in short format", () => {
      const date = new Date("2025-10-22T12:30:00Z");
      const result = formatDate(date, "short");
      expect(result).toMatch(/\d{2}\.\d{2}\.\d{4}/);
    });

    it("formats date in long format", () => {
      const date = new Date("2025-10-22T12:30:00Z");
      const result = formatDate(date, "long");
      expect(result).toContain("2025");
    });

    it("formats date in time format", () => {
      const date = new Date("2025-10-22T12:30:00Z");
      const result = formatDate(date, "time");
      expect(result).toMatch(/\d{2}:\d{2}/);
    });

    it("handles string dates", () => {
      const result = formatDate("2025-10-22T12:30:00Z", "short");
      expect(result).toMatch(/\d{2}\.\d{2}\.\d{4}/);
    });
  });

  describe("formatRelativeTime", () => {
    beforeEach(() => {
      vi.useFakeTimers();
      vi.setSystemTime(new Date("2025-10-22T12:00:00Z"));
    });

    afterEach(() => {
      vi.useRealTimers();
    });

    it("returns 'только что' for recent times", () => {
      const date = new Date("2025-10-22T11:59:30Z");
      expect(formatRelativeTime(date)).toBe("только что");
    });

    it("returns minutes for times within an hour", () => {
      const date = new Date("2025-10-22T11:45:00Z");
      expect(formatRelativeTime(date)).toBe("15 мин. назад");
    });

    it("returns hours for times within a day", () => {
      const date = new Date("2025-10-22T10:00:00Z");
      expect(formatRelativeTime(date)).toBe("2 ч. назад");
    });

    it("returns days for times within a week", () => {
      const date = new Date("2025-10-20T12:00:00Z");
      expect(formatRelativeTime(date)).toBe("2 дн. назад");
    });

    it("returns formatted date for times older than a week", () => {
      const date = new Date("2025-10-10T12:00:00Z");
      const result = formatRelativeTime(date);
      expect(result).toMatch(/\d{2}\.\d{2}\.\d{4}/);
    });
  });

  describe("formatPercentage", () => {
    it("formats percentage with default decimals", () => {
      expect(formatPercentage(25.5)).toBe("25.5%");
    });

    it("formats percentage with custom decimals", () => {
      expect(formatPercentage(25.567, 2)).toBe("25.57%");
    });

    it("handles zero", () => {
      expect(formatPercentage(0)).toBe("0.0%");
    });

    it("handles negative percentages", () => {
      expect(formatPercentage(-10.5)).toBe("-10.5%");
    });
  });

  describe("truncate", () => {
    it("truncates text longer than max length", () => {
      expect(truncate("Hello World", 5)).toBe("Hello...");
    });

    it("does not truncate text shorter than max length", () => {
      expect(truncate("Hello", 10)).toBe("Hello");
    });

    it("handles exact length", () => {
      expect(truncate("Hello", 5)).toBe("Hello");
    });

    it("handles empty string", () => {
      expect(truncate("", 5)).toBe("");
    });
  });

  describe("formatStockStatus", () => {
    it("formats critical status", () => {
      expect(formatStockStatus("critical")).toBe("Критический");
    });

    it("formats low_stock status", () => {
      expect(formatStockStatus("low_stock")).toBe("Низкий");
    });

    it("formats normal status", () => {
      expect(formatStockStatus("normal")).toBe("Нормальный");
    });

    it("formats overstock status", () => {
      expect(formatStockStatus("overstock")).toBe("Избыток");
    });

    it("returns original status for unknown values", () => {
      expect(formatStockStatus("unknown")).toBe("unknown");
    });
  });
});
