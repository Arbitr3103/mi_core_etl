/**
 * Automated UI tests for MDM system
 * Tests user interface functionality and workflows
 * Requirements: 4.1, 4.2, 4.3, 4.4
 */

const { Builder, By, until, Key } = require("selenium-webdriver");
const assert = require("assert");

class MDMUIAutomationTest {
  constructor() {
    this.driver = null;
    this.baseUrl = process.env.MDM_BASE_URL || "http://localhost/src/MDM";
    this.testResults = [];
  }

  async setUp() {
    this.driver = await new Builder().forBrowser("chrome").build();
    await this.driver.manage().window().maximize();
    await this.driver.manage().setTimeouts({ implicit: 10000 });
  }

  async tearDown() {
    if (this.driver) {
      await this.driver.quit();
    }
    this.generateTestReport();
  }

  /**
   * Test dashboard functionality
   * Requirements: 4.1, 5.1, 5.2
   */
  async testDashboardFunctionality() {
    const testName = "Dashboard Functionality";
    console.log(`Running test: ${testName}`);

    try {
      // Navigate to dashboard
      await this.driver.get(`${this.baseUrl}/index.php`);

      // Wait for dashboard to load
      await this.driver.wait(until.titleContains("MDM Dashboard"), 10000);

      // Test dashboard elements
      const dashboardTitle = await this.driver
        .findElement(By.css("h1"))
        .getText();
      assert(
        dashboardTitle.includes("MDM"),
        "Dashboard title should contain MDM"
      );

      // Test statistics widgets
      const statsWidgets = await this.driver.findElements(
        By.css(".stat-widget")
      );
      assert(
        statsWidgets.length >= 4,
        "Should have at least 4 statistics widgets"
      );

      // Test navigation menu
      const navItems = await this.driver.findElements(By.css(".nav-menu a"));
      assert(navItems.length >= 4, "Should have navigation menu items");

      // Test data quality metrics display
      const qualityMetrics = await this.driver.findElement(
        By.css(".quality-metrics")
      );
      assert(
        await qualityMetrics.isDisplayed(),
        "Quality metrics should be visible"
      );

      // Test real-time updates (check if data refreshes)
      const initialValue = await this.driver
        .findElement(By.css(".total-products .value"))
        .getText();
      await this.driver.sleep(2000);
      const updatedValue = await this.driver
        .findElement(By.css(".total-products .value"))
        .getText();
      // Note: In a real test, we might trigger an update and verify the change

      this.testResults.push({
        test: testName,
        status: "PASSED",
        message: "Dashboard functionality working correctly",
      });
    } catch (error) {
      this.testResults.push({
        test: testName,
        status: "FAILED",
        message: error.message,
      });
      throw error;
    }
  }

  /**
   * Test verification interface
   * Requirements: 4.2, 4.3, 3.4
   */
  async testVerificationInterface() {
    const testName = "Verification Interface";
    console.log(`Running test: ${testName}`);

    try {
      // Navigate to verification page
      await this.driver.get(`${this.baseUrl}/verification.php`);

      // Wait for verification page to load
      await this.driver.wait(
        until.elementLocated(By.css(".verification-container")),
        10000
      );

      // Test pending items list
      const pendingItems = await this.driver.findElements(
        By.css(".pending-item")
      );
      console.log(`Found ${pendingItems.length} pending verification items`);

      if (pendingItems.length > 0) {
        // Test first pending item
        const firstItem = pendingItems[0];

        // Test item details display
        const itemName = await firstItem
          .findElement(By.css(".item-name"))
          .getText();
        assert(itemName.length > 0, "Item name should be displayed");

        const confidenceScore = await firstItem
          .findElement(By.css(".confidence-score"))
          .getText();
        assert(
          confidenceScore.includes("%"),
          "Confidence score should be displayed as percentage"
        );

        // Test suggested matches
        const suggestedMatches = await firstItem.findElements(
          By.css(".suggested-match")
        );
        assert(suggestedMatches.length > 0, "Should show suggested matches");

        // Test action buttons
        const approveBtn = await firstItem.findElement(By.css(".btn-approve"));
        const rejectBtn = await firstItem.findElement(By.css(".btn-reject"));
        const createNewBtn = await firstItem.findElement(
          By.css(".btn-create-new")
        );

        assert(
          await approveBtn.isDisplayed(),
          "Approve button should be visible"
        );
        assert(
          await rejectBtn.isDisplayed(),
          "Reject button should be visible"
        );
        assert(
          await createNewBtn.isDisplayed(),
          "Create new button should be visible"
        );

        // Test approve action
        await approveBtn.click();

        // Wait for confirmation dialog
        await this.driver.wait(until.alertIsPresent(), 5000);
        const alert = await this.driver.switchTo().alert();
        await alert.accept();

        // Verify item was processed
        await this.driver.wait(until.stalenessOf(firstItem), 10000);
      }

      // Test search and filter functionality
      const searchBox = await this.driver.findElement(By.css(".search-box"));
      await searchBox.sendKeys("test product");
      await searchBox.sendKeys(Key.ENTER);

      // Wait for search results
      await this.driver.sleep(2000);

      // Test filter options
      const filterDropdown = await this.driver.findElement(
        By.css(".filter-dropdown")
      );
      await filterDropdown.click();

      const filterOptions = await this.driver.findElements(
        By.css(".filter-option")
      );
      assert(filterOptions.length > 0, "Should have filter options");

      this.testResults.push({
        test: testName,
        status: "PASSED",
        message: "Verification interface working correctly",
      });
    } catch (error) {
      this.testResults.push({
        test: testName,
        status: "FAILED",
        message: error.message,
      });
      throw error;
    }
  }

