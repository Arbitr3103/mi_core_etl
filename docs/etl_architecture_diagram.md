# ETL Architecture Diagrams

## High-Level Architecture

```mermaid
graph TB
    subgraph "External API"
        OZON[Ozon Analytics API<br/>POST /v2/analytics/stock_on_warehouses]
    end

    subgraph "ETL Services Layer"
        AC[AnalyticsApiClient<br/>• Pagination<br/>• Rate Limiting<br/>• Retry Logic]
        DV[DataValidator<br/>• Schema Validation<br/>• Business Rules<br/>• Anomaly Detection]
        NL[NormalizationLayer<br/>• Warehouse Names<br/>• Data Standardization<br/>• Mapping Rules]
        ETL[WarehouseETL<br/>• Process Orchestration<br/>• Batch Management<br/>• Error Handling]
    end

    subgraph "Data Storage"
        PG[(PostgreSQL<br/>mi_core_db<br/>• inventory table<br/>• audit tables)]
        REDIS[(Redis Cache<br/>• API responses<br/>• Mappings<br/>• Session data)]
    end

    subgraph "Monitoring & Logging"
        LOG[ETL Logger<br/>• Structured Logging<br/>• Performance Metrics<br/>• Audit Trail]
        ALERT[Alert Manager<br/>• Error Notifications<br/>• SLA Monitoring<br/>• Health Checks]
        DASH[Monitoring Dashboard<br/>• Real-time Status<br/>• Performance Charts<br/>• Error Reports]
    end

    subgraph "Scheduling & Control"
        CRON[Cron Scheduler<br/>• Full Sync: 2x daily<br/>• Incremental: 2h<br/>• Health Check: 15m]
        CLI[ETL CLI<br/>• Manual Operations<br/>• Status Commands<br/>• Maintenance Tasks]
    end

    OZON --> AC
    AC --> DV
    DV --> NL
    NL --> ETL
    ETL --> PG
    ETL --> REDIS

    ETL --> LOG
    LOG --> ALERT
    ALERT --> DASH

    CRON --> CLI
    CLI --> ETL

    style OZON fill:#e1f5fe
    style PG fill:#f3e5f5
    style REDIS fill:#fff3e0
    style ETL fill:#e8f5e8
```

## Data Flow Architecture

```mermaid
sequenceDiagram
    participant CRON as Cron Scheduler
    participant CLI as ETL CLI
    participant ETL as WarehouseETL
    participant API as AnalyticsApiClient
    participant VAL as DataValidator
    participant NORM as NormalizationLayer
    participant DB as PostgreSQL
    participant CACHE as Redis Cache
    participant LOG as ETL Logger

    CRON->>CLI: Trigger scheduled sync
    CLI->>ETL: Start ETL process
    ETL->>LOG: Log ETL start

    loop Pagination Loop
        ETL->>API: Request batch (offset, limit)
        API->>CACHE: Check cache
        alt Cache Miss
            API-->>External: Call Ozon API
            External-->>API: Return data
            API->>CACHE: Store in cache
        end
        API->>ETL: Return batch data

        ETL->>VAL: Validate batch
        VAL->>ETL: Return validation results

        ETL->>NORM: Normalize data
        NORM->>ETL: Return normalized data

        ETL->>DB: Update inventory
        DB->>ETL: Confirm updates

        ETL->>LOG: Log batch progress
    end

    ETL->>LOG: Log ETL completion
    ETL->>CLI: Return results
    CLI->>CRON: Exit with status
```

## Component Interaction Diagram

