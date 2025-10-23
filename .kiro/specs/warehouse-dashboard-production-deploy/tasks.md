# Production Deployment Tasks

## Phase 1: Pre-deployment Preparation

-   [ ] 1. Code Quality & Testing

    -   [ ] 1.1 Run final verification tests

        -   Execute `bash .kiro/specs/warehouse-dashboard/verify_implementation.sh`
        -   Verify all 51 checks pass
        -   _Requirements: All_

    -   [ ] 1.2 Run comprehensive test suite

        -   Execute backend unit tests: `vendor/bin/phpunit tests/Unit/`
        -   Execute frontend tests: `cd frontend && npm test -- --run`
        -   Execute integration tests
        -   _Requirements: All_

    -   [ ] 1.3 Test production build locally
        -   Run `cd frontend && npm run build`
        -   Test built files serve correctly
        -   Verify API endpoints work with build
        -   _Requirements: 2.1, 2.2, 2.3_

## Phase 2: Git Repository Management

-   [ ] 2. Version Control Preparation

    -   [ ] 2.1 Stage all changes

        -   Add all new files: `git add .`
        -   Review changes: `git status`
        -   Verify no sensitive data included
        -   _Requirements: 1.1, 1.2, 1.3_

    -   [ ] 2.2 Create deployment commit

        -   Commit with message: `git commit -m "feat: Add Warehouse Dashboard with full functionality"`
        -   Include detailed commit description
        -   _Requirements: 1.1_

    -   [ ] 2.3 Push to remote repository

        -   Push changes: `git push origin main`
        -   Verify push successful
        -   _Requirements: 1.2_

    -   [ ] 2.4 Create release tag
        -   Tag release: `git tag -a v1.0.0 -m "Warehouse Dashboard v1.0.0"`
        -   Push tag: `git push origin v1.0.0`
        -   _Requirements: 1.2_

## Phase 3: Production Build Creation

-   [ ] 3. Frontend Production Build

    -   [ ] 3.1 Install production dependencies

        -   Run `cd frontend && npm ci --production=false`
        -   Verify all dependencies installed
        -   _Requirements: 2.1_

    -   [ ] 3.2 Create optimized build

        -   Run `cd frontend && npm run build`
        -   Verify build completes without errors
        -   Check build size and optimization
        -   _Requirements: 2.1, 2.2, 2.3_

    -   [ ] 3.3 Test production build locally
        -   Serve build: `cd frontend/dist && python3 -m http.server 8080`
        -   Test dashboard loads at localhost:8080
        -   Verify API calls work (may need CORS adjustment)
        -   _Requirements: 2.1, 2.2_

## Phase 4: Server Preparation

-   [ ] 4. Production Server Setup

    -   [ ] 4.1 Access production server

        -   SSH into server: `ssh user@market-mi.ru`
        -   Verify server access and permissions
        -   Check server specifications (PHP, database, etc.)
        -   _Requirements: 3.1, 3.2, 3.3_

    -   [ ] 4.2 Backup existing data

        -   Backup current website files
        -   Backup production database
        -   Create restore point
        -   _Requirements: 4.1_

    -   [ ] 4.3 Prepare directory structure
        -   Create `/var/www/market-mi.ru/warehouse-dashboard/`
        -   Set proper permissions
        -   Create necessary subdirectories
        -   _Requirements: 3.1, 3.2_

## Phase 5: Database Migration

-   [ ] 5. Production Database Setup

    -   [ ] 5.1 Verify database connection

        -   Test connection to production PostgreSQL
        -   Verify credentials and permissions
        -   Check existing schema
        -   _Requirements: 4.1, 4.2_

    -   [ ] 5.2 Apply database migrations

        -   Run warehouse dashboard schema migration
        -   Create warehouse_sales_metrics table
        -   Add necessary indexes
        -   _Requirements: 4.2, 4.3_

    -   [ ] 5.3 Import initial data
        -   Import Ozon warehouse data using production importer
        -   Run metrics calculation
        -   Verify data integrity
        -   _Requirements: 4.2, 4.3_

## Phase 6: Backend Deployment

-   [ ] 6. PHP Backend Deployment

    -   [ ] 6.1 Upload backend files

        -   Copy `api/` directory to server
        -   Copy `scripts/` directory to server
        -   Copy `config/` directory to server
        -   _Requirements: 3.1_

    -   [ ] 6.2 Configure production environment

        -   Update database credentials in config
        -   Set production API keys
        -   Configure error logging
        -   _Requirements: 3.1, 3.2_

    -   [ ] 6.3 Set file permissions

        -   Set proper permissions for PHP files
        -   Ensure web server can read files
        -   Secure sensitive configuration files
        -   _Requirements: 3.1_

    -   [ ] 6.4 Test backend API
        -   Test API endpoint: `curl https://www.market-mi.ru/api/warehouse-dashboard.php`
        -   Verify JSON response
        -   Test all endpoints (warehouses, clusters, export)
        -   _Requirements: 3.1, 3.2_

