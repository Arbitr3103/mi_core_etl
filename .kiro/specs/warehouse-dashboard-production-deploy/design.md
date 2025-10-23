# Production Deployment Design

## Overview

This document outlines the deployment architecture and process for deploying the Warehouse Dashboard to the production server at market-mi.ru.

## Architecture

### Current Local Setup

-   **Backend**: PHP 8.4 on localhost:8000
-   **Frontend**: React + Vite on localhost:3000
-   **Database**: PostgreSQL with mi_core_db
-   **API Proxy**: Vite dev server proxying to PHP

### Production Target

-   **Domain**: https://www.market-mi.ru
-   **Backend**: PHP on Apache/Nginx
-   **Frontend**: Static build served by web server
-   **Database**: Production PostgreSQL
-   **SSL**: HTTPS with valid certificate

## Components

### 1. Git Repository Management

-   Commit all changes with proper messages
-   Push to remote repository (GitHub/GitLab)
-   Tag release version

### 2. Frontend Build Process

-   Run `npm run build` to create production assets
-   Generate optimized bundles with code splitting
-   Create static files for web server

### 3. Backend Deployment

-   Upload PHP files to server
-   Configure database connections for production
-   Set up proper file permissions

### 4. Database Migration

-   Backup production database
-   Apply schema changes
-   Import/update data if needed

### 5. Web Server Configuration

-   Configure virtual host for market-mi.ru
-   Set up routing for SPA (Single Page Application)
-   Configure API endpoints
-   Enable HTTPS

### 6. Monitoring & Testing

-   Verify all endpoints work
-   Test dashboard functionality
-   Monitor performance
-   Set up error logging

## File Structure on Server

```
/var/www/market-mi.ru/
├── public/                 # Web root
│   ├── index.html         # Frontend entry point
│   ├── assets/            # Built CSS/JS files
│   └── api/               # Backend API files
├── config/                # Configuration files
├── scripts/               # Maintenance scripts
└── logs/                  # Application logs
```

## Environment Configuration

### Production Environment Variables

-   Database credentials
-   API keys (Ozon, etc.)
-   Debug settings (disabled)
-   Cache settings
-   CORS origins

### Security Considerations

-   Secure database passwords
-   Restrict API access
-   Enable HTTPS only
-   Configure proper CORS
-   Set secure headers

## Deployment Steps Overview

1. **Pre-deployment**

    - Run tests
    - Verify local functionality
    - Backup production data

2. **Code Deployment**

    - Commit and push changes
    - Build production assets
    - Upload to server

3. **Database Updates**

    - Run migrations
    - Update data if needed
    - Verify integrity

4. **Configuration**

    - Update web server config
    - Set environment variables
    - Configure SSL

5. **Post-deployment**
    - Test all functionality
    - Monitor for errors
    - Update documentation
