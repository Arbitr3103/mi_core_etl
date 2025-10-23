<?php
/**
 * Create fact_orders table and populate with test data
 */

require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    echo "🏗️  Creating fact_orders table for sales data...\n\n";
    
    // Create fact_orders table
    echo "1. Creating fact_orders table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fact_orders (
            id INT PRIMARY KEY AUTO_INCREMENT,
            order_id VARCHAR(100) NOT NULL,
            product_id INT NOT NULL,
            sku VARCHAR(100),
            order_date DATE NOT NULL,
            qty INT NOT NULL DEFAULT 1,
            price DECIMAL(10,2) NOT NULL,
            cost_price DECIMAL(10,2) DEFAULT NULL,
            source_id INT DEFAULT 1,
            warehouse_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_product_id (product_id),
            INDEX idx_order_date (order_date),
            INDEX idx_product_date (product_id, order_date),
            INDEX idx_source_id (source_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    echo "✅ Table created\n";
    
    // Create dim_sources table if not exists
    echo "\n2. Creating dim_sources table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dim_sources (
            id INT PRIMARY KEY AUTO_INCREMENT,
            code VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // Insert Ozon source
    $pdo->exec("
        INSERT INTO dim_sources (id, code, name, description) 
        VALUES (1, 'ozon', 'Ozon', 'Ozon marketplace')
        ON DUPLICATE KEY UPDATE 
            name = VALUES(name),
            description = VALUES(description)
    ");
    
    echo "✅ Sources table created\n";
    
    // Get active products for test data
    echo "\n3. Getting active products...\n";
    $stmt = $pdo->query("SELECT id, name, sku_ozon FROM dim_products WHERE is_active = 1 LIMIT 20");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($products) . " active products\n";
    
    if (empty($products)) {
        echo "❌ No active products found. Cannot create test sales data.\n";
        exit(1);
    }
    
    // Generate test sales data for last 60 days
    echo "\n4. Generating test sales data...\n";
    
    $insertStmt = $pdo->prepare("
        INSERT INTO fact_orders (order_id, product_id, sku, order_date, qty, price, cost_price, source_id) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 1)
    ");
    
    $totalOrders = 0;
    $orderCounter = 1;
    
    foreach ($products as $product) {
        $productId = $product['id'];
        $productName = $product['name'];
        $sku = $product['sku_ozon'];
        
        echo "   Generating sales for: $productName (ID: $productId)\n";
        
        // Generate sales for last 60 days
        for ($day = 60; $day >= 1; $day--) {
            $date = date('Y-m-d', strtotime("-$day days"));
            
            // Simulate different sales patterns based on product
            $baseADS = rand(1, 8); // Base average daily sales
            
            // Add day-of-week patterns (weekends might be different)
            $dayOfWeek = date('N', strtotime($date)); // 1 = Monday, 7 = Sunday
            if ($dayOfWeek >= 6) { // Weekend
                $baseADS = round($baseADS * 1.2); // 20% more sales on weekends
            }
            
            // Add some randomness
            $randomFactor = rand(0, 100);
            
            if ($randomFactor < 25) {
                // 25% chance of no sales (out of stock or low demand)
                $qty = 0;
            } elseif ($randomFactor < 70) {
                // 45% chance of normal sales
                $qty = $baseADS + rand(-2, 2);
            } else {
                // 30% chance of higher sales (promotions, etc.)
                $qty = $baseADS + rand(3, 8);
            }
            
            $qty = max(0, $qty); // Ensure non-negative
            
            if ($qty > 0) {
                // Generate multiple orders for the day if qty > 1
                $ordersToday = min($qty, rand(1, 3)); // 1-3 orders per day max
                $qtyPerOrder = ceil($qty / $ordersToday);
                
                for ($order = 0; $order < $ordersToday; $order++) {
                    $orderQty = min($qtyPerOrder, $qty);
                    if ($orderQty <= 0) break;
                    
                    $orderId = 'ORD-' . date('Ymd', strtotime($date)) . '-' . str_pad($orderCounter++, 6, '0', STR_PAD_LEFT);
                    $price = rand(80, 400) + (rand(0, 99) / 100); // Random price 80-400 rubles
                    $costPrice = $price * (rand(40, 70) / 100); // Cost is 40-70% of price
                    
                    $insertStmt->execute([
                        $orderId,
                        $productId,
                        $sku,
                        $date,
                        $orderQty,
                        $price,
                        $costPrice
                    ]);
                    
                    $totalOrders++;
                    $qty -= $orderQty;
                }
            }
        }
    }
    
    echo "\n✅ Generated $totalOrders orders\n";
    
    // Show summary statistics
    echo "\n📊 Sales data summary:\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM fact_orders");
    $orderCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "- Total orders: $orderCount\n";
    
    $stmt = $pdo->query("SELECT MIN(order_date) as min_date, MAX(order_date) as max_date FROM fact_orders");
    $dateRange = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "- Date range: {$dateRange['min_date']} to {$dateRange['max_date']}\n";
    
    $stmt = $pdo->query("
        SELECT 
            product_id,
            COUNT(*) as order_count,
            SUM(qty) as total_qty,
            ROUND(AVG(qty), 2) as avg_qty_per_order,
            ROUND(SUM(qty) / COUNT(DISTINCT order_date), 2) as estimated_ads
        FROM fact_orders 
        GROUP BY product_id 
        ORDER BY total_qty DESC 
        LIMIT 5
    ");
    
    echo "\nTop 5 products by sales volume:\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "- Product {$row['product_id']}: {$row['order_count']} orders, {$row['total_qty']} units, ~{$row['estimated_ads']} ADS\n";
    }
    
    echo "\n🎉 fact_orders table created and populated with test data!\n";
    echo "Now you can test the SalesAnalyzer with real sales data structure.\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>