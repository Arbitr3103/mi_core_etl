# 📊 Warehouse Dashboard - Production User Manual

**Version:** 1.0.0  
**Production URL:** https://www.market-mi.ru/warehouse-dashboard  
**Date:** October 23, 2025

## 🚀 Quick Access

### Production Links

-   **Main Dashboard:** https://www.market-mi.ru/warehouse-dashboard
-   **API Health Check:** https://www.market-mi.ru/api/monitoring.php?action=health
-   **System Status:** https://www.market-mi.ru/api/monitoring.php?action=status

### Browser Requirements

-   **Recommended:** Chrome 90+, Firefox 88+, Safari 14+, Edge 90+
-   **Mobile Support:** iOS Safari 14+, Chrome Mobile 90+
-   **JavaScript:** Must be enabled
-   **Cookies:** Must be enabled for session management

## 📋 Getting Started

### First Time Access

1. **Open your web browser**
2. **Navigate to:** https://www.market-mi.ru/warehouse-dashboard
3. **Wait for loading** - Initial load may take 3-5 seconds
4. **Verify functionality** - You should see the dashboard with data

### System Requirements

-   **Internet Connection:** Stable broadband connection
-   **Screen Resolution:** Minimum 1024x768 (recommended 1920x1080)
-   **Browser Cache:** At least 50MB available
-   **Network:** Access to market-mi.ru domain

## 🎯 New Production Features

### Enhanced Performance

-   **Faster Loading:** Optimized for production with CDN
-   **Better Caching:** Improved response times with server-side caching
-   **Mobile Optimization:** Fully responsive design for tablets and phones
-   **Offline Indicators:** Clear status when connection is lost

### Production-Specific Features

#### 1. Real-time Data Updates

-   Data refreshes automatically every hour
-   Manual refresh button for immediate updates
-   Last update timestamp displayed
-   Connection status indicator

#### 2. Enhanced Export Functionality

```
Export Options:
├── CSV Export (All Data)
├── Filtered CSV Export
├── Summary Report
└── Print-Friendly View
```

#### 3. Advanced Filtering

-   **Warehouse Selection:** All Ozon warehouses available
-   **Cluster Filtering:** Regional groupings
-   **Liquidity Status:** Critical, Low, Normal, Excess
-   **Active Products Only:** Focus on items with sales/stock
-   **Replenishment Needed:** Items requiring orders

#### 4. Performance Monitoring

-   **Load Time Tracking:** Page performance metrics
-   **API Response Times:** Backend performance indicators
-   **Error Reporting:** Automatic error logging and reporting

## 📊 Dashboard Overview

### Main Interface Components

```
┌─────────────────────────────────────────────────────────────┐
│  🏭 Warehouse Dashboard - Production                        │
│  📍 https://www.market-mi.ru/warehouse-dashboard            │
│                                                              │
│  📊 Summary Stats                                           │
│  Total Products: 271 | Active: 180 | Updated: 14:30        │
│  🔴 Critical: 15 | 🟡 Low: 30 | 🟢 Normal: 120 | 🔵 Excess: 15 │
│                                                              │
│  🔧 Controls                                                │
│  [🔄 Refresh] [📥 Export CSV] [📊 Summary] [🖨️ Print]      │
│                                                              │
│  🔍 Filters                                                 │
│  Warehouse: [All ▼] Cluster: [All ▼] Status: [All ▼]      │
│  ☑️ Active Only  ☐ Replenishment Needed                    │
│                                                              │
│  📋 Data Table                                              │
│  [Product | Warehouse | Available | Sales/Day | Days | Need | Status] │
│  [Sortable columns with pagination]                         │
└─────────────────────────────────────────────────────────────┘
```

### Status Indicators

#### Connection Status

-   🟢 **Online** - Connected to server
-   🟡 **Slow** - Connection issues detected
-   🔴 **Offline** - No connection to server

#### Data Freshness

-   🟢 **Fresh** - Updated within last hour
-   🟡 **Stale** - Updated 1-2 hours ago
-   🔴 **Old** - Updated more than 2 hours ago

#### Liquidity Status Colors

-   🔴 **Critical** - Less than 7 days stock
-   🟡 **Low** - 7-14 days stock
-   🟢 **Normal** - 15-45 days stock
-   🔵 **Excess** - More than 45 days stock

## 🔧 Production Operations

### Daily Workflow

#### Morning Check (9:00 AM)

1. **Open Dashboard:** https://www.market-mi.ru/warehouse-dashboard
2. **Check Critical Items:** Filter by "Critical" status
3. **Review Urgent Orders:** Items with 🔥 indicator
4. **Export Critical List:** For procurement team

#### Midday Review (1:00 PM)

1. **Refresh Data:** Click refresh button
2. **Check Low Stock:** Filter by "Low" status
3. **Plan Afternoon Orders:** Review replenishment needs

#### End of Day (5:00 PM)