  /**
   * Test master products management
   * Requirements: 4.4, 6.3
   */
  async testMasterProductsManagement() {
    const testName = "Master Products Management";
    console.log(`Running test: ${testName}`);

    try {
      // Navigate to products page
      await this.driver.get(`${this.baseUrl}/products.php`);

      // Wait for products page to load
      await this.driver.wait(
        until.elementLocated(By.css(".products-container")),
        10000
      );

      // Test products list
      const productRows = await this.driver.findElements(
        By.css(".product-row")
      );
      console.log(`Found ${productRows.length} master products`);

      // Test search functionality
      const searchInput = await this.driver.findElement(
        By.css(".product-search")
      );
      await searchInput.sendKeys("test");
      await searchInput.sendKeys(Key.ENTER);

      await this.driver.sleep(2000);

      // Test create new product
      const createBtn = await this.driver.findElement(
        By.css(".btn-create-product")
      );
      await createBtn.click();

      // Wait for create form
      await this.driver.wait(
        until.elementLocated(By.css(".create-product-form")),
        5000
      );

      // Fill in product details
      await this.driver
        .findElement(By.css("#product-name"))
        .sendKeys("Test Product UI");
      await this.driver
        .findElement(By.css("#product-brand"))
        .sendKeys("Test Brand UI");
      await this.driver
        .findElement(By.css("#product-category"))
        .sendKeys("Test Category UI");
      await this.driver
        .findElement(By.css("#product-description"))
        .sendKeys("Test description for UI testing");

      // Submit form
      const submitBtn = await this.driver.findElement(By.css(".btn-submit"));
      await submitBtn.click();

      // Wait for success message
      await this.driver.wait(
        until.elementLocated(By.css(".success-message")),
        10000
      );

      // Verify product was created
      const successMessage = await this.driver
        .findElement(By.css(".success-message"))
        .getText();
      assert(successMessage.includes("created"), "Should show success message");

      // Test edit functionality
      const editBtns = await this.driver.findElements(By.css(".btn-edit"));
      if (editBtns.length > 0) {
        await editBtns[0].click();

        // Wait for edit form
        await this.driver.wait(
          until.elementLocated(By.css(".edit-product-form")),
          5000
        );

        // Modify product name
        const nameField = await this.driver.findElement(
          By.css("#edit-product-name")
        );
        await nameField.clear();
        await nameField.sendKeys("Modified Test Product UI");

        // Save changes
        const saveBtn = await this.driver.findElement(By.css(".btn-save"));
        await saveBtn.click();

        // Wait for update confirmation
        await this.driver.wait(
          until.elementLocated(By.css(".success-message")),
          10000
        );
      }

      // Test bulk operations
      const checkboxes = await this.driver.findElements(
        By.css(".product-checkbox")
      );
      if (checkboxes.length >= 2) {
        // Select multiple products
        await checkboxes[0].click();
        await checkboxes[1].click();

        // Test bulk update
        const bulkUpdateBtn = await this.driver.findElement(
          By.css(".btn-bulk-update")
        );
        await bulkUpdateBtn.click();

        // Wait for bulk update form
        await this.driver.wait(
          until.elementLocated(By.css(".bulk-update-form")),
          5000
        );

        // Cancel bulk update
        const cancelBtn = await this.driver.findElement(By.css(".btn-cancel"));
        await cancelBtn.click();
      }

      this.testResults.push({
        test: testName,
        status: "PASSED",
        message: "Master products management working correctly",
      });
    } catch (error) {
      this.testResults.push({
        test: testName,
        status: "FAILED",
        message: error.message,
      });
      throw error;
    }
  }

