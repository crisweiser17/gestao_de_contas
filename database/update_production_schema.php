<?php
// Atualiza o schema do banco de PRODUÇÃO criando campos extras necessários
// - Conecta usando credenciais de produção (Cloudways)
// - Não altera dados existentes
// - Idempotente: só cria o que estiver faltando

header('Content-Type: text/html; charset=utf-8');

// Conecta direto ao banco de produção
require_once __DIR__ . '/../config/database_hosting.php';
$database = new Database();
$pdo = $database->getConnection();

if (!$pdo) {
    echo "<p style='color: red;'>❌ Não foi possível conectar ao banco de PRODUÇÃO.</p>";
    exit;
}

// Descobrir o nome do banco atual
try {
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
} catch (Exception $e) {
    echo "<p style='color: red;'>Erro ao obter nome do banco: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

function columnExists(PDO $pdo, string $db, string $table, string $column): bool {
    $sql = "SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl AND COLUMN_NAME = :col";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':db' => $db, ':tbl' => $table, ':col' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function indexExists(PDO $pdo, string $db, string $table, string $index): bool {
    $sql = "SELECT COUNT(*) AS cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl AND INDEX_NAME = :idx";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':db' => $db, ':tbl' => $table, ':idx' => $index]);
    return (int)$stmt->fetchColumn() > 0;
}

$created = [];
$skipped = [];
$errors = [];

// 1) accounts.name (e índice)
try {
    if (!columnExists($pdo, $dbName, 'accounts', 'name')) {
        $pdo->exec("ALTER TABLE accounts ADD COLUMN `name` VARCHAR(255) NULL AFTER `description`");
        $created[] = "coluna accounts.name";
    } else {
        $skipped[] = "accounts.name já existe";
    }
    
    if (!indexExists($pdo, $dbName, 'accounts', 'idx_accounts_name')) {
        $pdo->exec("CREATE INDEX idx_accounts_name ON accounts(`name`)");
        $created[] = "índice idx_accounts_name";
    } else {
        $skipped[] = "índice idx_accounts_name já existe";
    }
} catch (Exception $e) {
    $errors[] = "accounts.name / índice: " . $e->getMessage();
}

// 2) accounts.attachment
try {
    if (!columnExists($pdo, $dbName, 'accounts', 'attachment')) {
        $pdo->exec("ALTER TABLE accounts ADD COLUMN `attachment` VARCHAR(255) NULL AFTER `notes`");
        $created[] = "coluna accounts.attachment";
    } else {
        $skipped[] = "accounts.attachment já existe";
    }
} catch (Exception $e) {
    $errors[] = "accounts.attachment: " . $e->getMessage();
}

// 3) users.last_login
try {
    if (!columnExists($pdo, $dbName, 'users', 'last_login')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN `last_login` TIMESTAMP NULL DEFAULT NULL AFTER `updated_at`");
        $created[] = "coluna users.last_login";
    } else {
        $skipped[] = "users.last_login já existe";
    }
} catch (Exception $e) {
    $errors[] = "users.last_login: " . $e->getMessage();
}

// Saída amigável
echo "<h2>🔧 Atualização de Schema (Produção)</h2>";
echo "<p><strong>Banco:</strong> " . htmlspecialchars($dbName) . "</p>";

if ($created) {
    echo "<h3>✅ Itens criados:</h3><ul>";
    foreach ($created as $item) {
        echo "<li>" . htmlspecialchars($item) . "</li>";
    }
    echo "</ul>";
} else {
    echo "<p>✅ Nada a criar. Estrutura já estava atualizada.</p>";
}

if ($skipped) {
    echo "<h3>ℹ️ Já existiam:</h3><ul>";
    foreach ($skipped as $item) {
        echo "<li>" . htmlspecialchars($item) . "</li>";
    }
    echo "</ul>";
}

if ($errors) {
    echo "<h3 style='color: red;'>❌ Erros:</h3><ul style='color: red;'>";
    foreach ($errors as $err) {
        echo "<li>" . htmlspecialchars($err) . "</li>";
    }
    echo "</ul>";
} else {
    echo "<p><strong>🎉 Concluído sem erros.</strong></p>";
}

// Ação sugerida
echo "<p><a href='../accounts.php' style='background:#007cba;color:#fff;padding:8px 14px;border-radius:6px;text-decoration:none;'>Voltar para Contas</a></p>";

?>