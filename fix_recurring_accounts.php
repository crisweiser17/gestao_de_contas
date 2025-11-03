<?php
require_once 'config/config.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Conexão estabelecida<br><br>";
} catch(PDOException $e) {
    die("❌ Erro: " . $e->getMessage());
}

// Palavras-chave que indicam contas recorrentes
$recurringKeywords = [
    'Contabilidade',
    'AMX',
    'Consórcio', 
    'Consórcios',
    'Condomínio',
    'Aluguel',
    'Seguro',
    'Internet',
    'Telefone',
    'Energia',
    'Água',
    'Gás',
    'Plano',
    'Mensalidade',
    'Imposto',
    'IPTU',
    'IPVA',
    'Financiamento',
    'Prestação'
];

echo "<h2>🔧 Corrigindo contas recorrentes</h2>";

$updatedCount = 0;
$alreadyRecurring = 0;

foreach ($recurringKeywords as $keyword) {
    // Buscar contas que contêm a palavra-chave mas não estão marcadas como recorrentes
    $stmt = $pdo->prepare("
        SELECT id, description, is_recurring 
        FROM accounts 
        WHERE description LIKE ? 
        AND user_id = 1
    ");
    $stmt->execute(["%{$keyword}%"]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($accounts as $account) {
        if ($account['is_recurring'] == 0) {
            // Marcar como recorrente
            $updateStmt = $pdo->prepare("UPDATE accounts SET is_recurring = 1 WHERE id = ?");
            $updateStmt->execute([$account['id']]);
            
            echo "<p>✅ <strong>{$account['description']}</strong> (ID: {$account['id']}) marcada como recorrente</p>";
            $updatedCount++;
        } else {
            echo "<p>ℹ️ <strong>{$account['description']}</strong> (ID: {$account['id']}) já era recorrente</p>";
            $alreadyRecurring++;
        }
    }
}

echo "<br><h3>📊 Resumo das alterações:</h3>";
echo "<p><strong>Contas atualizadas:</strong> {$updatedCount}</p>";
echo "<p><strong>Contas já recorrentes:</strong> {$alreadyRecurring}</p>";

// Verificar o resultado final
echo "<br><h3>🔍 Status final das contas:</h3>";
$stmt = $pdo->query("
    SELECT description, is_recurring 
    FROM accounts 
    WHERE user_id = 1 
    AND is_recurring = 1 
    ORDER BY description
");
$recurringAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p><strong>Total de contas recorrentes:</strong> " . count($recurringAccounts) . "</p>";
foreach ($recurringAccounts as $account) {
    echo "<p>🔄 {$account['description']}</p>";
}

echo "<br><p><strong>🎯 Agora volte para a página de contas para ver os ícones!</strong></p>";
echo "<p><a href='accounts.php' style='background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Ver Contas</a></p>";
?>