<?php
require_once 'config/config.php';
require_once 'models/Account.php';

// Conectar ao banco
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Conexão com banco estabelecida<br><br>";
} catch(PDOException $e) {
    die("❌ Erro de conexão: " . $e->getMessage());
}

// Buscar todas as contas com informações de recorrência
$query = "SELECT id, description, type, is_recurring, amount, due_date, status 
          FROM accounts 
          WHERE user_id = 1 
          ORDER BY due_date DESC 
          LIMIT 20";

$stmt = $pdo->prepare($query);
$stmt->execute();
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h2>🔍 Debug: Status das Contas Recorrentes</h2>";
echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
echo "<tr style='background: #f0f0f0;'>";
echo "<th>ID</th><th>Descrição</th><th>Tipo</th><th>is_recurring</th><th>Valor</th><th>Vencimento</th><th>Status</th>";
echo "</tr>";

$recurringCount = 0;
$totalCount = 0;

foreach ($accounts as $account) {
    $totalCount++;
    if ($account['is_recurring'] == 1) {
        $recurringCount++;
    }
    
    $bgColor = $account['is_recurring'] == 1 ? '#e8f5e8' : '#fff';
    $icon = $account['is_recurring'] == 1 ? '🔄' : '⚪';
    
    echo "<tr style='background: {$bgColor};'>";
    echo "<td>{$account['id']}</td>";
    echo "<td>{$icon} {$account['description']}</td>";
    echo "<td>{$account['type']}</td>";
    echo "<td style='text-align: center; font-weight: bold;'>{$account['is_recurring']}</td>";
    echo "<td>R$ " . number_format($account['amount'], 2, ',', '.') . "</td>";
    echo "<td>{$account['due_date']}</td>";
    echo "<td>{$account['status']}</td>";
    echo "</tr>";
}

echo "</table>";

echo "<br><h3>📊 Resumo:</h3>";
echo "<p><strong>Total de contas:</strong> {$totalCount}</p>";
echo "<p><strong>Contas recorrentes:</strong> {$recurringCount}</p>";
echo "<p><strong>Contas não recorrentes:</strong> " . ($totalCount - $recurringCount) . "</p>";

// Verificar se há contas que deveriam ser recorrentes
echo "<br><h3>🤔 Contas que podem ser recorrentes (baseado no nome):</h3>";
$possibleRecurring = [
    'Contabilidade', 'AMX', 'Consórcio', 'Seguro', 'Aluguel', 'Condomínio', 
    'Internet', 'Telefone', 'Energia', 'Água', 'Gás', 'Plano', 'Mensalidade'
];

foreach ($accounts as $account) {
    if ($account['is_recurring'] == 0) {
        foreach ($possibleRecurring as $keyword) {
            if (stripos($account['description'], $keyword) !== false) {
                echo "<p>⚠️ <strong>{$account['description']}</strong> (ID: {$account['id']}) - pode ser recorrente</p>";
                break;
            }
        }
    }
}

echo "<br><h3>🔧 Teste do método getWithFilters:</h3>";
$accountModel = new Account($pdo);
$testAccounts = $accountModel->getWithFilters(1, ['type' => 'despesa']);

echo "<p>Contas retornadas pelo getWithFilters:</p>";
foreach (array_slice($testAccounts, 0, 5) as $account) {
    $hasRecurring = isset($account['is_recurring']) ? '✅' : '❌';
    $recurringValue = isset($account['is_recurring']) ? $account['is_recurring'] : 'N/A';
    echo "<p>{$hasRecurring} {$account['description']} - is_recurring: {$recurringValue}</p>";
}
?>