  /**
   * Test reports functionality
   * Requirements: 5.1, 5.2, 5.4
   */
  async testReportsFunctionality() {
    const testName = "Reports Functionality";
    console.log(`Running test: ${testName}`);

    try {
      // Navigate to reports page
      await this.driver.get(`${this.baseUrl}/reports.php`);

      // Wait for reports page to load
      await this.driver.wait(
        until.elementLocated(By.css(".reports-container")),
        10000
      );

      // Test report types
      const reportTabs = await this.driver.findElements(By.css(".report-tab"));
      assert(reportTabs.length >= 3, "Should have multiple report types");

      // Test data quality report
      const qualityReportTab = await this.driver.findElement(
        By.css('[data-report="quality"]')
      );
      await qualityReportTab.click();

      await this.driver.sleep(2000);

      // Verify quality report content
      const qualityMetrics = await this.driver.findElements(
        By.css(".quality-metric")
      );
      assert(qualityMetrics.length > 0, "Should display quality metrics");

      // Test completeness report
      const completenessTab = await this.driver.findElement(
        By.css('[data-report="completeness"]')
      );
      await completenessTab.click();

      await this.driver.sleep(2000);

      // Verify completeness report
      const completenessChart = await this.driver.findElement(
        By.css(".completeness-chart")
      );
      assert(
        await completenessChart.isDisplayed(),
        "Completeness chart should be visible"
      );

      // Test export functionality
      const exportBtn = await this.driver.findElement(By.css(".btn-export"));
      await exportBtn.click();

      // Wait for export options
      await this.driver.wait(
        until.elementLocated(By.css(".export-options")),
        5000
      );

      const exportFormats = await this.driver.findElements(
        By.css(".export-format")
      );
      assert(exportFormats.length >= 2, "Should have multiple export formats");

      // Test CSV export
      const csvExport = await this.driver.findElement(
        By.css('[data-format="csv"]')
      );
      await csvExport.click();

      // Note: In a real test, we would verify the download started

      // Test date range filter
      const dateFromInput = await this.driver.findElement(By.css("#date-from"));
      const dateToInput = await this.driver.findElement(By.css("#date-to"));

      await dateFromInput.sendKeys("2024-01-01");
      await dateToInput.sendKeys("2024-12-31");

      const applyFilterBtn = await this.driver.findElement(
        By.css(".btn-apply-filter")
      );
      await applyFilterBtn.click();

      await this.driver.sleep(2000);

      this.testResults.push({
        test: testName,
        status: "PASSED",
        message: "Reports functionality working correctly",
      });
    } catch (error) {
      this.testResults.push({
        test: testName,
        status: "FAILED",
        message: error.message,
      });
      throw error;
    }
  }

  /**
   * Test responsive design and mobile compatibility
   * Requirements: 4.1, 4.2, 4.3, 4.4
   */
  async testResponsiveDesign() {
    const testName = "Responsive Design";
    console.log(`Running test: ${testName}`);

    try {
      // Test different screen sizes
      const screenSizes = [
        { width: 1920, height: 1080, name: "Desktop" },
        { width: 1024, height: 768, name: "Tablet" },
        { width: 375, height: 667, name: "Mobile" },
      ];

      for (const size of screenSizes) {
        console.log(`Testing ${size.name} view (${size.width}x${size.height})`);

        await this.driver.manage().window().setRect({
          width: size.width,
          height: size.height,
        });

        // Navigate to dashboard
        await this.driver.get(`${this.baseUrl}/index.php`);
        await this.driver.sleep(2000);

        // Test navigation visibility
        const navMenu = await this.driver.findElement(By.css(".nav-menu"));
        const isNavVisible = await navMenu.isDisplayed();

        if (size.width < 768) {
          // Mobile: check for hamburger menu
          const hamburgerMenu = await this.driver.findElements(
            By.css(".hamburger-menu")
          );
          assert(
            hamburgerMenu.length > 0,
            "Should have hamburger menu on mobile"
          );
        } else {
          // Desktop/Tablet: navigation should be visible
          assert(
            isNavVisible,
            "Navigation should be visible on larger screens"
          );
        }

        // Test content layout
        const mainContent = await this.driver.findElement(
          By.css(".main-content")
        );
        const contentRect = await mainContent.getRect();
        assert(contentRect.width > 0, "Main content should have width");

        // Test widgets responsiveness
        const widgets = await this.driver.findElements(By.css(".stat-widget"));
        for (const widget of widgets) {
          const widgetRect = await widget.getRect();
          assert(widgetRect.width > 0, "Widgets should have width");
        }
      }

      // Reset to default size
      await this.driver.manage().window().maximize();

      this.testResults.push({
        test: testName,
        status: "PASSED",
        message: "Responsive design working correctly",
      });
    } catch (error) {
      this.testResults.push({
        test: testName,
        status: "FAILED",
        message: error.message,
      });
      throw error;
    }
  }