1. **Final Check:** Review all critical items
2. **Export Summary:** Daily report for management
3. **Plan Tomorrow:** Identify items needing attention

### Weekly Planning

#### Monday - Planning Session

1. **Full Export:** All data for weekly analysis
2. **Trend Analysis:** Compare with previous week
3. **Budget Planning:** Calculate procurement needs
4. **Team Briefing:** Share insights with team

#### Wednesday - Mid-week Check

1. **Progress Review:** Check order fulfillment
2. **Adjust Plans:** Modify based on new data
3. **Emergency Orders:** Handle critical shortages

#### Friday - Week Wrap-up

1. **Performance Review:** Analyze week's performance
2. **Weekend Planning:** Prepare for next week
3. **Report Generation:** Weekly summary for management

## 📥 Export and Reporting

### CSV Export Options

#### Standard Export

-   **Button:** "Export CSV" in header
-   **Content:** All visible data based on current filters
-   **Format:** Excel-compatible CSV
-   **Filename:** `warehouse_dashboard_YYYY-MM-DD_HH-MM-SS.csv`

#### Advanced Export Features

```bash
# Export includes:
- Product information (SKU, name)
- Warehouse details (name, cluster)
- Stock levels (available, in-transit)
- Sales metrics (daily average, trends)
- Replenishment calculations
- Liquidity analysis
- Last update timestamps
```

### Report Templates

#### Daily Critical Items Report

1. Filter: Status = "Critical"
2. Filter: Replenishment Needed = Yes
3. Export CSV
4. Use for urgent procurement decisions

#### Weekly Procurement Planning Report

1. Filter: Status = "Critical" OR "Low"
2. Export CSV
3. Analyze in Excel for budget planning
4. Share with procurement team

#### Monthly Inventory Analysis

1. No filters (all data)
2. Export CSV
3. Create pivot tables for trend analysis
4. Generate management presentation

## 🔍 Advanced Features

### Search and Filtering

#### Quick Filters

-   **Warehouse Dropdown:** Select specific warehouse
-   **Cluster Dropdown:** Filter by region
-   **Status Dropdown:** Filter by liquidity status
-   **Active Only Checkbox:** Show only active products
-   **Replenishment Checkbox:** Show only items needing orders

#### Advanced Search

-   **Product Search:** Type product name or SKU
-   **Multi-select Filters:** Hold Ctrl to select multiple options
-   **Range Filters:** Set minimum/maximum values for metrics

### Sorting and Pagination

#### Column Sorting

-   **Click Header:** Sort by any column
-   **Double Click:** Reverse sort order
-   **Shift+Click:** Multi-column sorting

#### Pagination Controls

-   **Items per Page:** 25, 50, 100, 200 options
-   **Page Navigation:** First, Previous, Next, Last
-   **Jump to Page:** Direct page number input

### Performance Features

#### Lazy Loading

-   Data loads as you scroll
-   Improves performance with large datasets
-   Smooth scrolling experience

#### Virtual Scrolling

-   Renders only visible rows
-   Handles thousands of products efficiently
-   Maintains responsive interface

## 📱 Mobile Usage

### Mobile Interface

#### Responsive Design

-   **Tablet View:** Full functionality maintained
-   **Phone View:** Optimized layout for small screens
-   **Touch Friendly:** Large buttons and touch targets

#### Mobile-Specific Features

-   **Swipe Navigation:** Swipe between sections
-   **Touch Sorting:** Tap headers to sort
-   **Pinch Zoom:** Zoom in on data tables
-   **Offline Mode:** Basic functionality when offline

### Mobile Workflow

#### Quick Check on Phone

1. Open browser on phone
2. Navigate to dashboard URL
3. Use filters to find critical items
4. Make quick decisions on urgent orders

#### Tablet Analysis

1. Use tablet for detailed analysis
2. Export data for offline review
3. Share reports via email
4. Collaborate with team members

## 🚨 Troubleshooting

### Common Issues

#### Dashboard Won't Load

**Symptoms:** Blank page or loading spinner
**Solutions:**

1. Check internet connection
2. Try different browser
3. Clear browser cache
4. Disable browser extensions
5. Contact support if persistent

#### Data Not Updating

**Symptoms:** Old timestamps, stale data
**Solutions:**

1. Click refresh button
2. Hard refresh (Ctrl+F5)
3. Check system status page
4. Wait for next automatic update
5. Contact support if data is >2 hours old

#### Export Not Working

**Symptoms:** CSV download fails
**Solutions:**

1. Check browser download settings
2. Disable popup blockers
3. Try different browser
4. Check available disk space
5. Contact support if persistent

#### Slow Performance

**Symptoms:** Slow loading, laggy interface
**Solutions:**

1. Check internet speed
2. Close other browser tabs
3. Reduce number of displayed items
4. Use filters to limit data
5. Try different browser

### Error Messages

#### "Connection Lost"

-   **Meaning:** Lost connection to server
-   **Action:** Check internet, wait for reconnection
-   **Auto-retry:** System will attempt to reconnect

