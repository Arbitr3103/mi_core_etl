/**
 * Frontend Performance Testing Suite
 *
 * Tests frontend rendering performance with large datasets and validates
 * that performance requirements are met.
 *
 * Requirements: 7.1, 7.2, 7.3
 * Task: 4.3 Performance testing and validation
 */

import type {
  ProductWarehouseItem,
  FilterState,
} from "../types/inventory-dashboard";
import {
  memoizedFilterData,
  memoizedSortData,
  memoizedCalculateSummary,
} from "../utils/memoization";

interface PerformanceTestResult {
  testName: string;
  executionTime: number;
  memoryUsage?: number;
  dataSize: number;
  targetTime: number;
  passed: boolean;
  details?: any;
}

interface PerformanceTestSuite {
  results: PerformanceTestResult[];
  summary: {
    totalTests: number;
    passedTests: number;
    successRate: number;
    overallPassed: boolean;
  };
}

/**
 * Generate mock data for testing
 */
function generateMockData(count: number): ProductWarehouseItem[] {
  const warehouses = [
    "Коледино",
    "Тверь",
    "Екатеринбург",
    "Новосибирск",
    "Казань",
  ];
  const statuses: Array<"critical" | "low" | "normal" | "excess" | "no_sales"> =
    ["critical", "low", "normal", "excess", "no_sales"];
  const productNames = [
    "Автомобильная запчасть для двигателя",
    "Тормозные колодки передние",
    "Масляный фильтр",
    "Воздушный фильтр салона",
    "Свечи зажигания комплект",
    "Амортизатор задний",
    "Ремень ГРМ",
    "Радиатор охлаждения",
  ];

  const mockData: ProductWarehouseItem[] = [];

  for (let i = 0; i < count; i++) {
    const warehouse = warehouses[i % warehouses.length];
    const status = statuses[i % statuses.length];
    const productName = productNames[i % productNames.length];
    const dailySales = Math.random() * 10;
    const currentStock = Math.floor(Math.random() * 1000);
    const daysOfStock = dailySales > 0 ? currentStock / dailySales : null;

    mockData.push({
      productId: i + 1,
      productName: `${productName} ${i + 1}`,
      sku: `SKU${String(i + 1).padStart(6, "0")}`,
      warehouseName: warehouse,
      currentStock: currentStock,
      reservedStock: Math.floor(currentStock * 0.2),
      availableStock: Math.floor(currentStock * 0.8),
      dailySales: dailySales,
      sales7d: Math.floor(dailySales * 7),
      sales28d: Math.floor(dailySales * 28),
      salesTrend: ["up", "down", "stable"][Math.floor(Math.random() * 3)] as
        | "up"
        | "down"
        | "stable",
      daysOfStock: daysOfStock || 0,
      monthsOfStock: daysOfStock ? daysOfStock / 30 : 0,
      turnoverRate: dailySales > 0 ? (dailySales * 365) / currentStock : 0,
      status: status,
      recommendedQty: Math.max(0, Math.floor(dailySales * 60 - currentStock)),
      recommendedValue:
        Math.max(0, Math.floor(dailySales * 60 - currentStock)) *
        (50 + Math.random() * 200),
      urgencyScore: Math.floor(Math.random() * 100),
      stockoutRisk: Math.floor(Math.random() * 100),
      lastUpdated: new Date().toISOString(),
      lastSaleDate:
        Math.random() > 0.5
          ? new Date(
              Date.now() - Math.random() * 30 * 24 * 60 * 60 * 1000
            ).toISOString()
          : null,
    });
  }

  return mockData;
}

/**
 * Measure execution time and memory usage
 */
function measurePerformance<T>(
  operation: () => T,
  testName: string,
  dataSize: number,
  targetTime: number
): PerformanceTestResult {
  // Clear any existing performance marks
  if (typeof performance !== "undefined" && performance.clearMarks) {
    performance.clearMarks();
  }

  const startTime = performance.now();
  const startMemory = (performance as any).memory?.usedJSHeapSize;

  const result = operation();

  const endTime = performance.now();
  const endMemory = (performance as any).memory?.usedJSHeapSize;

  const executionTime = endTime - startTime;
  const memoryUsage =
    endMemory && startMemory ? endMemory - startMemory : undefined;

  return {
    testName,
    executionTime,
    memoryUsage,
    dataSize,
    targetTime,
    passed: executionTime < targetTime,
    details: { result },
  };
}

/**
 * Test data filtering performance
 */