  /**
   * Test error handling and user feedback
   * Requirements: 4.1, 4.2, 4.3, 4.4
   */
  async testErrorHandlingAndFeedback() {
    const testName = "Error Handling and User Feedback";
    console.log(`Running test: ${testName}`);

    try {
      // Test form validation
      await this.driver.get(`${this.baseUrl}/products.php`);

      // Try to create product with empty fields
      const createBtn = await this.driver.findElement(
        By.css(".btn-create-product")
      );
      await createBtn.click();

      await this.driver.wait(
        until.elementLocated(By.css(".create-product-form")),
        5000
      );

      // Submit empty form
      const submitBtn = await this.driver.findElement(By.css(".btn-submit"));
      await submitBtn.click();

      // Check for validation errors
      const errorMessages = await this.driver.findElements(
        By.css(".error-message")
      );
      assert(errorMessages.length > 0, "Should show validation errors");

      // Test network error handling (simulate by navigating to non-existent endpoint)
      await this.driver.get(`${this.baseUrl}/non-existent-page.php`);

      // Should show error page or redirect
      const pageTitle = await this.driver.getTitle();
      assert(
        pageTitle.includes("Error") || pageTitle.includes("404"),
        "Should handle non-existent pages"
      );

      // Test success feedback
      await this.driver.get(`${this.baseUrl}/products.php`);

      const createBtn2 = await this.driver.findElement(
        By.css(".btn-create-product")
      );
      await createBtn2.click();

      await this.driver.wait(
        until.elementLocated(By.css(".create-product-form")),
        5000
      );

      // Fill valid data
      await this.driver
        .findElement(By.css("#product-name"))
        .sendKeys("Valid Product Name");
      await this.driver
        .findElement(By.css("#product-brand"))
        .sendKeys("Valid Brand");
      await this.driver
        .findElement(By.css("#product-category"))
        .sendKeys("Valid Category");

      const submitBtn2 = await this.driver.findElement(By.css(".btn-submit"));
      await submitBtn2.click();

      // Check for success message
      await this.driver.wait(
        until.elementLocated(By.css(".success-message")),
        10000
      );
      const successMessage = await this.driver.findElement(
        By.css(".success-message")
      );
      assert(await successMessage.isDisplayed(), "Should show success message");

      this.testResults.push({
        test: testName,
        status: "PASSED",
        message: "Error handling and user feedback working correctly",
      });
    } catch (error) {
      this.testResults.push({
        test: testName,
        status: "FAILED",
        message: error.message,
      });
      throw error;
    }
  }

  /**
   * Run all UI tests
   */
  async runAllTests() {
    console.log("Starting MDM UI Automation Tests...");

    await this.setUp();

    try {
      await this.testDashboardFunctionality();
      await this.testVerificationInterface();
      await this.testMasterProductsManagement();
      await this.testReportsFunctionality();
      await this.testResponsiveDesign();
      await this.testErrorHandlingAndFeedback();

      console.log("All UI tests completed successfully!");
    } catch (error) {
      console.error("UI test failed:", error.message);
    } finally {
      await this.tearDown();
    }
  }

  generateTestReport() {
    const report = {
      timestamp: new Date().toISOString(),
      total_tests: this.testResults.length,
      passed_tests: this.testResults.filter((r) => r.status === "PASSED")
        .length,
      failed_tests: this.testResults.filter((r) => r.status === "FAILED")
        .length,
      results: this.testResults,
    };

    const reportPath = `./tests/results/mdm_ui_test_report_${Date.now()}.json`;
    require("fs").writeFileSync(reportPath, JSON.stringify(report, null, 2));

    console.log(`\nUI Test Report generated: ${reportPath}`);
    console.log(
      `Total: ${report.total_tests}, Passed: ${report.passed_tests}, Failed: ${report.failed_tests}`
    );
  }
}

// Export for use in test runner
module.exports = MDMUIAutomationTest;

// Run tests if called directly
if (require.main === module) {
  const testRunner = new MDMUIAutomationTest();
  testRunner.runAllTests().catch(console.error);
}
