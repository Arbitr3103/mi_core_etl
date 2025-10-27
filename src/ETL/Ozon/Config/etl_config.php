<?php

return array (
  'database' => 
  array (
    'host' => 'localhost',
    'port' => '5432',
    'database' => 'mi_core_db',
    'username' => 'vladimirbragin',
    'password' => '',
    'charset' => 'utf8',
    'options' => 
    array (
      3 => 2,
      19 => 2,
      20 => false,
    ),
  ),
  'ozon_api' => 
  array (
    'client_id' => '26100',
    'api_key' => '7e074977-e0db-4ace-ba9e-82903e088b4b',
    'base_url' => 'https://api-seller.ozon.ru',
    'timeout' => 30,
    'max_retries' => 3,
    'retry_delay' => 1,
  ),
  'product_etl' => 
  array (
    'batch_size' => 1000,
    'max_products' => 0,
    'enable_progress' => true,
  ),
  'inventory_etl' => 
  array (
    'report_language' => 'DEFAULT',
    'max_wait_time' => 1800,
    'poll_interval' => 60,
    'validate_csv_structure' => true,
  ),
);
