/**
 * Ozon Demographics Dashboard Usage Example
 *
 * This example demonstrates how to use the OzonDemographics component
 * to display demographic analytics data from Ozon.
 *
 * @version 1.0
 * @author Manhattan System
 */

// Example 1: Basic initialization
function initializeBasicDemographics() {
  const demographics = new OzonDemographics({
    containerId: "demographicsContainer",
    apiBaseUrl: "/src/api/ozon-analytics.php",
    autoRefresh: false,
  });

  return demographics;
}

// Example 2: Advanced initialization with custom options
function initializeAdvancedDemographics() {
  const demographics = new OzonDemographics({
    containerId: "demographicsContainer",
    apiBaseUrl: "/src/api/ozon-analytics.php",
    autoRefresh: true,
    refreshInterval: 300000, // 5 minutes
  });

  return demographics;
}

// Example 3: Manual data loading
function loadDemographicsManually(demographics) {
  // Load data with force refresh
  demographics.loadDemographicsData(true);
}

// Example 4: Export demographics data
function exportDemographicsData(demographics) {
  demographics.exportDemographicsData();
}

// Example 5: Working with sample data
function demonstrateWithSampleData() {
  const sampleData = [
    {
      age_group: "18-24",
      gender: "M",
      region: "Москва",
      orders_count: 150,
      revenue: 45000,
    },
    {
      age_group: "18-24",
      gender: "F",
      region: "Москва",
      orders_count: 200,
      revenue: 60000,
    },
    {
      age_group: "25-34",
      gender: "M",
      region: "Санкт-Петербург",
      orders_count: 300,
      revenue: 90000,
    },
    {
      age_group: "25-34",
      gender: "F",
      region: "Санкт-Петербург",
      orders_count: 250,
      revenue: 75000,
    },
    {
      age_group: "35-44",
      gender: "M",
      region: "Екатеринбург",
      orders_count: 180,
      revenue: 54000,
    },
    {
      age_group: "35-44",
      gender: "F",
      region: "Екатеринбург",
      orders_count: 220,
      revenue: 66000,
    },
    {
      age_group: "45-54",
      gender: "M",
      region: "Новосибирск",
      orders_count: 120,
      revenue: 36000,
    },
    {
      age_group: "45-54",
      gender: "F",
      region: "Новосибирск",
      orders_count: 140,
      revenue: 42000,
    },
    {
      age_group: "55-64",
      gender: "M",
      region: "Казань",
      orders_count: 80,
      revenue: 24000,
    },
    {
      age_group: "55-64",
      gender: "F",
      region: "Казань",
      orders_count: 90,
      revenue: 27000,
    },
  ];

  const demographics = initializeBasicDemographics();

  // Update charts with sample data
  demographics.updateCharts(sampleData);
  demographics.updateSummary(sampleData);

  return demographics;
}

// Example 6: Integration with main dashboard
function integrateWithMainDashboard() {
  let ozonDemographics = null;

  // Initialize when demographics tab is shown
  const demographicsTab = document.getElementById("demographics-tab");
  if (demographicsTab) {
    demographicsTab.addEventListener("shown.bs.tab", function () {
      if (!ozonDemographics) {
        ozonDemographics = new OzonDemographics({
          containerId: "demographicsContainer",
          apiBaseUrl: "/src/api/ozon-analytics.php",
          autoRefresh: false,
        });
      }
    });
  }

  // Handle filter changes
  const applyFiltersBtn = document.getElementById("applyAnalyticsFilters");
  if (applyFiltersBtn) {
    applyFiltersBtn.addEventListener("click", function () {
      if (ozonDemographics) {
        ozonDemographics.loadDemographicsData(true);
      }
    });
  }

  // Handle refresh
  const refreshBtn = document.getElementById("refreshAnalytics");
  if (refreshBtn) {
    refreshBtn.addEventListener("click", function () {
      if (ozonDemographics) {
        ozonDemographics.loadDemographicsData(true);
      }
    });
  }

  return ozonDemographics;
}

