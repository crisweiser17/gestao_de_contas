<?php
// Script para debugar problema de atualização de status
require_once 'config/config.php';
require_once 'models/Account.php';

echo "<h1>Debug - Atualização de Status</h1>\n";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .success { color: green; }
    .error { color: red; }
    .warning { color: orange; }
    .info { color: blue; }
    pre { background: #f5f5f5; padding: 10px; border-radius: 5px; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; }
</style>\n";

// Simular sessão de usuário
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1; // Usar usuário padrão para teste
    echo "<p class='warning'>⚠️ Simulando usuário ID: 1</p>\n";
}

$userId = $_SESSION['user_id'];
$accountModel = new Account($pdo);

try {
    // 1. Verificar estrutura da tabela accounts
    echo "<h2>1. Estrutura da Tabela Accounts</h2>\n";
    $stmt = $pdo->query("DESCRIBE accounts");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table>\n";
    echo "<tr><th>Campo</th><th>Tipo</th><th>Null</th><th>Key</th><th>Default</th></tr>\n";
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td>{$col['Field']}</td>";
        echo "<td>{$col['Type']}</td>";
        echo "<td>{$col['Null']}</td>";
        echo "<td>{$col['Key']}</td>";
        echo "<td>{$col['Default']}</td>";
        echo "</tr>\n";
    }
    echo "</table>\n";
    
    // 2. Buscar contas do usuário
    echo "<h2>2. Contas do Usuário (ID: $userId)</h2>\n";
    $stmt = $pdo->prepare("SELECT id, description, amount, status, type, due_date FROM accounts WHERE user_id = ? ORDER BY id DESC LIMIT 10");
    $stmt->execute([$userId]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($accounts)) {
        echo "<p class='warning'>⚠️ Nenhuma conta encontrada para o usuário</p>\n";
    } else {
        echo "<table>\n";
        echo "<tr><th>ID</th><th>Descrição</th><th>Valor</th><th>Status</th><th>Tipo</th><th>Vencimento</th><th>Ações</th></tr>\n";
        foreach ($accounts as $account) {
            $statusClass = $account['status'] === 'pendente' ? 'warning' : 'success';
            echo "<tr>";
            echo "<td>{$account['id']}</td>";
            echo "<td>{$account['description']}</td>";
            echo "<td>R$ " . number_format($account['amount'], 2, ',', '.') . "</td>";
            echo "<td class='$statusClass'>{$account['status']}</td>";
            echo "<td>{$account['type']}</td>";
            echo "<td>{$account['due_date']}</td>";
            echo "<td>";
            if ($account['status'] === 'pendente') {
                $newStatus = $account['type'] === 'despesa' ? 'paga' : 'recebida';
                echo "<a href='?test_update={$account['id']}&new_status=$newStatus'>🔄 Testar Update</a>";
            }
            echo "</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    }
    
    // 3. Testar atualização se solicitado
    if (isset($_GET['test_update']) && isset($_GET['new_status'])) {
        $testAccountId = $_GET['test_update'];
        $newStatus = $_GET['new_status'];
        
        echo "<h2>3. Teste de Atualização</h2>\n";
        echo "<p class='info'>🧪 Testando atualização da conta ID: $testAccountId para status: $newStatus</p>\n";
        
        // Buscar conta antes da atualização
        $beforeStmt = $pdo->prepare("SELECT * FROM accounts WHERE id = ? AND user_id = ?");
        $beforeStmt->execute([$testAccountId, $userId]);
        $beforeAccount = $beforeStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$beforeAccount) {
            echo "<p class='error'>❌ Conta não encontrada!</p>\n";
        } else {
            echo "<p><strong>Status ANTES:</strong> {$beforeAccount['status']}</p>\n";
            
            // Executar atualização
            $updateResult = $accountModel->updateStatus($testAccountId, $newStatus, $userId);
            echo "<p><strong>Resultado do Update:</strong> " . ($updateResult ? '✅ SUCCESS' : '❌ FAILED') . "</p>\n";
            
            if ($updateResult) {
                // Verificar se realmente atualizou
                $afterStmt = $pdo->prepare("SELECT * FROM accounts WHERE id = ? AND user_id = ?");
                $afterStmt->execute([$testAccountId, $userId]);
                $afterAccount = $afterStmt->fetch(PDO::FETCH_ASSOC);
                
                echo "<p><strong>Status DEPOIS:</strong> {$afterAccount['status']}</p>\n";
                
                if ($afterAccount['status'] === $newStatus) {
                    echo "<p class='success'>🎉 SUCESSO! Status atualizado corretamente!</p>\n";
                } else {
                    echo "<p class='error'>❌ PROBLEMA! Status não foi atualizado no banco!</p>\n";
                    
                    // Debug adicional - tentar update direto
                    echo "<h3>Debug Adicional</h3>\n";
                    $directStmt = $pdo->prepare("UPDATE accounts SET status = ? WHERE id = ? AND user_id = ?");
                    $directResult = $directStmt->execute([$newStatus, $testAccountId, $userId]);
                    echo "<p>Update direto: " . ($directResult ? 'SUCCESS' : 'FAILED') . "</p>\n";
                    
                    if ($directResult) {
                        $finalStmt = $pdo->prepare("SELECT status FROM accounts WHERE id = ? AND user_id = ?");
                        $finalStmt->execute([$testAccountId, $userId]);
                        $finalStatus = $finalStmt->fetchColumn();
                        echo "<p>Status final após update direto: $finalStatus</p>\n";
                    }
                }
            }
        }
    }
    
    // 4. Verificar se há transações pendentes
    echo "<h2>4. Verificação de Transações</h2>\n";
    $stmt = $pdo->query("SELECT @@autocommit as autocommit");
    $autocommit = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>Autocommit: " . ($autocommit['autocommit'] ? 'ON' : 'OFF') . "</p>\n";
    
    // 5. Testar conexão PDO
    echo "<h2>5. Teste de Conexão PDO</h2>\n";
    if ($pdo) {
        echo "<p class='success'>✅ Conexão PDO ativa</p>\n";
        echo "<p>Driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "</p>\n";
        echo "<p>Versão do servidor: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "</p>\n";
    } else {
        echo "<p class='error'>❌ Problema com conexão PDO</p>\n";
    }
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro: " . $e->getMessage() . "</p>\n";
    echo "<pre>" . $e->getTraceAsString() . "</pre>\n";
}

echo "<hr>\n";
echo "<p><a href='accounts.php'>🔗 Voltar para Contas</a></p>\n";
echo "<p><em>Debug executado em: " . date('Y-m-d H:i:s') . "</em></p>\n";
?>