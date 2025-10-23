#!/usr/bin/env python3
"""
MySQL to PostgreSQL Data Migration Script
For mi_core_etl project

This script migrates data from MySQL to PostgreSQL with integrity checks
and validation at each step.
"""

import os
import sys
import json
import logging
import hashlib
from datetime import datetime
from typing import Dict, List, Tuple, Any, Optional
from dataclasses import dataclass

import mysql.connector
import psycopg2
import psycopg2.extras
from mysql.connector import Error as MySQLError
from psycopg2 import Error as PostgreSQLError

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler(f'migration_{datetime.now().strftime("%Y%m%d_%H%M%S")}.log'),
        logging.StreamHandler(sys.stdout)
    ]
)
logger = logging.getLogger(__name__)

@dataclass
class DatabaseConfig:
    """Database configuration"""
    host: str
    port: int
    database: str
    user: str
    password: str

@dataclass
class MigrationStats:
    """Migration statistics"""
    table_name: str
    source_count: int
    target_count: int
    migrated_count: int
    errors: List[str]
    checksum_source: str
    checksum_target: str
    
    @property
    def is_valid(self) -> bool:
        return (
            self.source_count == self.target_count == self.migrated_count and
            self.checksum_source == self.checksum_target and
            len(self.errors) == 0
        )