// Example 7: Error handling
function handleDemographicsErrors() {
  const demographics = new OzonDemographics({
    containerId: "demographicsContainer",
    apiBaseUrl: "/src/api/ozon-analytics.php",
    autoRefresh: false,
  });

  // Override error handling methods for custom behavior
  const originalShowError = demographics.showErrorNotification;
  demographics.showErrorNotification = function (message) {
    // Custom error handling
    console.error("Demographics Error:", message);

    // Show custom notification
    const notification = document.createElement("div");
    notification.className = "alert alert-danger alert-dismissible fade show";
    notification.innerHTML = `
            <strong>Ошибка демографии:</strong> ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;

    document.body.appendChild(notification);

    // Auto-remove after 5 seconds
    setTimeout(() => {
      if (notification.parentNode) {
        notification.parentNode.removeChild(notification);
      }
    }, 5000);

    // Call original method
    originalShowError.call(this, message);
  };

  return demographics;
}

// Example 8: Custom chart styling
function customizeChartStyling() {
  const demographics = new OzonDemographics({
    containerId: "demographicsContainer",
    apiBaseUrl: "/src/api/ozon-analytics.php",
    autoRefresh: false,
  });

  // Wait for initialization, then customize charts
  setTimeout(() => {
    // Customize age distribution chart
    if (demographics.charts.ageDistribution) {
      demographics.charts.ageDistribution.options.plugins.legend = {
        display: true,
        position: "bottom",
      };
      demographics.charts.ageDistribution.update();
    }

    // Customize gender distribution chart
    if (demographics.charts.genderDistribution) {
      demographics.charts.genderDistribution.data.datasets[0].backgroundColor =
        [
          "#4A90E2", // Blue for male
          "#F5A623", // Orange for female
          "#7ED321", // Green for unspecified
        ];
      demographics.charts.genderDistribution.update();
    }
  }, 1000);

  return demographics;
}

// Example 9: Responsive behavior
function handleResponsiveBehavior() {
  const demographics = initializeBasicDemographics();

  // Handle window resize
  window.addEventListener("resize", function () {
    demographics.resizeCharts();
  });

  // Handle orientation change on mobile
  window.addEventListener("orientationchange", function () {
    setTimeout(() => {
      demographics.resizeCharts();
    }, 100);
  });

  return demographics;
}

// Example 10: Cleanup and memory management
function properCleanup() {
  let demographics = initializeBasicDemographics();

  // Cleanup function
  function cleanup() {
    if (demographics) {
      demographics.destroy();
      demographics = null;
    }
  }

  // Cleanup on page unload
  window.addEventListener("beforeunload", cleanup);

  // Cleanup on tab switch (if using single-page application)
  document.addEventListener("visibilitychange", function () {
    if (document.hidden) {
      // Page is hidden, stop auto-refresh
      if (demographics) {
        demographics.stopAutoRefresh();
      }
    } else {
      // Page is visible, restart auto-refresh if needed
      if (demographics && demographics.options.autoRefresh) {
        demographics.startAutoRefresh();
      }
    }
  });

  return { demographics, cleanup };
}

// Usage examples for different scenarios
const UsageExamples = {
  // Basic usage
  basic: initializeBasicDemographics,

  // Advanced usage
  advanced: initializeAdvancedDemographics,

  // With sample data
  withSampleData: demonstrateWithSampleData,

  // Dashboard integration
  dashboardIntegration: integrateWithMainDashboard,

  // Error handling
  errorHandling: handleDemographicsErrors,

  // Custom styling
  customStyling: customizeChartStyling,

  // Responsive
  responsive: handleResponsiveBehavior,

  // Cleanup
  withCleanup: properCleanup,
};

// Export for use in other modules
if (typeof module !== "undefined" && module.exports) {
  module.exports = UsageExamples;
}

// Make available globally for browser usage
if (typeof window !== "undefined") {
  window.OzonDemographicsExamples = UsageExamples;
}
