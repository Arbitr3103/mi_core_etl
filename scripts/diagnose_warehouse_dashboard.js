#!/usr/bin/env node

/**
 * Diagnostic Script for Warehouse Dashboard Production
 *
 * This script checks the production deployment at https://www.market-mi.ru/warehouse-dashboard
 * and verifies:
 * - HTML loads correctly
 * - All CSS files load with 200 status
 * - All JavaScript files load correctly
 * - Identifies any 404 or error responses
 */

const https = require("https");
const http = require("http");
const { URL } = require("url");

const PRODUCTION_URL = "https://www.market-mi.ru/warehouse-dashboard";
const results = {
  html: null,
  css: [],
  js: [],
  errors: [],
  warnings: [],
};

/**
 * Fetch a URL and return response details
 */
function fetchUrl(url) {
  return new Promise((resolve, reject) => {
    const urlObj = new URL(url);
    const protocol = urlObj.protocol === "https:" ? https : http;

    const options = {
      hostname: urlObj.hostname,
      port: urlObj.port,
      path: urlObj.pathname + urlObj.search,
      method: "GET",
      headers: {
        "User-Agent": "Mozilla/5.0 (Diagnostic Script)",
      },
    };

    const req = protocol.request(options, (res) => {
      let data = "";

      res.on("data", (chunk) => {
        data += chunk;
      });

      res.on("end", () => {
        resolve({
          url,
          status: res.statusCode,
          headers: res.headers,
          body: data,
          size: Buffer.byteLength(data),
        });
      });
    });

    req.on("error", (error) => {
      reject({ url, error: error.message });
    });

    req.setTimeout(10000, () => {
      req.destroy();
      reject({ url, error: "Request timeout" });
    });

    req.end();
  });
}

/**
 * Extract asset URLs from HTML
 */
function extractAssets(html, baseUrl) {
  const assets = {
    css: [],
    js: [],
  };

  // Extract CSS links
  const cssRegex = /<link[^>]+href=["']([^"']+\.css[^"']*)["'][^>]*>/gi;
  let match;
  while ((match = cssRegex.exec(html)) !== null) {
    const href = match[1];
    const fullUrl = href.startsWith("http")
      ? href
      : new URL(href, baseUrl).href;
    assets.css.push(fullUrl);
  }

  // Extract JS scripts
  const jsRegex = /<script[^>]+src=["']([^"']+\.js[^"']*)["'][^>]*>/gi;
  while ((match = jsRegex.exec(html)) !== null) {
    const src = match[1];
    const fullUrl = src.startsWith("http") ? src : new URL(src, baseUrl).href;
    assets.js.push(fullUrl);
  }

  return assets;
}

/**
 * Check if Tailwind CSS is present
 */
