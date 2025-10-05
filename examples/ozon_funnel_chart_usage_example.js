/**
 * Ozon Funnel Chart Usage Example
 *
 * Демонстрирует различные способы использования компонента OzonFunnelChart
 *
 * @version 1.0
 * @author Manhattan System
 */

// Example 1: Basic Usage
function basicUsageExample() {
  // Create a new funnel chart instance
  const funnelChart = new OzonFunnelChart("myFunnelContainer", {
    responsive: true,
    showConversions: true,
    colors: {
      views: "#0066cc",
      cartAdditions: "#4CAF50",
      orders: "#FF9800",
    },
  });

  // Sample data structure
  const sampleData = {
    totals: {
      views: 15420,
      cart_additions: 2340,
      orders: 456,
      conversion_view_to_cart: 15.2,
      conversion_cart_to_order: 19.5,
      conversion_overall: 2.96,
    },
  };

  // Render the funnel
  funnelChart.renderFunnel(sampleData);
}

// Example 2: Advanced Usage with Event Handling
function advancedUsageExample() {
  const funnelChart = new OzonFunnelChart("advancedFunnelContainer", {
    responsive: true,
    maintainAspectRatio: false,
    showConversions: true,
    showPercentages: true,
    animationDuration: 1000,
  });

  // Listen for stage click events
  document
    .getElementById("advancedFunnelContainer")
    .addEventListener("funnelStageClick", function (event) {
      const { stage, stageIndex, data } = event.detail;

      console.log(`Stage clicked: ${stage}`);
      console.log(`Stage index: ${stageIndex}`);
      console.log(`Current data:`, data);

      // You can perform custom actions here:
      // - Show detailed breakdown
      // - Filter other charts
      // - Open modal with more information
      showStageModal(stage, data);
    });

  // Listen for retry events (when error occurs)
  document
    .getElementById("advancedFunnelContainer")
    .addEventListener("retryLoad", function () {
      console.log("Retry requested, reloading data...");
      loadFunnelDataFromAPI();
    });

  // Load initial data
  loadFunnelDataFromAPI();
}

// Example 3: Integration with API
async function apiIntegrationExample() {
  const funnelChart = new OzonFunnelChart("apiFunnelContainer");

  try {
    // Show loading state
    funnelChart.showLoading();

    // Fetch data from API
    const response = await fetch(
      "/src/api/ozon-analytics.php?action=funnel-data&date_from=2025-01-01&date_to=2025-01-31"
    );
    const result = await response.json();

    if (result.success) {
      // Render the chart with API data
      funnelChart.renderFunnel(result.data);
    } else {
      // Show error if API request failed
      funnelChart.showError(result.message || "Ошибка загрузки данных");
    }
  } catch (error) {
    console.error("API Error:", error);
    funnelChart.showError("Ошибка подключения к API");
  }
}

// Example 4: Dynamic Data Updates
function dynamicUpdatesExample() {
  const funnelChart = new OzonFunnelChart("dynamicFunnelContainer");

  // Initial data
  let currentData = {
    totals: {
      views: 10000,
      cart_additions: 1500,
      orders: 300,
      conversion_view_to_cart: 15.0,
      conversion_cart_to_order: 20.0,
      conversion_overall: 3.0,
    },
  };

  funnelChart.renderFunnel(currentData);

  // Simulate real-time updates every 5 seconds
  setInterval(() => {
    // Generate new random data
    currentData.totals.views += Math.floor(Math.random() * 100);
    currentData.totals.cart_additions += Math.floor(Math.random() * 15);
    currentData.totals.orders += Math.floor(Math.random() * 3);

    // Recalculate conversions
    currentData.totals.conversion_view_to_cart =
      (currentData.totals.cart_additions / currentData.totals.views) * 100;
    currentData.totals.conversion_cart_to_order =
      (currentData.totals.orders / currentData.totals.cart_additions) * 100;
    currentData.totals.conversion_overall =
      (currentData.totals.orders / currentData.totals.views) * 100;

    // Update chart with new data
    funnelChart.updateData(currentData);
  }, 5000);
}

// Example 5: Custom Styling and Options
function customStylingExample() {
  const funnelChart = new OzonFunnelChart("customFunnelContainer", {
    responsive: true,
    maintainAspectRatio: false,
    showConversions: true,
    showPercentages: true,
    animationDuration: 1200,
    colors: {
      views: "#e74c3c", // Red for views
      cartAdditions: "#f39c12", // Orange for cart additions
      orders: "#27ae60", // Green for orders
      conversions: "#9b59b6", // Purple for conversions
    },
  });

  const customData = {
    totals: {
      views: 25000,
      cart_additions: 4500,
      orders: 890,
      conversion_view_to_cart: 18.0,
      conversion_cart_to_order: 19.8,
      conversion_overall: 3.56,
    },
  };

  funnelChart.renderFunnel(customData);

  // Example of changing options dynamically
  setTimeout(() => {
    funnelChart.setOptions({
      colors: {
        views: "#3498db",
        cartAdditions: "#2ecc71",
        orders: "#e67e22",
      },
      animationDuration: 600,
    });
  }, 3000);
}

// Example 6: Multiple Charts on Same Page
function multipleChartsExample() {
  // Chart 1: Current period
  const currentChart = new OzonFunnelChart("currentPeriodChart", {
    showConversions: true,
  });

  // Chart 2: Previous period for comparison
  const previousChart = new OzonFunnelChart("previousPeriodChart", {
    showConversions: true,
    colors: {
      views: "#95a5a6",
      cartAdditions: "#7f8c8d",
      orders: "#34495e",
    },
  });

  // Current period data
  const currentData = {
    totals: {
      views: 18500,
      cart_additions: 2800,
      orders: 520,
      conversion_view_to_cart: 15.1,
      conversion_cart_to_order: 18.6,
      conversion_overall: 2.81,
    },
  };

  // Previous period data
  const previousData = {
    totals: {
      views: 16200,
      cart_additions: 2400,
      orders: 450,
      conversion_view_to_cart: 14.8,
      conversion_cart_to_order: 18.8,
      conversion_overall: 2.78,
    },
  };

  currentChart.renderFunnel(currentData);
  previousChart.renderFunnel(previousData);
}

// Helper Functions

function showStageModal(stage, data) {
  const stageNames = {
    views: "Просмотры",
    cart_additions: "Добавления в корзину",
    orders: "Заказы",
  };

  const stageName = stageNames[stage] || stage;
  const stageValue = data.totals[stage] || 0;

  // Create and show modal (simplified example)
  alert(`Детали этапа: ${stageName}\nЗначение: ${stageValue.toLocaleString()}`);
}

async function loadFunnelDataFromAPI() {
  try {
    const response = await fetch(
      "/src/api/ozon-analytics.php?action=funnel-data&date_from=2025-01-01&date_to=2025-01-31"
    );
    const result = await response.json();

    if (result.success) {
      return result.data;
    } else {
      throw new Error(result.message || "API Error");
    }
  } catch (error) {
    console.error("Error loading funnel data:", error);
    throw error;
  }
}

// Export examples for use in other files
if (typeof module !== "undefined" && module.exports) {
  module.exports = {
    basicUsageExample,
    advancedUsageExample,
    apiIntegrationExample,
    dynamicUpdatesExample,
    customStylingExample,
    multipleChartsExample,
  };
}

// Auto-run basic example if this script is loaded directly
if (typeof window !== "undefined" && window.document) {
  document.addEventListener("DOMContentLoaded", function () {
    // Check if we have a basic container and run the example
    if (document.getElementById("myFunnelContainer")) {
      basicUsageExample();
    }
  });
}
