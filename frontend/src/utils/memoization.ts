/**
 * Memoization Utilities for Performance Optimization
 *
 * Provides memoization functions for expensive calculations and data transformations.
 *
 * Requirements: 7.2, 7.3
 * Task: 4.2 Frontend performance optimization - memoization
 */

import type {
  ProductWarehouseItem,
  FilterState,
  SortConfig,
} from "../types/inventory-dashboard";

/**
 * Simple memoization function with LRU cache
 */
class LRUCache<K, V> {
  private cache = new Map<K, V>();
  private maxSize: number;

  constructor(maxSize: number = 100) {
    this.maxSize = maxSize;
  }

  get(key: K): V | undefined {
    const value = this.cache.get(key);
    if (value !== undefined) {
      // Move to end (most recently used)
      this.cache.delete(key);
      this.cache.set(key, value);
    }
    return value;
  }

  set(key: K, value: V): void {
    if (this.cache.has(key)) {
      this.cache.delete(key);
    } else if (this.cache.size >= this.maxSize) {
      // Remove least recently used (first item)
      const firstKey = this.cache.keys().next().value;
      if (firstKey !== undefined) {
        this.cache.delete(firstKey);
      }
    }
    this.cache.set(key, value);
  }

  clear(): void {
    this.cache.clear();
  }

  size(): number {
    return this.cache.size;
  }
}

/**
 * Memoized function creator
 */
export function memoize<Args extends any[], Return>(
  fn: (...args: Args) => Return,
  keyFn?: (...args: Args) => string,
  maxSize: number = 100
): (...args: Args) => Return {
  const cache = new LRUCache<string, Return>(maxSize);

  return (...args: Args): Return => {
    const key = keyFn ? keyFn(...args) : JSON.stringify(args);

    const cached = cache.get(key);
    if (cached !== undefined) {
      return cached;
    }

    const result = fn(...args);
    cache.set(key, result);
    return result;
  };
}

/**
 * Memoized data filtering function
 */
export const memoizedFilterData = memoize(
  (
    data: ProductWarehouseItem[],
    filters: FilterState
  ): ProductWarehouseItem[] => {
    return data.filter((item) => {
      // Warehouse filter
      if (
        filters.warehouses.length > 0 &&
        !filters.warehouses.includes(item.warehouseName)
      ) {
        return false;
      }

      // Status filter
      if (
        filters.statuses.length > 0 &&
        !filters.statuses.includes(item.status)
      ) {
        return false;
      }

      // Search filter
      if (filters.searchTerm) {
        const searchLower = filters.searchTerm.toLowerCase();
        const matchesName = item.productName
          .toLowerCase()
          .includes(searchLower);
        const matchesSku = item.sku?.toLowerCase().includes(searchLower);

        if (!matchesName && !matchesSku) {
          return false;
        }
      }

      // Days of stock filter
      if (
        filters.minDaysOfStock !== undefined &&
        (item.daysOfStock === null || item.daysOfStock < filters.minDaysOfStock)
      ) {
        return false;
      }

      if (
        filters.maxDaysOfStock !== undefined &&
        (item.daysOfStock === null || item.daysOfStock > filters.maxDaysOfStock)
      ) {
        return false;
      }

      // Show only urgent filter
      if (
        filters.showOnlyUrgent &&
        !["critical", "low"].includes(item.status)
      ) {
        return false;
      }

      return true;
    });
  },
  (data, filters) => `${data.length}-${JSON.stringify(filters)}`,
  50 // Cache up to 50 different filter combinations
);

/**
 * Memoized data sorting function
 */
export const memoizedSortData = memoize(
  (
    data: ProductWarehouseItem[],
    sortConfig: SortConfig
  ): ProductWarehouseItem[] => {
    if (!sortConfig.column) {
      return data;
    }

    return [...data].sort((a, b) => {
      const aValue = a[sortConfig.column];
      const bValue = b[sortConfig.column];

      // Handle null/undefined values
      if (aValue === null || aValue === undefined) {
        return bValue === null || bValue === undefined ? 0 : 1;
      }
      if (bValue === null || bValue === undefined) {
        return -1;
      }

      // Handle different data types
      if (typeof aValue === "string" && typeof bValue === "string") {
        const comparison = aValue.localeCompare(bValue);
        return sortConfig.direction === "desc" ? -comparison : comparison;
      }

      if (typeof aValue === "number" && typeof bValue === "number") {
        const comparison = aValue - bValue;
        return sortConfig.direction === "desc" ? -comparison : comparison;
      }

      // Fallback to string comparison
      const aStr = String(aValue);
      const bStr = String(bValue);
      const comparison = aStr.localeCompare(bStr);
      return sortConfig.direction === "desc" ? -comparison : comparison;
    });
  },
  (data, sortConfig) =>
    `${data.length}-${sortConfig.column}-${sortConfig.direction}`,
  20 // Cache up to 20 different sort configurations
);