```mermaid
graph LR
    subgraph "ETL Process Flow"
        START([Start ETL]) --> EXTRACT[Extract Phase]
        EXTRACT --> TRANSFORM[Transform Phase]
        TRANSFORM --> LOAD[Load Phase]
        LOAD --> AUDIT[Audit Phase]
        AUDIT --> END([End ETL])
    end

    subgraph "Extract Phase Details"
        EXTRACT --> API_CALL[API Call]
        API_CALL --> PAGINATE[Handle Pagination]
        PAGINATE --> CACHE_CHECK[Check Cache]
        CACHE_CHECK --> RATE_LIMIT[Rate Limiting]
        RATE_LIMIT --> RETRY[Retry Logic]
    end

    subgraph "Transform Phase Details"
        TRANSFORM --> VALIDATE[Data Validation]
        VALIDATE --> NORMALIZE[Data Normalization]
        NORMALIZE --> ENRICH[Data Enrichment]
        ENRICH --> DEDUPE[Deduplication]
    end

    subgraph "Load Phase Details"
        LOAD --> BATCH_PREP[Batch Preparation]
        BATCH_PREP --> DB_TRANS[Database Transaction]
        DB_TRANS --> UPSERT[Upsert Operations]
        UPSERT --> INDEX_UPDATE[Index Updates]
    end

    subgraph "Audit Phase Details"
        AUDIT --> LOG_CHANGES[Log Changes]
        LOG_CHANGES --> METRICS[Update Metrics]
        METRICS --> ALERTS[Check Alerts]
        ALERTS --> CLEANUP[Cleanup Resources]
    end
```

## Error Handling Flow

```mermaid
graph TD
    START[ETL Operation] --> TRY[Try Operation]
    TRY --> SUCCESS{Success?}

    SUCCESS -->|Yes| LOG_SUCCESS[Log Success]
    SUCCESS -->|No| ERROR[Handle Error]

    ERROR --> CHECK_TYPE{Error Type?}

    CHECK_TYPE -->|Rate Limit| WAIT[Wait & Retry]
    CHECK_TYPE -->|Timeout| RETRY[Retry with Backoff]
    CHECK_TYPE -->|Validation| SKIP[Skip Record & Log]
    CHECK_TYPE -->|Critical| CIRCUIT[Circuit Breaker]

    WAIT --> RETRY_COUNT{Retry Count < Max?}
    RETRY --> RETRY_COUNT

    RETRY_COUNT -->|Yes| TRY
    RETRY_COUNT -->|No| FAIL[Mark as Failed]

    SKIP --> CONTINUE[Continue Processing]
    CIRCUIT --> ALERT[Send Alert]

    LOG_SUCCESS --> CONTINUE
    FAIL --> ALERT
    ALERT --> END[End Process]
    CONTINUE --> END

    style START fill:#e8f5e8
    style SUCCESS fill:#fff3e0
    style ERROR fill:#ffebee
    style FAIL fill:#f44336,color:#fff
    style LOG_SUCCESS fill:#4caf50,color:#fff
```

## Database Schema Relationships

```mermaid
erDiagram
    inventory {
        int id PK
        int sku
        string warehouse_name
        string normalized_warehouse_name
        string original_warehouse_name
        int available
        int reserved
        int promised_amount
        string product_name
        string product_code
        string data_source
        int data_quality_score
        timestamp last_analytics_sync
        uuid sync_batch_id
        timestamp created_at
        timestamp updated_at
    }

    analytics_etl_log {
        int id PK
        uuid batch_id
        string etl_type
        timestamp started_at
        timestamp completed_at
        string status
        int records_extracted
        int records_validated
        int records_normalized
        int records_inserted
        int records_updated
        int records_failed
        int api_requests_made
        int api_requests_failed
        int total_api_time_ms
        text error_message
        jsonb error_details
        int execution_time_ms
        decimal memory_used_mb
    }

    warehouse_normalization {
        int id PK
        string original_name
        string normalized_name
        string source_type
        decimal confidence_score
        timestamp created_at
        timestamp updated_at
        string created_by
        string cluster_name
        string warehouse_code
        boolean is_active
    }

    inventory_audit {
        int id PK
        int inventory_id FK
        int product_id
        string warehouse_name
        int old_available
        int old_reserved
        string old_data_source
        int old_quality_score
        int new_available
        int new_reserved
        string new_data_source
        int new_quality_score
        string change_type
        string change_source
        string initiated_by
        string change_reason
        timestamp changed_at
        string api_request_id
        string ui_file_name
        uuid batch_id
    }

    inventory ||--o{ inventory_audit : "tracks changes"
    analytics_etl_log ||--o{ inventory : "batch_id links to sync_batch_id"
    warehouse_normalization ||--o{ inventory : "normalized_name mapping"
```

## Monitoring Architecture

