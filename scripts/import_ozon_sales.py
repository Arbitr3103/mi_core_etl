#!/usr/bin/env python3
"""
Import sales data from Ozon API to PostgreSQL
"""

import os
import sys
import requests
import psycopg2
import urllib3
from datetime import datetime, timedelta
from typing import List, Dict, Any

# Disable SSL warnings
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

# Database configuration
DB_CONFIG = {
    'host': 'localhost',
    'port': 5432,
    'database': 'mi_core_db',
    'user': 'mi_core_user',
    'password': 'MiCore2025SecurePass!'
}

# Ozon API configuration
OZON_CLIENT_ID = '26100'
OZON_API_KEY = '7e074977-e0db-4ace-ba9e-82903e088b4b'
OZON_API_URL = 'https://api-seller.ozon.ru'

def get_ozon_headers() -> Dict[str, str]:
    """Get headers for Ozon API requests"""
    return {
        'Client-Id': OZON_CLIENT_ID,
        'Api-Key': OZON_API_KEY,
        'Content-Type': 'application/json'
    }

def fetch_sales_data(date_from: str = "2025-08-01") -> List[Dict[str, Any]]:
    """
    Fetch sales data from Ozon API (FBO postings)
    
    Args:
        date_from: Start date in format YYYY-MM-DD (default: 2025-08-01)
    
    Returns:
        List of sales records
    """
    date_to = datetime.now()
    print(f"Fetching sales data from {date_from} to {date_to.strftime('%Y-%m-%d')} from Ozon API...")
    
    # Ozon API endpoint for FBO postings (orders fulfilled by Ozon)
    url = f"{OZON_API_URL}/v2/posting/fbo/list"
    
    all_orders = []
    max_offset = 20000  # API limit
    
    payload = {
        "dir": "ASC",
        "filter": {
            "since": f"{date_from}T00:00:00.000Z",
            "to": date_to.strftime("%Y-%m-%dT23:59:59.999Z"),
            "status": ""  # All statuses
        },
        "limit": 1000,
        "offset": 0,
        "with": {
            "analytics_data": True,
            "financial_data": True
        }
    }
    
    try:
        while payload['offset'] < max_offset:
            response = requests.post(url, json=payload, headers=get_ozon_headers(), verify=False)
            response.raise_for_status()
            
            data = response.json()
            postings = data.get('result', [])
            
            if not postings:
                break
            
            all_orders.extend(postings)
            print(f"  Fetched {len(postings)} orders (total: {len(all_orders)})")
            
            # Check if there are more pages (for FBO, we need to check if we got less than limit)
            if len(postings) < payload['limit']:
                break
            
            # Update offset for next page
            payload['offset'] += payload['limit']
        
        print(f"✓ Total received: {len(all_orders)} orders")
        
        # If we hit the limit, warn the user
        if len(all_orders) >= max_offset:
            print(f"⚠ Warning: Reached API offset limit ({max_offset}). Some orders may be missing.")
            print(f"  Consider splitting the date range into smaller periods.")
        
        return all_orders
        
    except requests.exceptions.RequestException as e:
        print(f"✗ Error fetching data from Ozon API: {e}")
        if hasattr(e, 'response') and e.response is not None:
            print(f"  Response: {e.response.text}")
        # Return what we have so far
        if all_orders:
            print(f"  Returning {len(all_orders)} orders fetched before error")
        return all_orders

def import_to_postgresql(sales_data: List[Dict[str, Any]]) -> int:
    """
    Import sales data to PostgreSQL
    
    Args:
        sales_data: List of order postings from Ozon
    
    Returns:
        Number of imported records
    """
    if not sales_data:
        print("No data to import")
        return 0
    
    print(f"Importing {len(sales_data)} orders to PostgreSQL...")
    
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cursor = conn.cursor()
        
        imported = 0
        skipped = 0
        
        for posting in sales_data:
            try:
                posting_number = posting.get('posting_number', '')
                order_date_str = posting.get('in_process_at') or posting.get('shipment_date') or posting.get('created_at', '')
                
                # Parse order date
                try:
                    order_date = datetime.fromisoformat(order_date_str.replace('Z', '+00:00')).date()
                except:
                    order_date = datetime.now().date()
                
                # Process each product in the order
                products = posting.get('products', [])
                
                if not products:
                    skipped += 1
                    continue
                
                for product in products:
                    sku = product.get('offer_id', '')
                    quantity = product.get('quantity', 0)
                    price = float(product.get('price', '0'))
                    
                    if not sku or quantity == 0:
                        skipped += 1
                        continue
                    
                    # Find product_id by SKU (Ozon SKU)
                    cursor.execute("""
                        SELECT id FROM dim_products WHERE sku_ozon = %s LIMIT 1
                    """, (sku,))
                    
                    result = cursor.fetchone()
                    if not result:
                        print(f"  ⚠ Product not found for SKU: {sku}")
                        skipped += 1
                        continue
                    
                    product_id = result[0]
                    
                    # Insert order record
                    cursor.execute("""
                        INSERT INTO fact_orders 
                        (product_id, order_id, transaction_type, sku, qty, price, order_date, client_id, source_id)
                        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
                        ON CONFLICT (order_id, sku) DO UPDATE SET
                            qty = EXCLUDED.qty,
                            price = EXCLUDED.price,
                            updated_at = CURRENT_TIMESTAMP
                    """, (
                        product_id,
                        posting_number,
                        'sale',
                        sku,
                        quantity,
                        price,
                        order_date,
                        1,  # Default client_id
                        1   # Default source_id (Ozon)
                    ))
                    
                    imported += 1
                
            except Exception as e:
                print(f"  ✗ Error importing posting {posting.get('posting_number', 'unknown')}: {e}")
                skipped += 1
                continue
        
        conn.commit()
        cursor.close()
        conn.close()
        
        print(f"✓ Imported: {imported} order items")
        print(f"⚠ Skipped: {skipped} items")
        
        return imported
        
    except psycopg2.Error as e:
        print(f"✗ Database error: {e}")
        return 0

def main():
    """Main function"""
    print("=" * 70)
    print("Ozon Sales Data Import (from 2025-08-01)")
    print("=" * 70)
    print()
    
    # Fetch data from Ozon API starting from August 1, 2025
    sales_data = fetch_sales_data(date_from="2025-08-01")
    
    if not sales_data:
        print("\n✗ No data fetched from Ozon API")
        sys.exit(1)
    
    # Import to PostgreSQL
    imported = import_to_postgresql(sales_data)
    
    print()
    print("=" * 70)
    if imported > 0:
        print(f"✓ Import completed successfully! {imported} order items imported")
    else:
        print("✗ Import failed or no records imported")
    print("=" * 70)

if __name__ == "__main__":
    main()
