<?php
declare(strict_types=1);

function app_config(): array
{
    return [
        'host' => getenv('TOKO_DB_HOST') ?: '127.0.0.1',
        'port' => getenv('TOKO_DB_PORT') ?: '3306',
        'database' => getenv('TOKO_DB_NAME') ?: 'toko_kita',
        'username' => getenv('TOKO_DB_USER') ?: 'root',
        'password' => getenv('TOKO_DB_PASSWORD') ?: '',
    ];
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = app_config();
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $config['database'])) {
        throw new RuntimeException('Nama database pada konfigurasi tidak valid.');
    }
    $baseDsn = "mysql:host={$config['host']};port={$config['port']};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $server = new PDO($baseDsn, $config['username'], $config['password'], $options);
    $server->exec("CREATE DATABASE IF NOT EXISTS `{$config['database']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = new PDO($baseDsn . ";dbname={$config['database']}", $config['username'], $config['password'], $options);
    initialize_schema($pdo);
    return $pdo;
}

function initialize_schema(PDO $pdo): void
{
    $statements = [
        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            username VARCHAR(60) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS customers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            city VARCHAR(100) NOT NULL,
            phone VARCHAR(30) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_customer_name (name)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS products (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            category VARCHAR(60) NOT NULL,
            price DECIMAL(14,2) NOT NULL,
            cost DECIMAL(14,2) NOT NULL DEFAULT 0,
            stock INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_product_name (name)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS sales (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            invoice VARCHAR(40) NOT NULL UNIQUE,
            customer_id INT UNSIGNED NULL,
            customer_name VARCHAR(120) NOT NULL,
            sale_date DATE NOT NULL,
            status ENUM('completed','partial_return','cancelled') NOT NULL DEFAULT 'completed',
            note VARCHAR(500) NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sale_date (sale_date),
            INDEX idx_sale_customer (customer_id),
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS sale_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sale_id BIGINT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NULL,
            product_name VARCHAR(150) NOT NULL,
            quantity INT UNSIGNED NOT NULL,
            returned_quantity INT UNSIGNED NOT NULL DEFAULT 0,
            unit_price DECIMAL(14,2) NOT NULL,
            unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
            FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
            INDEX idx_item_product (product_id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS stock_movements (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id INT UNSIGNED NULL,
            product_name VARCHAR(150) NOT NULL,
            movement_type ENUM('opening','sale','restock','return','cancel','adjustment') NOT NULL,
            quantity INT NOT NULL,
            unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
            reference_id BIGINT UNSIGNED NULL,
            note VARCHAR(500) NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_movement_created (created_at),
            INDEX idx_movement_product (product_id),
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB",
    ];
    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    $count = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    if ($count === 0) {
        $products = [
            ['Beras 5 kg', 'Sembako', 65000, 52000, 87],
            ['Minyak Goreng 2 L', 'Sembako', 38000, 30000, 88],
            ['Gula 1 kg', 'Sembako', 17000, 13000, 87],
            ['Kopi Sachet (box)', 'Minuman', 25000, 19000, 92],
            ['Teh Celup (box)', 'Minuman', 15000, 11000, 88],
            ['Sabun Mandi', 'Kebersihan', 12000, 8500, 83],
            ['Deterjen 1 kg', 'Kebersihan', 28000, 22000, 94],
            ['Mie Instan (dus)', 'Sembako', 110000, 90000, 93],
        ];
        $insert = $pdo->prepare('INSERT INTO products (name, category, price, cost, stock) VALUES (?, ?, ?, ?, ?)');
        foreach ($products as $product) {
            $insert->execute($product);
        }
    }

    $count = (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
    if ($count === 0) {
        $customers = [
            ['Andi','Ponorogo',''], ['Budi','Madiun',''], ['Citra','Surabaya',''], ['Dewi','Ponorogo',''],
            ['Eko','Magetan',''], ['Fitri','Madiun',''], ['Gunawan','Surabaya',''], ['Hana','Ponorogo',''],
            ['Indra','Magetan',''], ['Joko','Madiun',''],
        ];
        $insert = $pdo->prepare('INSERT INTO customers (name, city, phone) VALUES (?, ?, ?)');
        foreach ($customers as $customer) {
            $insert->execute($customer);
        }
    }
}

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}
