<?php
require_once __DIR__ . '/../config/config.php';

function columnExists(PDO $pdo, string $dbName, string $table, string $column): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl AND COLUMN_NAME = :col"
    );
    $stmt->execute([':db' => $dbName, ':tbl' => $table, ':col' => $column]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int)$row['cnt'] > 0;
}

function indexExists(PDO $pdo, string $dbName, string $table, string $index): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl AND INDEX_NAME = :idx"
    );
    $stmt->execute([':db' => $dbName, ':tbl' => $table, ':idx' => $index]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int)$row['cnt'] > 0;
}

try {
    $dbName = DB_NAME;

    $created = [];

    // Garantir coluna 'name'
    if (!columnExists($pdo, $dbName, 'accounts', 'name')) {
        $pdo->exec("ALTER TABLE accounts ADD COLUMN `name` VARCHAR(255) NULL AFTER `description`");
        $created[] = "coluna 'name'";
    }

    // Garantir índice em 'name'
    if (!indexExists($pdo, $dbName, 'accounts', 'idx_accounts_name')) {
        $pdo->exec("CREATE INDEX idx_accounts_name ON accounts(`name`)");
        $created[] = "índice 'idx_accounts_name'";
    }

    if (empty($created)) {
        echo "ℹ️ Nenhuma alteração necessária: coluna e índice já existem.";
    } else {
        echo "✅ Migration concluída: " . implode(", ", $created) . " criados.";
    }
} catch (Exception $e) {
    http_response_code(500);
    echo "❌ Erro ao aplicar migration: " . $e->getMessage();
}