class DatabaseMigrator:
    """Main migration class"""
    
    def __init__(self, mysql_config: DatabaseConfig, postgresql_config: DatabaseConfig):
        self.mysql_config = mysql_config
        self.postgresql_config = postgresql_config
        self.mysql_conn = None
        self.postgresql_conn = None
        self.migration_stats: Dict[str, MigrationStats] = {}
        
        # Table migration order (respecting foreign key dependencies)
        self.table_order = [
            'clients',
            'sources', 
            'regions',
            'brands',
            'car_models',
            'car_specifications',
            'dim_products',
            'system_settings',
            'job_runs',
            'raw_events',
            'fact_orders',
            'fact_transactions',
            'metrics_daily',
            'inventory',
            'stock_movements',
            'master_products',
            'sku_mapping',
            'data_quality_metrics',
            'matching_history',
            'audit_log',
            'replenishment_recommendations',
            'replenishment_alerts',
            'replenishment_settings'
        ]
        
        # Column mappings for data type conversions
        self.column_mappings = {
            'job_runs': {
                'status': self._convert_enum_status
            },
            'inventory': {
                'stock_type': self._convert_stock_type,
                'source': self._convert_marketplace_source
            },
            'stock_movements': {
                'source': self._convert_marketplace_source
            },
            'master_products': {
                'status': self._convert_product_status
            },
            'sku_mapping': {
                'verification_status': self._convert_verification_status
            },
            'matching_history': {
                'final_decision': self._convert_match_decision
            },
            'audit_log': {
                'action': self._convert_audit_action
            },
            'car_specifications': {
                'fastener_type': self._convert_fastener_type
            }
        }

    def connect_databases(self) -> bool:
        """Establish connections to both databases"""
        try:
            # Connect to MySQL
            logger.info("Connecting to MySQL database...")
            self.mysql_conn = mysql.connector.connect(
                host=self.mysql_config.host,
                port=self.mysql_config.port,
                database=self.mysql_config.database,
                user=self.mysql_config.user,
                password=self.mysql_config.password,
                charset='utf8mb4',
                use_unicode=True
            )
            
            # Connect to PostgreSQL
            logger.info("Connecting to PostgreSQL database...")
            self.postgresql_conn = psycopg2.connect(
                host=self.postgresql_config.host,
                port=self.postgresql_config.port,
                database=self.postgresql_config.database,
                user=self.postgresql_config.user,
                password=self.postgresql_config.password
            )
            self.postgresql_conn.set_client_encoding('UTF8')
            
            logger.info("Database connections established successfully")
            return True
            
        except (MySQLError, PostgreSQLError) as e:
            logger.error(f"Database connection failed: {e}")
            return False

    def close_connections(self):
        """Close database connections"""
        if self.mysql_conn:
            self.mysql_conn.close()
        if self.postgresql_conn:
            self.postgresql_conn.close()
        logger.info("Database connections closed")

    def get_table_exists(self, table_name: str, database: str = 'both') -> Tuple[bool, bool]:
        """Check if table exists in MySQL and/or PostgreSQL"""
        mysql_exists = False
        postgresql_exists = False
        
        if database in ['mysql', 'both']:
            try:
                cursor = self.mysql_conn.cursor()
                cursor.execute(f"SHOW TABLES LIKE '{table_name}'")
                mysql_exists = cursor.fetchone() is not None
                cursor.close()
            except MySQLError as e:
                logger.warning(f"Error checking MySQL table {table_name}: {e}")
        
        if database in ['postgresql', 'both']:
            try:
                cursor = self.postgresql_conn.cursor()
                cursor.execute("""
                    SELECT EXISTS (
                        SELECT FROM information_schema.tables 
                        WHERE table_schema = 'public' AND table_name = %s
                    )
                """, (table_name,))
                postgresql_exists = cursor.fetchone()[0]
                cursor.close()
            except PostgreSQLError as e:
                logger.warning(f"Error checking PostgreSQL table {table_name}: {e}")
        
        return mysql_exists, postgresql_exists

    def get_table_count(self, table_name: str, database: str) -> int:
        """Get row count for a table"""
        try:
            if database == 'mysql':
                cursor = self.mysql_conn.cursor()
                cursor.execute(f"SELECT COUNT(*) FROM {table_name}")
                count = cursor.fetchone()[0]
                cursor.close()
            else:  # postgresql
                cursor = self.postgresql_conn.cursor()
                cursor.execute(f"SELECT COUNT(*) FROM {table_name}")
                count = cursor.fetchone()[0]
                cursor.close()
            return count
        except Exception as e:
            logger.error(f"Error getting count for {table_name} in {database}: {e}")
            return 0

    def calculate_table_checksum(self, table_name: str, database: str) -> str:
        """Calculate checksum for table data"""
        try:
            if database == 'mysql':
                cursor = self.mysql_conn.cursor()
                cursor.execute(f"SELECT * FROM {table_name} ORDER BY id")
                rows = cursor.fetchall()
                cursor.close()
            else:  # postgresql
                cursor = self.postgresql_conn.cursor()
                cursor.execute(f"SELECT * FROM {table_name} ORDER BY id")
                rows = cursor.fetchall()
                cursor.close()
            
            # Create checksum from all data
            data_str = json.dumps(rows, default=str, sort_keys=True)
            return hashlib.md5(data_str.encode()).hexdigest()
            
        except Exception as e:
            logger.error(f"Error calculating checksum for {table_name} in {database}: {e}")
            return ""

    def _convert_enum_status(self, value: str) -> str:
        """Convert MySQL enum status to PostgreSQL"""
        return value  # Direct mapping for job_status

    def _convert_stock_type(self, value: str) -> str:
        """Convert stock type enum"""
        return value  # Direct mapping

    def _convert_marketplace_source(self, value: str) -> str:
        """Convert marketplace source enum"""
        return value  # Direct mapping

    def _convert_product_status(self, value: str) -> str:
        """Convert product status enum"""
        return value  # Direct mapping

    def _convert_verification_status(self, value: str) -> str:
        """Convert verification status enum"""
        return value  # Direct mapping

    def _convert_match_decision(self, value: str) -> str:
        """Convert match decision enum"""
        return value  # Direct mapping

    def _convert_audit_action(self, value: str) -> str:
        """Convert audit action enum"""
        return value  # Direct mapping

    def _convert_fastener_type(self, value: str) -> str:
        """Convert fastener type enum"""
        return value  # Direct mapping

    def migrate_table(self, table_name: str) -> MigrationStats:
        """Migrate a single table from MySQL to PostgreSQL"""
        logger.info(f"Starting migration for table: {table_name}")
        
        errors = []
        migrated_count = 0
        
        try:
            # Check if source table exists
            mysql_exists, postgresql_exists = self.get_table_exists(table_name)
            
            if not mysql_exists:
                logger.warning(f"Source table {table_name} does not exist in MySQL, skipping")
                return MigrationStats(
                    table_name=table_name,
                    source_count=0,
                    target_count=0,
                    migrated_count=0,
                    errors=[f"Source table {table_name} does not exist"],
                    checksum_source="",
                    checksum_target=""
                )
            
            if not postgresql_exists:
                logger.warning(f"Target table {table_name} does not exist in PostgreSQL, skipping")
                return MigrationStats(
                    table_name=table_name,
                    source_count=0,
                    target_count=0,
                    migrated_count=0,
                    errors=[f"Target table {table_name} does not exist"],
                    checksum_source="",
                    checksum_target=""
                )
            
            # Get source data count
            source_count = self.get_table_count(table_name, 'mysql')
            logger.info(f"Source table {table_name} has {source_count} rows")
            
            if source_count == 0:
                logger.info(f"Source table {table_name} is empty, skipping data migration")
                return MigrationStats(
                    table_name=table_name,
                    source_count=0,
                    target_count=0,
                    migrated_count=0,
                    errors=[],
                    checksum_source="",
                    checksum_target=""
                )
            
            # Clear target table
            postgresql_cursor = self.postgresql_conn.cursor()
            postgresql_cursor.execute(f"TRUNCATE TABLE {table_name} RESTART IDENTITY CASCADE")
            self.postgresql_conn.commit()
            
            # Get source data
            mysql_cursor = self.mysql_conn.cursor(dictionary=True)
            mysql_cursor.execute(f"SELECT * FROM {table_name}")
            
            # Get column names from PostgreSQL table
            postgresql_cursor.execute(f"""
                SELECT column_name 
                FROM information_schema.columns 
                WHERE table_name = '{table_name}' AND table_schema = 'public'
                ORDER BY ordinal_position
            """)
            pg_columns = [row[0] for row in postgresql_cursor.fetchall()]
            
            # Migrate data in batches
            batch_size = 1000
            batch_count = 0
            
            while True:
                rows = mysql_cursor.fetchmany(batch_size)
                if not rows:
                    break
                
                batch_count += 1
                logger.info(f"Processing batch {batch_count} for {table_name} ({len(rows)} rows)")
                
                # Prepare data for PostgreSQL
                processed_rows = []
                for row in rows:
                    processed_row = {}
                    
                    # Apply column mappings and conversions
                    for col_name, value in row.items():
                        if col_name in pg_columns:
                            # Apply custom conversions if defined
                            if (table_name in self.column_mappings and 
                                col_name in self.column_mappings[table_name]):
                                converter = self.column_mappings[table_name][col_name]
                                value = converter(value) if value is not None else None
                            
                            processed_row[col_name] = value
                    
                    processed_rows.append(processed_row)
                
                # Insert batch into PostgreSQL
                if processed_rows:
                    try:
                        # Build insert query
                        columns = list(processed_rows[0].keys())
                        placeholders = ', '.join(['%s'] * len(columns))
                        query = f"INSERT INTO {table_name} ({', '.join(columns)}) VALUES ({placeholders})"
                        
                        # Prepare values
                        values = []
                        for row in processed_rows:
                            values.append([row[col] for col in columns])
                        
                        # Execute batch insert
                        psycopg2.extras.execute_batch(postgresql_cursor, query, values, page_size=100)
                        self.postgresql_conn.commit()
                        migrated_count += len(processed_rows)
                        
                    except PostgreSQLError as e:
                        error_msg = f"Error inserting batch {batch_count} into {table_name}: {e}"
                        logger.error(error_msg)
                        errors.append(error_msg)
                        self.postgresql_conn.rollback()
            
            mysql_cursor.close()
            postgresql_cursor.close()
            
            # Get final counts and checksums
            target_count = self.get_table_count(table_name, 'postgresql')
            source_checksum = self.calculate_table_checksum(table_name, 'mysql')
            target_checksum = self.calculate_table_checksum(table_name, 'postgresql')
            
            stats = MigrationStats(
                table_name=table_name,
                source_count=source_count,
                target_count=target_count,
                migrated_count=migrated_count,
                errors=errors,
                checksum_source=source_checksum,
                checksum_target=target_checksum
            )
            
            if stats.is_valid:
                logger.info(f"✅ Table {table_name} migrated successfully: {migrated_count} rows")
            else:
                logger.error(f"❌ Table {table_name} migration validation failed")
                logger.error(f"   Source count: {source_count}, Target count: {target_count}")
                logger.error(f"   Migrated count: {migrated_count}")
                logger.error(f"   Checksum match: {source_checksum == target_checksum}")
            
            return stats
            
        except Exception as e:
            error_msg = f"Unexpected error migrating {table_name}: {e}"
            logger.error(error_msg)
            return MigrationStats(
                table_name=table_name,
                source_count=0,
                target_count=0,
                migrated_count=0,
                errors=[error_msg],
                checksum_source="",
                checksum_target=""
            )

    def migrate_all_tables(self) -> Dict[str, MigrationStats]:
        """Migrate all tables in the correct order"""
        logger.info("Starting full database migration...")
        
        for table_name in self.table_order:
            stats = self.migrate_table(table_name)
            self.migration_stats[table_name] = stats
        
        return self.migration_stats

    def generate_migration_report(self) -> str:
        """Generate a detailed migration report"""
        report = []
        report.append("=" * 80)
        report.append("MYSQL TO POSTGRESQL MIGRATION REPORT")
        report.append("=" * 80)
        report.append(f"Migration completed at: {datetime.now()}")
        report.append("")
        
        total_tables = len(self.migration_stats)
        successful_tables = sum(1 for stats in self.migration_stats.values() if stats.is_valid)
        failed_tables = total_tables - successful_tables
        
        report.append(f"SUMMARY:")
        report.append(f"  Total tables processed: {total_tables}")
        report.append(f"  Successful migrations: {successful_tables}")
        report.append(f"  Failed migrations: {failed_tables}")
        report.append("")
        
        # Detailed table results
        report.append("DETAILED RESULTS:")
        report.append("-" * 80)
        
        for table_name, stats in self.migration_stats.items():
            status = "✅ SUCCESS" if stats.is_valid else "❌ FAILED"
            report.append(f"{table_name:<30} {status}")
            report.append(f"  Source rows:    {stats.source_count:>10}")
            report.append(f"  Target rows:    {stats.target_count:>10}")
            report.append(f"  Migrated rows:  {stats.migrated_count:>10}")
            report.append(f"  Checksum match: {'Yes' if stats.checksum_source == stats.checksum_target else 'No'}")
            
            if stats.errors:
                report.append("  Errors:")
                for error in stats.errors:
                    report.append(f"    - {error}")
            report.append("")
        
        return "\n".join(report)

    def validate_migration(self) -> bool:
        """Validate the entire migration"""
        logger.info("Validating migration integrity...")
        
        all_valid = True
        for table_name, stats in self.migration_stats.items():
            if not stats.is_valid:
                logger.error(f"Validation failed for table: {table_name}")
                all_valid = False
        
        if all_valid:
            logger.info("✅ All tables passed validation")
        else:
            logger.error("❌ Some tables failed validation")
        
        return all_valid

