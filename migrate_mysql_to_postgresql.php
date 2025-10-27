#!/usr/bin/env php
<?php
/**
 * Migrate data from MySQL to PostgreSQL
 * Transfers real ETОНОВО product data from MySQL mi_core to PostgreSQL mi_core_db
 */

echo "Starting MySQL to PostgreSQL migration...\n\n";

try {
    // Connect to MySQL
    $mysql = new PDO('mysql:host=localhost;dbname=mi_core', 'root', '');
    $mysql->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✓ Connected to MySQL\n";
    
    // Connect to PostgreSQL
    $pgsql = new PDO('pgsql:host=localhost;dbname=mi_core_db', 'vladimirbragin', '');
    $pgsql->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✓ Connected to PostgreSQL\n\n";
    
    // Clear existing test data from PostgreSQL
    echo "Clearing test data from PostgreSQL...\n";
    $pgsql->exec("TRUNCATE TABLE inventory CASCADE;");
    $pgsql->exec("DELETE FROM dim_products WHERE sku_ozon NOT LIKE '%[0-9]%' OR sku_ozon ~ '^SKU';");
    echo "✓ Test data cleared\n\n";
    
    // Migrate dim_products
    echo "Migrating dim_products...\n";
    $stmt = $mysql->query("
        SELECT id, sku_ozon, sku_wb, barcode, product_name, brand, category, cost_price, 
               created_at, updated_at, is_active
        FROM dim_products 
        WHERE sku_ozon IS NOT NULL AND sku_ozon REGEXP '^[0-9]+$'
    ");
    
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($products) . " products in MySQL\n";
    
    $insertStmt = $pgsql->prepare("
        INSERT INTO dim_products (sku_ozon, sku_wb, barcode, product_name, cost_price, created_at, updated_at)
        VALUES (:sku_ozon, :sku_wb, :barcode, :product_name, :cost_price, :created_at, :updated_at)
        ON CONFLICT (sku_ozon) DO UPDATE SET
            sku_wb = EXCLUDED.sku_wb,
            barcode = EXCLUDED.barcode,
            product_name = EXCLUDED.product_name,
            cost_price = EXCLUDED.cost_price,
            updated_at = EXCLUDED.updated_at
        RETURNING id
    ");
    
    $productIdMap = [];
    foreach ($products as $product) {
        $insertStmt->execute([
            'sku_ozon' => $product['sku_ozon'],
            'sku_wb' => $product['sku_wb'],
            'barcode' => $product['barcode'],
            'product_name' => $product['product_name'],
            'cost_price' => $product['cost_price'],
            'created_at' => $product['created_at'],
            'updated_at' => $product['updated_at']
        ]);
        
        $result = $insertStmt->fetch(PDO::FETCH_ASSOC);
        $productIdMap[$product['sku_ozon']] = $result['id'];
    }
    echo "✓ Migrated " . count($products) . " products\n\n";
    
    // Migrate inventory_data to inventory
    echo "Migrating inventory data...\n";
    $stmt = $mysql->query("
        SELECT sku, warehouse_name, current_stock, reserved_stock, last_sync_at
        FROM inventory_data 
        WHERE sku IS NOT NULL AND sku REGEXP '^[0-9]+$'
    ");
    
    $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($inventory) . " inventory records in MySQL\n";
    
    $insertStmt = $pgsql->prepare("
        INSERT INTO inventory (product_id, warehouse_name, stock_type, source, quantity_present, quantity_reserved, updated_at)
        VALUES (:product_id, :warehouse_name, 'FBO', 'ozon', :quantity_present, :quantity_reserved, :updated_at)
        ON CONFLICT (product_id, warehouse_name, source) DO UPDATE SET
            stock_type = EXCLUDED.stock_type,
            quantity_present = EXCLUDED.quantity_present,
            quantity_reserved = EXCLUDED.quantity_reserved,
            updated_at = EXCLUDED.updated_at
    ");
    
    $migratedCount = 0;
    foreach ($inventory as $inv) {
        if (isset($productIdMap[$inv['sku']])) {
            $insertStmt->execute([
                'product_id' => $productIdMap[$inv['sku']],
                'warehouse_name' => $inv['warehouse_name'],
                'quantity_present' => $inv['current_stock'] ?? 0,
                'quantity_reserved' => $inv['reserved_stock'] ?? 0,
                'updated_at' => $inv['last_sync_at'] ?? date('Y-m-d H:i:s')
            ]);
            $migratedCount++;
        }
    }
    echo "✓ Migrated $migratedCount inventory records\n\n";
    
    // Verify migration
    echo "Verifying migration...\n";
    $pgProductCount = $pgsql->query("SELECT COUNT(*) FROM dim_products WHERE sku_ozon ~ '^[0-9]+$'")->fetchColumn();
    $pgInventoryCount = $pgsql->query("SELECT COUNT(*) FROM inventory")->fetchColumn();
    
    echo "PostgreSQL now has:\n";
    echo "  - $pgProductCount products\n";
    echo "  - $pgInventoryCount inventory records\n\n";
    
    echo "✓ Migration completed successfully!\n\n";
    echo "You can now safely remove MySQL if desired.\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