#### "Data Load Failed"

-   **Meaning:** Server error loading data
-   **Action:** Refresh page, check system status
-   **Escalation:** Contact support if persistent

#### "Export Failed"

-   **Meaning:** CSV generation failed
-   **Action:** Try again, check filters
-   **Workaround:** Use print function as alternative

## 📞 Support and Help

### Getting Help

#### Self-Service Resources

1. **This Manual:** Comprehensive usage guide
2. **System Status:** Check operational status
3. **FAQ Section:** Common questions answered
4. **Video Tutorials:** Step-by-step guides (if available)

#### Contact Support

-   **Email:** support@market-mi.ru
-   **Subject Line:** "Warehouse Dashboard - [Issue Description]"
-   **Include:** Screenshots, error messages, steps taken

#### Emergency Support

-   **Critical Issues:** System completely down
-   **Phone:** +X-XXX-XXX-XXXX (business hours)
-   **Response Time:** Within 2 hours during business hours

### Information to Provide

When contacting support, include:

1. **URL:** https://www.market-mi.ru/warehouse-dashboard
2. **Browser:** Chrome/Firefox/Safari version
3. **Device:** Desktop/tablet/mobile
4. **Error Message:** Exact text of any errors
5. **Screenshots:** Visual evidence of issue
6. **Steps:** What you were doing when issue occurred
7. **Time:** When the issue started

## 📈 Best Practices

### Daily Usage

#### Efficient Workflow

1. **Start with Critical:** Always check critical items first
2. **Use Filters:** Don't load all data unnecessarily
3. **Export Regularly:** Keep historical records
4. **Monitor Trends:** Look for patterns in data

#### Data Interpretation

1. **Context Matters:** Consider seasonality and trends
2. **Verify Calculations:** Double-check important decisions
3. **Cross-Reference:** Compare with other data sources
4. **Document Decisions:** Keep records of actions taken

### Performance Optimization

#### Browser Settings

1. **Enable JavaScript:** Required for functionality
2. **Allow Cookies:** Needed for session management
3. **Update Browser:** Use latest version for best performance
4. **Clear Cache:** Regularly clear browser cache

#### Network Optimization

1. **Stable Connection:** Use reliable internet connection
2. **Avoid Peak Hours:** System may be slower during peak usage
3. **Close Unused Tabs:** Free up browser resources
4. **Use Wired Connection:** More stable than WiFi when possible

## 🔄 Updates and Changes

### Version History

#### Version 1.0.0 (October 2025)

-   Initial production release
-   Full warehouse dashboard functionality
-   Real-time data updates
-   Export capabilities
-   Mobile responsive design

### Upcoming Features

#### Planned Enhancements

-   **Advanced Analytics:** Trend analysis and forecasting
-   **Custom Alerts:** Email notifications for critical items
-   **API Integration:** Direct integration with procurement systems
-   **Bulk Actions:** Mass update capabilities

#### Feature Requests

To request new features:

1. Email: features@market-mi.ru
2. Subject: "Feature Request - Warehouse Dashboard"
3. Include: Detailed description and business justification

## 📚 Additional Resources

### Documentation Links

-   **API Documentation:** Technical API reference
-   **System Architecture:** Technical system overview
-   **Deployment Guide:** For system administrators
-   **Troubleshooting Guide:** Detailed problem resolution

### Training Materials

-   **Video Tutorials:** Step-by-step usage guides
-   **Webinar Recordings:** Training session recordings
-   **Quick Reference Cards:** Printable reference guides
-   **FAQ Database:** Searchable question database

### Related Systems

-   **Main MI Core System:** https://www.market-mi.ru
-   **Ozon Analytics:** Marketplace performance data
-   **Inventory Management:** Stock level monitoring
-   **Procurement System:** Order management integration

---

## 📝 Quick Reference

### Essential URLs

-   **Dashboard:** https://www.market-mi.ru/warehouse-dashboard
-   **Health Check:** https://www.market-mi.ru/api/monitoring.php?action=health
-   **Support:** support@market-mi.ru

### Key Shortcuts

-   **F5:** Refresh page
-   **Ctrl+F:** Find on page
-   **Ctrl+P:** Print
-   **Ctrl+S:** Save/Export

### Status Colors

-   🔴 **Critical:** <7 days stock
-   🟡 **Low:** 7-14 days stock
-   🟢 **Normal:** 15-45 days stock
-   🔵 **Excess:** >45 days stock

### Support Priority

-   **P1 Critical:** System down - 2 hour response
-   **P2 High:** Major functionality broken - 4 hour response
-   **P3 Medium:** Minor issues - 1 business day response
-   **P4 Low:** Enhancement requests - 3 business days response

---

**Document Version:** 1.0.0  
**Last Updated:** October 23, 2025  
**Next Review:** November 23, 2025  
**Maintained By:** MI Core Team

_This manual is specific to the production environment. For development or testing environments, refer to the appropriate documentation._
