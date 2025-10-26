# Ozon Complete ETL System

A comprehensive ETL (Extract, Transform, Load) system for synchronizing data from Ozon API to support warehouse management dashboards.

## Overview

This system provides daily synchronization of:

-   **Product Catalog** - All products with identifiers (product_id, offer_id, SKUs)
-   **Sales History** - Order data for calculating sales velocity (ADS)
-   **Warehouse Inventory** - Real-time stock levels across all warehouses

## Directory Structure

```
src/ETL/Ozon/
├── Core/           # Base ETL framework classes
├── Api/            # Ozon API client implementation
├── Components/     # ETL component implementations
├── Scripts/        # Executable CLI scripts
├── Config/         # Configuration files
├── Logs/           # Log files (runtime)
├── Tests/          # Unit tests
└── README.md       # This file
```

## Configuration

### Environment Setup

1. Copy the environment template:

    ```bash
    cp src/ETL/Ozon/Config/.env.ozon.example .env.ozon
    ```

2. Fill in your Ozon API credentials and database settings in `.env.ozon`

### Required Environment Variables

-   `OZON_CLIENT_ID` - Your Ozon API client ID
-   `OZON_API_KEY` - Your Ozon API key
-   `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` - Database connection

## Installation

1. Ensure Composer dependencies are installed:

    ```bash
    php composer.phar install
    ```

2. Load the autoloader and configuration:
    ```php
    require_once 'src/ETL/Ozon/autoload.php';
    ```

## Usage

The system is designed to be executed via CLI scripts:

```bash
# Sync products (planned)
php src/ETL/Ozon/Scripts/sync_products.php

# Sync sales data (planned)
php src/ETL/Ozon/Scripts/sync_sales.php

# Sync inventory (planned)
php src/ETL/Ozon/Scripts/sync_inventory.php

# Health check (planned)
php src/ETL/Ozon/Scripts/health_check.php
```

## Scheduling

The system is designed to run on the following schedule (Moscow time):

-   **02:00** - Product synchronization
-   **03:00** - Sales data extraction
-   **04:00** - Inventory update

## Database Schema

The system uses the following tables:

-   `dim_products` - Product catalog dimension
-   `fact_orders` - Sales transactions fact table
-   `inventory` - Current inventory levels
-   `etl_execution_log` - ETL process monitoring

## Monitoring

-   All operations are logged with structured JSON format
-   Execution metrics are tracked in `etl_execution_log` table
-   Alert notifications for failures (email/Slack configurable)
-   Health check endpoint for system monitoring

## Development Status

This is the basic infrastructure setup. Implementation of core components is planned in subsequent tasks:

-   [x] Task 1: Basic infrastructure setup
-   [ ] Task 2: Database schema creation
-   [ ] Task 3: Base ETL framework
-   [ ] Task 4: Ozon API client
-   [ ] Task 5-7: ETL components (Product, Sales, Inventory)
-   [ ] Task 8: CLI scripts
-   [ ] Task 9: Scheduler setup
-   [ ] Task 10: Integration testing and deployment

## Requirements Addressed

This infrastructure setup addresses the following requirements:

-   **1.1-1.5**: Product catalog synchronization infrastructure
-   **2.1-2.5**: Sales history extraction infrastructure
-   **3.1-3.5**: Inventory management infrastructure
-   **4.1-4.5**: Monitoring and logging infrastructure
-   **5.1-5.5**: Scheduling infrastructure
