#!/usr/bin/env python3
"""
Simple SQL syntax validation for the migration file
"""

import re

def validate_sql_syntax(file_path):
    """Basic SQL syntax validation"""
    errors = []
    warnings = []
    
    try:
        with open(file_path, 'r', encoding='utf-8') as f:
            content = f.read()
        
        # Remove comments and empty lines for analysis
        lines = content.split('\n')
        sql_lines = []
        
        for line in lines:
            line = line.strip()
            if line and not line.startswith('--'):
                sql_lines.append(line)
        
        sql_content = ' '.join(sql_lines)
        
        # Basic checks
        if 'CREATE TABLE' not in sql_content:
            errors.append("No CREATE TABLE statements found")
        
        # Check for balanced parentheses
        open_parens = sql_content.count('(')
        close_parens = sql_content.count(')')
        if open_parens != close_parens:
            errors.append(f"Unbalanced parentheses: {open_parens} open, {close_parens} close")
        
        # Check for semicolons at end of statements
        statements = sql_content.split(';')
        if len(statements) < 4:  # Should have at least 4 CREATE TABLE statements
            warnings.append("Expected at least 4 CREATE TABLE statements")
        
        # Check for required tables
        required_tables = ['ozon_api_settings', 'ozon_funnel_data', 'ozon_demographics', 'ozon_campaigns']
        for table in required_tables:
            if table not in sql_content:
                errors.append(f"Required table {table} not found")
        
        # Check for indexes
        if 'INDEX' not in sql_content:
            warnings.append("No INDEX statements found")
        
        # Check for IF NOT EXISTS
        if 'IF NOT EXISTS' not in sql_content:
            warnings.append("No IF NOT EXISTS clauses found - migration may fail if tables exist")
        
        return errors, warnings
        
    except Exception as e:
        return [f"Error reading file: {e}"], []

def main():
    print("SQL Syntax Validation for Ozon Migration")
    print("=" * 45)
    
    file_path = "migrations/add_ozon_analytics_tables.sql"
    errors, warnings = validate_sql_syntax(file_path)
    
    if warnings:
        print("⚠️  WARNINGS:")
        for warning in warnings:
            print(f"   - {warning}")
        print()
    
    if errors:
        print("❌ ERRORS:")
        for error in errors:
            print(f"   - {error}")
        print("\n❌ SQL validation failed!")
        return 1
    else:
        print("✅ SQL syntax validation passed!")
        return 0

if __name__ == "__main__":
    exit(main())