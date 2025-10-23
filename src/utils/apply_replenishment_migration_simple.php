<?php
/**
 * Simple Replenishment Schema Migration
 */

require_once __DIR__ . '/config.php';

try {
    echo "🚀 Creating replenishment schema...\n";
    
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Read and execute migration
    // Create recommendations table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS replenishment_recommendations (
            id INT PRIMARY KEY AUTO_INCREMENT,
            product_id INT NOT NULL,
            product_name VARCHAR(255) NOT NULL,
            sku VARCHAR(100),
            ads DECIMAL(10,2) NOT NULL,
            current_stock INT NOT NULL,
            target_stock INT NOT NULL,
            recommended_quantity INT NOT NULL,
            calculation_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_calculation_date (calculation_date),
            INDEX idx_product_id (product_id),
            INDEX idx_recommended_quantity (recommended_quantity DESC),
            UNIQUE KEY uk_product_date (product_id, calculation_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // Create config table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS replenishment_config (
            id INT PRIMARY KEY AUTO_INCREMENT,
            parameter_name VARCHAR(100) NOT NULL UNIQUE,
            parameter_value VARCHAR(255) NOT NULL,
            parameter_type ENUM('int', 'float', 'string', 'boolean') DEFAULT 'string',
            description TEXT,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_parameter_name (parameter_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // Create calculations log table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS replenishment_calculations (
            id INT PRIMARY KEY AUTO_INCREMENT,
            calculation_date DATE NOT NULL,
            products_processed INT NOT NULL DEFAULT 0,
            recommendations_generated INT NOT NULL DEFAULT 0,
            execution_time_seconds INT DEFAULT NULL,
            status ENUM('running', 'success', 'error', 'partial') NOT NULL DEFAULT 'running',
            error_message TEXT DEFAULT NULL,
            started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            completed_at TIMESTAMP NULL,
            
            INDEX idx_calculation_date (calculation_date),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    echo "✅ Tables created successfully!\n";
    
    // Insert default configuration
    echo "⚙️  Inserting default configuration...\n";
    
    $configData = [
        ['replenishment_days', '14', 'int', 'Период пополнения в днях'],
        ['safety_days', '7', 'int', 'Страховой запас в днях'],
        ['analysis_days', '30', 'int', 'Период анализа продаж в днях'],
        ['min_ads_threshold', '0.1', 'float', 'Минимальный ADS для включения в рекомендации']
    ];
    
    $stmt = $pdo->prepare("
        INSERT INTO replenishment_config (parameter_name, parameter_value, parameter_type, description) 
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            parameter_value = VALUES(parameter_value),
            updated_at = CURRENT_TIMESTAMP
    ");
    
    foreach ($configData as $config) {
        $stmt->execute($config);
        echo "   • {$config[0]}: {$config[1]}\n";
    }
    
    // Verify tables
    echo "\n🔍 Verifying tables...\n";
    $tables = ['replenishment_recommendations', 'replenishment_config', 'replenishment_calculations'];
    
    foreach ($tables as $table) {
        $result = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($result->rowCount() > 0) {
            echo "   ✅ $table\n";
        } else {
            echo "   ❌ $table NOT FOUND\n";
        }
    }
    
    echo "\n🎉 Migration completed successfully!\n";
    echo "Ready to implement replenishment system.\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>