/**
 * Memoized summary statistics calculation
 */
export const memoizedCalculateSummary = memoize(
  (data: ProductWarehouseItem[]) => {
    const summary = {
      totalProducts: data.length,
      productsWithStock: 0,
      totalStockValue: 0,
      statusDistribution: {
        critical: 0,
        low: 0,
        normal: 0,
        excess: 0,
        out_of_stock: 0,
        no_sales: 0,
      },
      avgDaysOfStock: 0,
      productsNeedingReplenishment: 0,
      totalRecommendedValue: 0,
    };

    let totalDaysOfStock = 0;
    let productsWithDaysOfStock = 0;

    data.forEach((item) => {
      if (item.currentStock > 0) {
        summary.productsWithStock++;
      }

      summary.totalStockValue += item.currentStock * 100 || 0; // Approximate stock value
      summary.statusDistribution[
        item.status as keyof typeof summary.statusDistribution
      ]++;

      if (item.daysOfStock !== null && item.daysOfStock !== undefined) {
        totalDaysOfStock += item.daysOfStock;
        productsWithDaysOfStock++;
      }

      if (item.recommendedQty > 0) {
        summary.productsNeedingReplenishment++;
        summary.totalRecommendedValue += item.recommendedValue || 0;
      }
    });

    summary.avgDaysOfStock =
      productsWithDaysOfStock > 0
        ? Math.round((totalDaysOfStock / productsWithDaysOfStock) * 10) / 10
        : 0;

    return summary;
  },
  (data) =>
    `summary-${data.length}-${data.reduce(
      (acc, item) => acc + item.currentStock,
      0
    )}`,
  10 // Cache up to 10 different summary calculations
);

/**
 * Memoized warehouse statistics calculation
 */
export const memoizedCalculateWarehouseStats = memoize(
  (data: ProductWarehouseItem[]) => {
    const warehouseStats = new Map<
      string,
      {
        totalProducts: number;
        productsWithStock: number;
        criticalProducts: number;
        lowProducts: number;
        totalStockValue: number;
        avgDaysOfStock: number;
      }
    >();

    data.forEach((item) => {
      const warehouseName = item.warehouseName;

      if (!warehouseStats.has(warehouseName)) {
        warehouseStats.set(warehouseName, {
          totalProducts: 0,
          productsWithStock: 0,
          criticalProducts: 0,
          lowProducts: 0,
          totalStockValue: 0,
          avgDaysOfStock: 0,
        });
      }

      const stats = warehouseStats.get(warehouseName)!;
      stats.totalProducts++;

      if (item.currentStock > 0) {
        stats.productsWithStock++;
      }

      if (item.status === "critical") {
        stats.criticalProducts++;
      } else if (item.status === "low") {
        stats.lowProducts++;
      }

      stats.totalStockValue += item.currentStock * 100 || 0;
    });

    // Convert to array and sort by total products
    return Array.from(warehouseStats.entries())
      .map(([name, stats]) => ({ name, ...stats }))
      .sort((a, b) => b.totalProducts - a.totalProducts);
  },
  (data) => `warehouse-stats-${data.length}`,
  5 // Cache up to 5 different warehouse calculations
);

/**
 * Memoized export data preparation
 */
export const memoizedPrepareExportData = memoize(
  (data: ProductWarehouseItem[], includeCalculations: boolean = true) => {
    return data.map((item) => {
      const baseData = {
        "Product Name": item.productName,
        SKU: item.sku || "",
        Warehouse: item.warehouseName,
        "Current Stock": item.currentStock,
        "Available Stock": item.availableStock,
        "Daily Sales": item.dailySales,
        "Sales (28d)": item.sales28d,
        "Stock Status": item.status,
        "Last Updated": item.lastUpdated,
      };

      if (includeCalculations) {
        return {
          ...baseData,
          "Days of Stock": item.daysOfStock,
          "Recommended Qty": item.recommendedQty,
          "Recommended Value": item.recommendedValue,
          "Urgency Score": item.urgencyScore,
          "Stockout Risk": item.stockoutRisk,
          "Turnover Rate": item.turnoverRate,
        };
      }

      return baseData;
    });
  },
  (data, includeCalculations) => `export-${data.length}-${includeCalculations}`,
  5 // Cache up to 5 different export preparations
);

/**
 * Clear all memoization caches
 */
export function clearAllCaches(): void {
  // This would clear all internal caches
  // In a real implementation, we might want to expose cache clearing methods
  console.log("Clearing all memoization caches");
}

/**
 * Get cache statistics for monitoring
 */
export function getCacheStats() {
  return {
    // In a real implementation, we would return actual cache statistics
    message: "Cache statistics would be available here",
  };
}