function testFilteringPerformance(
  data: ProductWarehouseItem[]
): PerformanceTestResult[] {
  const results: PerformanceTestResult[] = [];

  // Test 1: Simple warehouse filter
  results.push(
    measurePerformance(
      () =>
        memoizedFilterData(data, {
          warehouses: ["Коледино"],
          statuses: [],
          searchTerm: "",
          showOnlyUrgent: false,
        }),
      "Simple Warehouse Filter",
      data.length,
      50 // 50ms target
    )
  );

  // Test 2: Multiple status filter
  results.push(
    measurePerformance(
      () =>
        memoizedFilterData(data, {
          warehouses: [],
          statuses: ["critical", "low"],
          searchTerm: "",
          showOnlyUrgent: false,
        }),
      "Multiple Status Filter",
      data.length,
      50
    )
  );

  // Test 3: Complex combined filter
  results.push(
    measurePerformance(
      () =>
        memoizedFilterData(data, {
          warehouses: ["Коледино", "Тверь"],
          statuses: ["critical", "low", "normal"],
          searchTerm: "Автомобильная",
          showOnlyUrgent: false,
          minDaysOfStock: 5,
          maxDaysOfStock: 30,
        }),
      "Complex Combined Filter",
      data.length,
      100 // Higher threshold for complex filters
    )
  );

  // Test 4: Search filter
  results.push(
    measurePerformance(
      () =>
        memoizedFilterData(data, {
          warehouses: [],
          statuses: [],
          searchTerm: "запчасть",
          showOnlyUrgent: false,
        }),
      "Search Filter",
      data.length,
      75
    )
  );

  return results;
}

/**
 * Test data sorting performance
 */
function testSortingPerformance(
  data: ProductWarehouseItem[]
): PerformanceTestResult[] {
  const results: PerformanceTestResult[] = [];

  // Test different sorting scenarios
  const sortTests = [
    {
      column: "productName" as keyof ProductWarehouseItem,
      direction: "asc" as const,
      target: 100,
    },
    {
      column: "daysOfStock" as keyof ProductWarehouseItem,
      direction: "asc" as const,
      target: 75,
    },
    {
      column: "urgencyScore" as keyof ProductWarehouseItem,
      direction: "desc" as const,
      target: 75,
    },
    {
      column: "dailySales" as keyof ProductWarehouseItem,
      direction: "desc" as const,
      target: 75,
    },
    {
      column: "currentStock" as keyof ProductWarehouseItem,
      direction: "desc" as const,
      target: 100,
    },
  ];

  sortTests.forEach(({ column, direction, target }) => {
    results.push(
      measurePerformance(
        () => memoizedSortData(data, { column, direction }),
        `Sort by ${String(column)} ${direction}`,
        data.length,
        target
      )
    );
  });

  return results;
}

/**
 * Test summary calculation performance
 */
function testSummaryCalculationPerformance(
  data: ProductWarehouseItem[]
): PerformanceTestResult {
  return measurePerformance(
    () => memoizedCalculateSummary(data),
    "Summary Calculation",
    data.length,
    50
  );
}

/**
 * Test virtual scrolling simulation
 */
function testVirtualScrollingPerformance(
  data: ProductWarehouseItem[]
): PerformanceTestResult[] {
  const results: PerformanceTestResult[] = [];

  // Simulate virtual scrolling by processing chunks of data
  const rowHeight = 50;
  const containerHeight = 600;
  const visibleRows = Math.ceil(containerHeight / rowHeight);
  const overscan = 5;

  // Test 1: Calculate visible range
  results.push(
    measurePerformance(
      () => {
        const scrollTop = Math.floor(Math.random() * data.length * rowHeight);
        const startIndex = Math.max(
          0,
          Math.floor(scrollTop / rowHeight) - overscan
        );
        const endIndex = Math.min(
          data.length - 1,
          Math.ceil((scrollTop + containerHeight) / rowHeight) + overscan
        );
        return data.slice(startIndex, endIndex + 1);
      },
      "Virtual Scroll Range Calculation",
      data.length,
      10 // Very fast operation
    )
  );

  // Test 2: Render visible items
  results.push(
    measurePerformance(
      () => {
        const visibleData = data.slice(0, visibleRows + overscan * 2);
        return visibleData.map((item, index) => ({
          key: `${item.productId}-${item.warehouseName}`,
          style: {
            position: "absolute" as const,
            top: index * rowHeight,
            height: rowHeight,
          },
          data: item,
        }));
      },
      "Virtual Scroll Render Items",
      visibleRows + overscan * 2,
      25
    )
  );

  return results;
}

/**
 * Test memoization effectiveness
 */
function testMemoizationPerformance(
  data: ProductWarehouseItem[]
): PerformanceTestResult[] {
  const results: PerformanceTestResult[] = [];

  const filters: FilterState = {
    warehouses: ["Коледино"],
    statuses: ["critical"],
    searchTerm: "",
    showOnlyUrgent: false,
  };

  // Test 1: First call (cache miss)
  const firstCallResult = measurePerformance(
    () => memoizedFilterData(data, filters),
    "Memoization - First Call (Cache Miss)",
    data.length,
    100
  );
  results.push(firstCallResult);

  // Test 2: Second call (cache hit)
  const secondCallResult = measurePerformance(
    () => memoizedFilterData(data, filters),
    "Memoization - Second Call (Cache Hit)",
    data.length,
    10 // Should be much faster
  );
  results.push(secondCallResult);

  // Calculate speedup
  const speedup =
    firstCallResult.executionTime / secondCallResult.executionTime;
  results.push({
    testName: "Memoization Speedup Factor",
    executionTime: speedup,
    dataSize: data.length,
    targetTime: 5, // Should be at least 5x faster
    passed: speedup >= 5,
    details: {
      speedup,
      firstCall: firstCallResult.executionTime,
      secondCall: secondCallResult.executionTime,
    },
  });

  return results;
}

