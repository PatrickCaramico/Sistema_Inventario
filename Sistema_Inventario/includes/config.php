<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

loadEnvironment();

function env(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return (string) $value;
}

function loadEnvironment(): void
{
    $root = dirname(__DIR__);
    $files = [$root . '/.env', $root . '/.env.local'];

    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            continue;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $trimmed, 2);
            $name = trim($name);
            $value = trim($value);

            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            if ($name !== '' && env($name) === null) {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = env('DB_HOST', '127.0.0.1');
    $port = env('DB_PORT', '3306');
    $database = env('DB_DATABASE', 'Sistema_Inventario');
    $username = env('DB_USERNAME', 'root');
    $password = env('DB_PASSWORD', '');

    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('A extensao pdo_mysql nao esta habilitada no PHP. Ative no php.ini para usar MySQL.');
    }

    try {
        $pdo = connectMySql($host, $port, $database, $username, $password);
    } catch (PDOException $e) {
        $errorCode = (string) ($e->errorInfo[1] ?? '');

        if ($errorCode !== '1049') {
            throw $e;
        }

        ensureDatabaseExists($host, $port, $database, $username, $password);
        $pdo = connectMySql($host, $port, $database, $username, $password);
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    initializeDatabase($pdo);

    return $pdo;
}

function connectMySql(string $host, string $port, string $database, string $username, string $password): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database);
    return new PDO($dsn, $username, $password);
}

function ensureDatabaseExists(string $host, string $port, string $database, string $username, string $password): void
{
    $rootDsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port);
    $rootPdo = new PDO($rootDsn, $username, $password);
    $rootPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $safeDbName = str_replace('`', '``', $database);
    $rootPdo->exec('CREATE DATABASE IF NOT EXISTS `' . $safeDbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}

function initializeDatabase(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT "funcionario",
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    ensureRoleColumn($pdo);

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(190) NOT NULL,
            category VARCHAR(120) NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            quantity INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT chk_price_non_negative CHECK (price >= 0),
            CONSTRAINT chk_quantity_non_negative CHECK (quantity >= 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            user_name VARCHAR(120) NOT NULL,
            action VARCHAR(60) NOT NULL,
            product_id INT NULL,
            details TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_created_at (created_at),
            INDEX idx_audit_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $checkUser = $pdo->query('SELECT COUNT(*) AS total FROM users')->fetch();
    if ((int) ($checkUser['total'] ?? 0) === 0) {
        $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (:name, :email, :password_hash, :role)');
        $stmt->execute([
            ':name' => 'Administrador',
            ':email' => 'admin@inventario.com',
            ':password_hash' => password_hash('123456', PASSWORD_DEFAULT),
            ':role' => 'admin',
        ]);
    }

    $checkEmployee = $pdo->prepare('SELECT COUNT(*) AS total FROM users WHERE email = :email');
    $checkEmployee->execute([':email' => 'funcionario@inventario.com']);
    $employee = $checkEmployee->fetch();

    if ((int) ($employee['total'] ?? 0) === 0) {
        $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (:name, :email, :password_hash, :role)');
        $stmt->execute([
            ':name' => 'Funcionario',
            ':email' => 'funcionario@inventario.com',
            ':password_hash' => password_hash('123456', PASSWORD_DEFAULT),
            ':role' => 'funcionario',
        ]);
    }

    $checkProducts = $pdo->query('SELECT COUNT(*) AS total FROM products')->fetch();
    if ((int) ($checkProducts['total'] ?? 0) === 0) {
        $seedProducts = [
            ['Mouse Gamer N12', 'Mouses', 129.90, 12],
            ['Teclado Mecanico K82', 'Teclados', 319.50, 4],
            ['Monitor UltraView 24', 'Monitores', 899.00, 7],
            ['Notebook Core i5 Pro', 'Notebooks', 3799.99, 3],
            ['Cabo HDMI 2m', 'Cabos', 34.90, 25],
        ];

        $seedStmt = $pdo->prepare('INSERT INTO products (name, category, price, quantity) VALUES (:name, :category, :price, :quantity)');
        foreach ($seedProducts as $product) {
            $seedStmt->execute([
                ':name' => $product[0],
                ':category' => $product[1],
                ':price' => $product[2],
                ':quantity' => $product[3],
            ]);
        }
    }
}

function ensureRoleColumn(PDO $pdo): void
{
    $columnStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
    $column = $columnStmt->fetch();

    if ($column) {
        return;
    }

    $pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'funcionario' AFTER password_hash");

    $updateAdmin = $pdo->prepare('UPDATE users SET role = :role WHERE email = :email');
    $updateAdmin->execute([
        ':role' => 'admin',
        ':email' => 'admin@inventario.com',
    ]);
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

function sessionTimeoutSeconds(): int
{
    $raw = env('SESSION_TIMEOUT_SECONDS', '1800');
    $seconds = (int) ($raw ?? '1800');

    return $seconds >= 60 ? $seconds : 1800;
}

function sessionRemainingSeconds(): int
{
    $timeout = sessionTimeoutSeconds();
    $lastActivity = (int) ($_SESSION['last_activity_at'] ?? 0);

    if ($lastActivity <= 0) {
        return $timeout;
    }

    $remaining = $timeout - (time() - $lastActivity);
    return $remaining > 0 ? $remaining : 0;
}

function destroySessionAndCookies(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }

    session_destroy();
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }

    if (sessionRemainingSeconds() <= 0) {
        destroySessionAndCookies();
        header('Location: login.php?status=timeout');
        exit;
    }

    $_SESSION['last_activity_at'] = time();

    if (!isset($_SESSION['login_at'])) {
        $_SESSION['login_at'] = time();
    }
}

function currentUserName(): string
{
    return (string) ($_SESSION['user_name'] ?? 'Usuario');
}

function currentUserRole(): string
{
    $role = (string) ($_SESSION['user_role'] ?? 'funcionario');
    return $role === 'admin' ? 'admin' : 'funcionario';
}

function isAdmin(): bool
{
    return currentUserRole() === 'admin';
}