def load_config_from_env() -> Tuple[DatabaseConfig, DatabaseConfig]:
    """Load database configurations from environment variables"""
    
    # MySQL configuration
    mysql_config = DatabaseConfig(
        host=os.getenv('DB_HOST', 'localhost'),
        port=int(os.getenv('DB_PORT', '3306')),
        database=os.getenv('DB_NAME', 'mi_core_db'),
        user=os.getenv('DB_USER', 'root'),
        password=os.getenv('DB_PASSWORD', '')
    )
    
    # PostgreSQL configuration
    postgresql_config = DatabaseConfig(
        host=os.getenv('PG_HOST', 'localhost'),
        port=int(os.getenv('PG_PORT', '5432')),
        database=os.getenv('PG_NAME', 'mi_core_db'),
        user=os.getenv('PG_USER', 'mi_core_user'),
        password=os.getenv('PG_PASSWORD', '')
    )
    
    return mysql_config, postgresql_config

def main():
    """Main migration function"""
    logger.info("Starting MySQL to PostgreSQL migration...")
    
    try:
        # Load configurations
        mysql_config, postgresql_config = load_config_from_env()
        
        # Create migrator
        migrator = DatabaseMigrator(mysql_config, postgresql_config)
        
        # Connect to databases
        if not migrator.connect_databases():
            logger.error("Failed to connect to databases")
            return False
        
        # Run migration
        migration_stats = migrator.migrate_all_tables()
        
        # Validate migration
        is_valid = migrator.validate_migration()
        
        # Generate report
        report = migrator.generate_migration_report()
        
        # Save report to file
        report_filename = f"migration_report_{datetime.now().strftime('%Y%m%d_%H%M%S')}.txt"
        with open(report_filename, 'w', encoding='utf-8') as f:
            f.write(report)
        
        # Print report
        print(report)
        
        # Close connections
        migrator.close_connections()
        
        logger.info(f"Migration completed. Report saved to: {report_filename}")
        return is_valid
        
    except Exception as e:
        logger.error(f"Migration failed with error: {e}")
        return False

if __name__ == "__main__":
    success = main()
    sys.exit(0 if success else 1)