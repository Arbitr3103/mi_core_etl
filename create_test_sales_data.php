<?php
/**
 * Create test sales data for replenishment system testing
 */

require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    echo "🧪 Creating test sales data...\n\n";
    
    // Create test sales table
    echo "1. Creating test_sales table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS test_sales (
            id INT PRIMARY KEY AUTO_INCREMENT,
            product_id INT NOT NULL,
            order_date DATE NOT NULL,
            quantity INT NOT NULL,
            price DECIMAL(10,2),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_product_date (product_id, order_date),
            INDEX idx_order_date (order_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    echo "✅ Table created\n";
    
    // Get active products
    echo "\n2. Getting active products...\n";
    $stmt = $pdo->query("SELECT id, name FROM dim_products WHERE is_active = 1 LIMIT 10");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($products) . " active products\n";
    
    // Generate test sales data for last 45 days
    echo "\n3. Generating test sales data...\n";
    
    $insertStmt = $pdo->prepare("
        INSERT INTO test_sales (product_id, order_date, quantity, price) 
        VALUES (?, ?, ?, ?)
    ");
    
    $totalRecords = 0;
    
    foreach ($products as $product) {
        $productId = $product['id'];
        $productName = $product['name'];
        
        echo "   Generating sales for: $productName (ID: $productId)\n";
        
        // Generate sales for last 45 days
        for ($day = 45; $day >= 1; $day--) {
            $date = date('Y-m-d', strtotime("-$day days"));
            
            // Simulate different sales patterns
            $baseADS = rand(1, 5); // Base average daily sales
            
            // Add some randomness (some days no sales, some days higher)
            $randomFactor = rand(0, 100);
            
            if ($randomFactor < 20) {
                // 20% chance of no sales
                $quantity = 0;
            } elseif ($randomFactor < 70) {
                // 50% chance of normal sales
                $quantity = $baseADS + rand(-1, 1);
            } else {
                // 30% chance of higher sales
                $quantity = $baseADS + rand(2, 5);
            }
            
            $quantity = max(0, $quantity); // Ensure non-negative
            
            if ($quantity > 0) {
                $price = rand(50, 300) + (rand(0, 99) / 100); // Random price
                
                $insertStmt->execute([$productId, $date, $quantity, $price]);
                $totalRecords++;
            }
        }
    }
    
    echo "\n✅ Generated $totalRecords sales records\n";
    
    // Create inventory snapshots (for stock availability tracking)
    echo "\n4. Creating test inventory snapshots...\n";
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS test_inventory_snapshots (
            id INT PRIMARY KEY AUTO_INCREMENT,
            product_id INT NOT NULL,
            snapshot_date DATE NOT NULL,
            stock_quantity INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_product_date (product_id, snapshot_date),
            UNIQUE KEY uk_product_date (product_id, snapshot_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    $inventoryStmt = $pdo->prepare("
        INSERT INTO test_inventory_snapshots (product_id, snapshot_date, stock_quantity) 
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE stock_quantity = VALUES(stock_quantity)
    ");
    
    $inventoryRecords = 0;
    
    foreach ($products as $product) {
        $productId = $product['id'];
        
        // Generate inventory snapshots for last 45 days
        for ($day = 45; $day >= 1; $day--) {
            $date = date('Y-m-d', strtotime("-$day days"));
            
            // Simulate stock levels (some days zero stock)
            $randomStock = rand(0, 100);
            
            if ($randomStock < 15) {
                // 15% chance of zero stock
                $stock = 0;
            } else {
                // Normal stock levels
                $stock = rand(5, 100);
            }
            
            $inventoryStmt->execute([$productId, $date, $stock]);
            $inventoryRecords++;
        }
    }
    
    echo "✅ Generated $inventoryRecords inventory snapshots\n";
    
    // Show summary
    echo "\n📊 Test data summary:\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM test_sales");
    $salesCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "- Sales records: $salesCount\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM test_inventory_snapshots");
    $inventoryCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "- Inventory snapshots: $inventoryCount\n";
    
    $stmt = $pdo->query("
        SELECT 
            product_id,
            COUNT(*) as sales_days,
            SUM(quantity) as total_quantity,
            AVG(quantity) as avg_quantity
        FROM test_sales 
        GROUP BY product_id 
        LIMIT 5
    ");
    
    echo "\nSample sales statistics:\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "- Product {$row['product_id']}: {$row['sales_days']} days, {$row['total_quantity']} total, {$row['avg_quantity']} avg/day\n";
    }
    
    echo "\n🎉 Test data created successfully!\n";
    echo "You can now test the SalesAnalyzer with this data.\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>