## Phase 7: Frontend Deployment

-   [ ] 7. Frontend Static Files Deployment

    -   [ ] 7.1 Upload built frontend files

        -   Copy `frontend/dist/` contents to web root
        -   Ensure index.html is in correct location
        -   Upload all assets (CSS, JS, images)
        -   _Requirements: 3.2_

    -   [ ] 7.2 Configure web server routing

        -   Set up SPA routing (all routes serve index.html)
        -   Configure API proxy or direct routing
        -   Set proper MIME types for assets
        -   _Requirements: 3.2, 3.3_

    -   [ ] 7.3 Update API base URL
        -   Update frontend to use production API URLs
        -   Remove development proxy configuration
        -   Test API calls from frontend
        -   _Requirements: 3.2_

## Phase 8: Web Server Configuration

-   [ ] 8. Apache/Nginx Configuration

    -   [ ] 8.1 Configure virtual host

        -   Set up virtual host for market-mi.ru
        -   Configure document root
        -   Set up proper directory permissions
        -   _Requirements: 3.3, 5.2_

    -   [ ] 8.2 Configure HTTPS

        -   Verify SSL certificate is valid
        -   Force HTTPS redirects
        -   Set security headers
        -   _Requirements: 5.2_

    -   [ ] 8.3 Configure API routing
        -   Set up `/api/` routing to PHP files
        -   Configure PHP-FPM if needed
        -   Test API endpoints through web server
        -   _Requirements: 3.3_

## Phase 9: Production Testing

-   [ ] 9. Comprehensive Production Testing

    -   [ ] 9.1 Test dashboard access

        -   Access https://www.market-mi.ru/warehouse-dashboard
        -   Verify page loads within 3 seconds
        -   Test on multiple browsers
        -   _Requirements: 5.1, 5.2, 5.3_

    -   [ ] 9.2 Test all dashboard features

        -   Test filters (warehouse, cluster, liquidity)
        -   Test sorting functionality
        -   Test CSV export
        -   Test pagination
        -   _Requirements: 5.1_

    -   [ ] 9.3 Test mobile responsiveness

        -   Test on mobile devices
        -   Verify responsive design works
        -   Test touch interactions
        -   _Requirements: 5.1_

    -   [ ] 9.4 Performance testing
        -   Measure page load times
        -   Test with large datasets
        -   Verify API response times
        -   _Requirements: 5.3_

## Phase 10: Monitoring & Maintenance

-   [ ] 10. Production Monitoring Setup

    -   [ ] 10.1 Set up error logging

        -   Configure PHP error logging
        -   Set up frontend error tracking
        -   Create log rotation
        -   _Requirements: 5.1_

    -   [ ] 10.2 Set up monitoring

        -   Monitor API response times
        -   Set up uptime monitoring
        -   Configure alerts for errors
        -   _Requirements: 5.1, 5.3_

    -   [ ] 10.3 Create maintenance procedures
        -   Document backup procedures
        -   Create update deployment process
        -   Set up automated data refresh
        -   _Requirements: 4.1_

## Phase 11: Documentation & Handover

-   [ ] 11. Production Documentation

    -   [ ] 11.1 Create production deployment guide

        -   Document server configuration
        -   Document deployment process
        -   Create troubleshooting guide
        -   _Requirements: All_

    -   [ ] 11.2 Update user documentation

        -   Update URLs in user guide
        -   Create production user manual
        -   Document new features
        -   _Requirements: 5.1_

    -   [ ] 11.3 Create maintenance schedule
        -   Schedule regular data updates
        -   Plan for feature updates
        -   Set up backup schedule
        -   _Requirements: 4.1_

---

## Deployment Checklist Summary

**Pre-deployment:**

-   [ ] All tests pass
-   [ ] Code committed and pushed
-   [ ] Production build created and tested

**Deployment:**

-   [ ] Database migrated
-   [ ] Backend deployed and tested
-   [ ] Frontend deployed and configured
-   [ ] Web server configured with HTTPS

**Post-deployment:**

-   [ ] All features tested in production
-   [ ] Performance verified
-   [ ] Monitoring set up
-   [ ] Documentation updated

**Final URL:** https://www.market-mi.ru/warehouse-dashboard

---

## Estimated Timeline

-   **Phase 1-3 (Preparation):** 2-3 hours
-   **Phase 4-6 (Server & Backend):** 3-4 hours
-   **Phase 7-8 (Frontend & Config):** 2-3 hours
-   **Phase 9-11 (Testing & Docs):** 2-3 hours

**Total Estimated Time:** 9-13 hours
