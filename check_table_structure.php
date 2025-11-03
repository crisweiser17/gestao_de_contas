<?php
require_once 'config/config.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Conexão estabelecida<br><br>";
} catch(PDOException $e) {
    die("❌ Erro: " . $e->getMessage());
}

// Verificar estrutura da tabela accounts
echo "<h2>🔍 Estrutura da tabela 'accounts':</h2>";
$stmt = $pdo->query("DESCRIBE accounts");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' style='border-collapse: collapse;'>";
echo "<tr style='background: #f0f0f0;'><th>Campo</th><th>Tipo</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
foreach ($columns as $column) {
    $highlight = $column['Field'] == 'is_recurring' ? 'background: #ffffcc;' : '';
    echo "<tr style='{$highlight}'>";
    echo "<td>{$column['Field']}</td>";
    echo "<td>{$column['Type']}</td>";
    echo "<td>{$column['Null']}</td>";
    echo "<td>{$column['Key']}</td>";
    echo "<td>{$column['Default']}</td>";
    echo "<td>{$column['Extra']}</td>";
    echo "</tr>";
}
echo "</table>";

// Verificar valores únicos do campo is_recurring
echo "<br><h2>📊 Valores do campo 'is_recurring':</h2>";
$stmt = $pdo->query("SELECT is_recurring, COUNT(*) as count FROM accounts GROUP BY is_recurring");
$recurringStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($recurringStats as $stat) {
    echo "<p>is_recurring = '{$stat['is_recurring']}': {$stat['count']} contas</p>";
}

// Verificar algumas contas específicas
echo "<br><h2>🔍 Contas específicas da imagem:</h2>";
$specificAccounts = [
    'Contabilidade AMX',
    'Imposto AMX', 
    'HS Consórcios',
    'Condomínio Conj. dos Passaros',
    'QG Cardoso de Melo',
    'Aluguel Apto Pira',
    'Seguro Saude Sulamerica'
];

foreach ($specificAccounts as $accountName) {
    $stmt = $pdo->prepare("SELECT id, description, is_recurring FROM accounts WHERE description LIKE ? LIMIT 1");
    $stmt->execute(["%{$accountName}%"]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($account) {
        $icon = $account['is_recurring'] == 1 ? '🔄' : '⚪';
        echo "<p>{$icon} <strong>{$account['description']}</strong> (ID: {$account['id']}) - is_recurring: {$account['is_recurring']}</p>";
    } else {
        echo "<p>❓ Não encontrado: {$accountName}</p>";
    }
}
?>