/**
 * Run all performance tests
 */
export function runPerformanceTests(): PerformanceTestSuite {
  console.log("🚀 Starting Frontend Performance Tests...\n");

  const results: PerformanceTestResult[] = [];

  // Test with different dataset sizes
  const testSizes = [1000, 5000, 10000];

  testSizes.forEach((size) => {
    console.log(`📊 Testing with ${size} records...`);

    const testData = generateMockData(size);

    // Test filtering performance
    console.log("  🔍 Testing filtering performance...");
    results.push(...testFilteringPerformance(testData));

    // Test sorting performance
    console.log("  📈 Testing sorting performance...");
    results.push(...testSortingPerformance(testData));

    // Test summary calculation
    console.log("  📋 Testing summary calculation...");
    results.push(testSummaryCalculationPerformance(testData));

    // Test virtual scrolling
    console.log("  📜 Testing virtual scrolling...");
    results.push(...testVirtualScrollingPerformance(testData));

    // Test memoization (only for largest dataset)
    if (size === Math.max(...testSizes)) {
      console.log("  🧠 Testing memoization...");
      results.push(...testMemoizationPerformance(testData));
    }

    console.log(`  ✅ Completed tests for ${size} records\n`);
  });

  // Calculate summary
  const totalTests = results.length;
  const passedTests = results.filter((r) => r.passed).length;
  const successRate = (passedTests / totalTests) * 100;
  const overallPassed = successRate >= 80; // 80% pass rate required

  const summary = {
    totalTests,
    passedTests,
    successRate,
    overallPassed,
  };

  return { results, summary };
}

/**
 * Generate performance report
 */
export function generatePerformanceReport(
  testSuite: PerformanceTestSuite
): string {
  const { results, summary } = testSuite;

  let report = "=== FRONTEND PERFORMANCE TEST REPORT ===\n\n";

  // Group results by test type
  const groupedResults = results.reduce((groups, result) => {
    const category = result.testName.split(" - ")[0] || result.testName;
    if (!groups[category]) groups[category] = [];
    groups[category].push(result);
    return groups;
  }, {} as Record<string, PerformanceTestResult[]>);

  // Report by category
  Object.entries(groupedResults).forEach(([category, categoryResults]) => {
    report += `${category.toUpperCase()}:\n`;
    report += "=".repeat(category.length + 1) + "\n";

    categoryResults.forEach((result) => {
      const status = result.passed ? "✅ PASSED" : "❌ FAILED";
      report += `${result.testName}: ${status}\n`;
      report += `  Execution Time: ${result.executionTime.toFixed(
        2
      )}ms (target: <${result.targetTime}ms)\n`;
      report += `  Data Size: ${result.dataSize} records\n`;

      if (result.memoryUsage) {
        report += `  Memory Usage: ${(result.memoryUsage / 1024 / 1024).toFixed(
          2
        )}MB\n`;
      }

      if (result.details?.speedup) {
        report += `  Speedup Factor: ${result.details.speedup.toFixed(1)}x\n`;
      }

      report += "\n";
    });

    report += "\n";
  });

  // Overall summary
  report += "OVERALL SUMMARY:\n";
  report += "================\n";
  report += `Total Tests: ${summary.totalTests}\n`;
  report += `Passed Tests: ${summary.passedTests}\n`;
  report += `Success Rate: ${summary.successRate.toFixed(1)}%\n`;
  report += `Overall Status: ${
    summary.overallPassed ? "✅ PASSED" : "❌ FAILED"
  }\n\n`;

  // Performance recommendations
  report += "PERFORMANCE RECOMMENDATIONS:\n";
  report += "============================\n";

  const failedTests = results.filter((r) => !r.passed);
  if (failedTests.length === 0) {
    report += "🎉 All performance tests passed! No recommendations needed.\n";
  } else {
    failedTests.forEach((test) => {
      report += `• ${
        test.testName
      }: Consider optimization (${test.executionTime.toFixed(2)}ms > ${
        test.targetTime
      }ms)\n`;
    });
  }

  return report;
}

/**
 * Run performance tests and log results (for development)
 */
export function runAndLogPerformanceTests(): void {
  if (process.env.NODE_ENV !== "development") {
    console.warn("Performance tests should only be run in development mode");
    return;
  }

  const testSuite = runPerformanceTests();
  const report = generatePerformanceReport(testSuite);

  console.log(report);

  // Save to localStorage for debugging
  if (typeof localStorage !== "undefined") {
    localStorage.setItem("performanceTestResults", JSON.stringify(testSuite));
    console.log("📁 Performance test results saved to localStorage");
  }
}