```mermaid
graph TB
    subgraph "Data Collection"
        APP[ETL Application] --> LOGS[Application Logs]
        APP --> METRICS[Performance Metrics]
        APP --> EVENTS[System Events]
        DB[(Database)] --> DB_METRICS[DB Metrics]
        REDIS[(Redis)] --> CACHE_METRICS[Cache Metrics]
    end

    subgraph "Processing Layer"
        LOGS --> LOG_PARSER[Log Parser]
        METRICS --> METRIC_COLLECTOR[Metric Collector]
        EVENTS --> EVENT_PROCESSOR[Event Processor]
        DB_METRICS --> DB_MONITOR[DB Monitor]
        CACHE_METRICS --> CACHE_MONITOR[Cache Monitor]
    end

    subgraph "Storage & Analysis"
        LOG_PARSER --> LOG_STORE[(Log Storage)]
        METRIC_COLLECTOR --> METRIC_STORE[(Metric Storage)]
        EVENT_PROCESSOR --> EVENT_STORE[(Event Storage)]
        DB_MONITOR --> HEALTH_STORE[(Health Storage)]
        CACHE_MONITOR --> HEALTH_STORE
    end

    subgraph "Alerting & Visualization"
        LOG_STORE --> ALERT_ENGINE[Alert Engine]
        METRIC_STORE --> ALERT_ENGINE
        EVENT_STORE --> ALERT_ENGINE
        HEALTH_STORE --> ALERT_ENGINE

        ALERT_ENGINE --> EMAIL[Email Alerts]
        ALERT_ENGINE --> SLACK[Slack Notifications]
        ALERT_ENGINE --> SMS[SMS Alerts]

        LOG_STORE --> DASHBOARD[Monitoring Dashboard]
        METRIC_STORE --> DASHBOARD
        EVENT_STORE --> DASHBOARD
        HEALTH_STORE --> DASHBOARD
    end

    style APP fill:#e8f5e8
    style ALERT_ENGINE fill:#ff9800,color:#fff
    style DASHBOARD fill:#2196f3,color:#fff
```

## Deployment Architecture

```mermaid
graph TB
    subgraph "Development Environment"
        DEV_CODE[Source Code] --> DEV_BUILD[Build Process]
        DEV_BUILD --> DEV_TEST[Unit Tests]
        DEV_TEST --> DEV_PACKAGE[Package Creation]
    end

    subgraph "CI/CD Pipeline"
        DEV_PACKAGE --> CI[Continuous Integration]
        CI --> INTEGRATION_TEST[Integration Tests]
        INTEGRATION_TEST --> SECURITY_SCAN[Security Scanning]
        SECURITY_SCAN --> STAGING_DEPLOY[Staging Deployment]
    end

    subgraph "Staging Environment"
        STAGING_DEPLOY --> STAGING_ETL[Staging ETL Services]
        STAGING_ETL --> STAGING_DB[(Staging Database)]
        STAGING_ETL --> STAGING_CACHE[(Staging Cache)]
        STAGING_ETL --> E2E_TEST[End-to-End Tests]
    end

    subgraph "Production Environment"
        E2E_TEST --> PROD_DEPLOY[Production Deployment]
        PROD_DEPLOY --> PROD_ETL[Production ETL Services]
        PROD_ETL --> PROD_DB[(Production Database)]
        PROD_ETL --> PROD_CACHE[(Production Cache)]
        PROD_ETL --> PROD_MONITOR[Production Monitoring]
    end

    subgraph "Rollback Strategy"
        PROD_DEPLOY --> HEALTH_CHECK[Health Check]
        HEALTH_CHECK --> ROLLBACK_DECISION{Healthy?}
        ROLLBACK_DECISION -->|No| ROLLBACK[Automatic Rollback]
        ROLLBACK_DECISION -->|Yes| PROD_MONITOR
        ROLLBACK --> PREV_VERSION[Previous Version]
    end

    style DEV_CODE fill:#e8f5e8
    style PROD_ETL fill:#4caf50,color:#fff
    style ROLLBACK fill:#f44336,color:#fff
```

These diagrams provide a comprehensive view of the ETL architecture, showing how all components interact and how data flows through the system from the Analytics API to the final storage in PostgreSQL.