function checkTailwindPresence(cssContent) {
  // Look for Tailwind-specific patterns
  const tailwindPatterns = [
    /\.flex\s*{/,
    /\.grid\s*{/,
    /\.bg-gray-/,
    /\.text-gray-/,
    /\.rounded-/,
    /\.shadow-/,
  ];

  return tailwindPatterns.some((pattern) => pattern.test(cssContent));
}

/**
 * Main diagnostic function
 */
async function runDiagnostics() {
  console.log("🔍 Starting Warehouse Dashboard Diagnostics...\n");
  console.log(`Target URL: ${PRODUCTION_URL}\n`);
  console.log("=".repeat(60));

  try {
    // Step 1: Fetch main HTML
    console.log("\n📄 Fetching main HTML page...");
    const htmlResponse = await fetchUrl(PRODUCTION_URL);

    if (htmlResponse.status === 200) {
      console.log(`✅ HTML loaded successfully (${htmlResponse.status})`);
      console.log(`   Size: ${(htmlResponse.size / 1024).toFixed(2)} KB`);
      results.html = {
        status: htmlResponse.status,
        size: htmlResponse.size,
        success: true,
      };
    } else {
      console.log(`❌ HTML load failed (${htmlResponse.status})`);
      results.errors.push(`HTML returned status ${htmlResponse.status}`);
      results.html = {
        status: htmlResponse.status,
        success: false,
      };
    }

    // Step 2: Extract and check CSS files
    console.log("\n🎨 Checking CSS files...");
    const assets = extractAssets(htmlResponse.body, PRODUCTION_URL);

    if (assets.css.length === 0) {
      console.log("⚠️  No CSS files found in HTML");
      results.warnings.push("No CSS files found in HTML");
    } else {
      console.log(`Found ${assets.css.length} CSS file(s)`);

      for (const cssUrl of assets.css) {
        try {
          const cssResponse = await fetchUrl(cssUrl);
          const status = cssResponse.status === 200 ? "✅" : "❌";
          const sizeKB = (cssResponse.size / 1024).toFixed(2);

          console.log(`${status} ${cssUrl}`);
          console.log(`   Status: ${cssResponse.status}, Size: ${sizeKB} KB`);

          if (cssResponse.status === 200) {
            // Check if it's actually CSS content
            if (cssResponse.size === 0) {
              console.log("   ⚠️  WARNING: CSS file is empty (0 bytes)");
              results.warnings.push(`CSS file is empty: ${cssUrl}`);
            }

            // Check for Tailwind CSS
            const hasTailwind = checkTailwindPresence(cssResponse.body);
            if (hasTailwind) {
              console.log("   ✅ Tailwind CSS classes detected");
            } else {
              console.log("   ⚠️  Tailwind CSS classes not detected");
              results.warnings.push(`Tailwind CSS not detected in: ${cssUrl}`);
            }
          } else if (cssResponse.status === 404) {
            results.errors.push(`CSS file not found (404): ${cssUrl}`);
          } else {
            results.errors.push(
              `CSS file error (${cssResponse.status}): ${cssUrl}`
            );
          }

          results.css.push({
            url: cssUrl,
            status: cssResponse.status,
            size: cssResponse.size,
            hasTailwind: hasTailwind,
          });
        } catch (error) {
          console.log(`❌ ${cssUrl}`);
          console.log(`   Error: ${error.error || error.message}`);
          results.errors.push(
            `CSS fetch error: ${cssUrl} - ${error.error || error.message}`
          );
          results.css.push({
            url: cssUrl,
            error: error.error || error.message,
          });
        }
      }
    }

    // Step 3: Check JavaScript files
    console.log("\n📜 Checking JavaScript files...");

    if (assets.js.length === 0) {
      console.log("⚠️  No JavaScript files found in HTML");
      results.warnings.push("No JavaScript files found in HTML");
    } else {
      console.log(`Found ${assets.js.length} JavaScript file(s)`);

      for (const jsUrl of assets.js) {
        try {
          const jsResponse = await fetchUrl(jsUrl);
          const status = jsResponse.status === 200 ? "✅" : "❌";
          const sizeKB = (jsResponse.size / 1024).toFixed(2);

          console.log(`${status} ${jsUrl}`);
          console.log(`   Status: ${jsResponse.status}, Size: ${sizeKB} KB`);

          if (jsResponse.status === 200) {
            if (jsResponse.size === 0) {
              console.log("   ⚠️  WARNING: JavaScript file is empty (0 bytes)");
              results.warnings.push(`JavaScript file is empty: ${jsUrl}`);
            }
          } else if (jsResponse.status === 404) {
            results.errors.push(`JavaScript file not found (404): ${jsUrl}`);
          } else {
            results.errors.push(
              `JavaScript file error (${jsResponse.status}): ${jsUrl}`
            );
          }

          results.js.push({
            url: jsUrl,
            status: jsResponse.status,
            size: jsResponse.size,
          });
        } catch (error) {
          console.log(`❌ ${jsUrl}`);
          console.log(`   Error: ${error.error || error.message}`);
          results.errors.push(
            `JavaScript fetch error: ${jsUrl} - ${error.error || error.message}`
          );
          results.js.push({
            url: jsUrl,
            error: error.error || error.message,
          });
        }
      }
    }

    // Step 4: Summary
    console.log("\n" + "=".repeat(60));
    console.log("\n📊 DIAGNOSTIC SUMMARY\n");

    console.log(`HTML Status: ${results.html.success ? "✅ OK" : "❌ FAILED"}`);
    console.log(
      `CSS Files: ${results.css.filter((c) => c.status === 200).length}/${
        results.css.length
      } loaded`
    );
    console.log(
      `JS Files: ${results.js.filter((j) => j.status === 200).length}/${
        results.js.length
      } loaded`
    );
    console.log(`Errors: ${results.errors.length}`);
    console.log(`Warnings: ${results.warnings.length}`);

    if (results.errors.length > 0) {
      console.log("\n❌ ERRORS:");
      results.errors.forEach((error, i) => {
        console.log(`   ${i + 1}. ${error}`);
      });
    }

    if (results.warnings.length > 0) {
      console.log("\n⚠️  WARNINGS:");
      results.warnings.forEach((warning, i) => {
        console.log(`   ${i + 1}. ${warning}`);
      });
    }

    // Save results to file
    const fs = require("fs");
    const timestamp = new Date().toISOString().replace(/[:.]/g, "-");
    const reportPath = `diagnostic_report_${timestamp}.json`;
    fs.writeFileSync(reportPath, JSON.stringify(results, null, 2));
    console.log(`\n💾 Full report saved to: ${reportPath}`);
  } catch (error) {
    console.error("\n❌ Fatal error during diagnostics:", error);
    process.exit(1);
  }
}

// Run diagnostics
runDiagnostics()
  .then(() => {
    console.log("\n✅ Diagnostics complete!\n");
    process.exit(results.errors.length > 0 ? 1 : 0);
  })
  .catch((error) => {
    console.error("\n❌ Unexpected error:", error);
    process.exit(1);
  });
