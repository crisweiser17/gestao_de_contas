<?php
// Script para testar e corrigir problema de status
require_once 'config/config.php';
require_once 'models/Account.php';

echo "<h1>Teste e Correção de Status</h1>\n";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .success { color: green; }
    .error { color: red; }
    .warning { color: orange; }
    .info { color: blue; }
    pre { background: #f5f5f5; padding: 10px; border-radius: 5px; }
    table { border-collapse: collapse; width: 100%; margin: 10px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; }
</style>\n";

// Simular sessão de usuário
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
    echo "<p class='warning'>⚠️ Simulando usuário ID: 1</p>\n";
}

$userId = $_SESSION['user_id'];
$accountModel = new Account($pdo);

try {
    // 1. Verificar se há contas pendentes
    echo "<h2>1. Contas Pendentes do Usuário</h2>\n";
    $stmt = $pdo->prepare("SELECT id, description, amount, status, type FROM accounts WHERE user_id = ? AND status = 'pendente' ORDER BY id DESC LIMIT 5");
    $stmt->execute([$userId]);
    $pendingAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($pendingAccounts)) {
        echo "<p class='info'>ℹ️ Nenhuma conta pendente encontrada. Vou criar uma para teste.</p>\n";
        
        // Criar conta de teste
        $testData = [
            'description' => 'Teste de Status - ' . date('Y-m-d H:i:s'),
            'amount' => 100.00,
            'type' => 'despesa',
            'status' => 'pendente',
            'due_date' => date('Y-m-d'),
            'category_id' => 1, // Assumindo que existe categoria 1
            'user_id' => $userId
        ];
        
        $createStmt = $pdo->prepare("INSERT INTO accounts (description, amount, type, status, due_date, category_id, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $createResult = $createStmt->execute([
            $testData['description'],
            $testData['amount'],
            $testData['type'],
            $testData['status'],
            $testData['due_date'],
            $testData['category_id'],
            $testData['user_id']
        ]);
        
        if ($createResult) {
            $testAccountId = $pdo->lastInsertId();
            echo "<p class='success'>✅ Conta de teste criada com ID: $testAccountId</p>\n";
            
            // Buscar novamente
            $stmt->execute([$userId]);
            $pendingAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            echo "<p class='error'>❌ Erro ao criar conta de teste</p>\n";
        }
    }
    
    if (!empty($pendingAccounts)) {
        echo "<table>\n";
        echo "<tr><th>ID</th><th>Descrição</th><th>Valor</th><th>Status</th><th>Tipo</th><th>Ação</th></tr>\n";
        foreach ($pendingAccounts as $account) {
            echo "<tr>";
            echo "<td>{$account['id']}</td>";
            echo "<td>{$account['description']}</td>";
            echo "<td>R$ " . number_format($account['amount'], 2, ',', '.') . "</td>";
            echo "<td>{$account['status']}</td>";
            echo "<td>{$account['type']}</td>";
            echo "<td><a href='?test_id={$account['id']}&test_type={$account['type']}'>🧪 Testar</a></td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    }
    
    // 2. Executar teste se solicitado
    if (isset($_GET['test_id']) && isset($_GET['test_type'])) {
        $testId = $_GET['test_id'];
        $testType = $_GET['test_type'];
        $newStatus = $testType === 'despesa' ? 'paga' : 'recebida';
        
        echo "<h2>2. Executando Teste de Atualização</h2>\n";
        echo "<p class='info'>🧪 Testando conta ID: $testId | Tipo: $testType | Novo Status: $newStatus</p>\n";
        
        // Método 1: Usando o modelo Account
        echo "<h3>Método 1: Usando Account Model</h3>\n";
        $modelResult = $accountModel->updateStatus($testId, $newStatus, $userId);
        echo "<p>Resultado: " . ($modelResult ? '✅ SUCCESS' : '❌ FAILED') . "</p>\n";
        
        // Verificar resultado
        $checkStmt = $pdo->prepare("SELECT status FROM accounts WHERE id = ? AND user_id = ?");
        $checkStmt->execute([$testId, $userId]);
        $currentStatus = $checkStmt->fetchColumn();
        echo "<p>Status atual no banco: <strong>$currentStatus</strong></p>\n";
        
        if ($currentStatus === $newStatus) {
            echo "<p class='success'>🎉 SUCESSO! Status atualizado corretamente!</p>\n";
        } else {
            echo "<p class='error'>❌ PROBLEMA! Status não foi atualizado.</p>\n";
            
            // Método 2: Update direto
            echo "<h3>Método 2: Update Direto no Banco</h3>\n";
            $directStmt = $pdo->prepare("UPDATE accounts SET status = ? WHERE id = ? AND user_id = ?");
            $directResult = $directStmt->execute([$newStatus, $testId, $userId]);
            echo "<p>Update direto: " . ($directResult ? '✅ SUCCESS' : '❌ FAILED') . "</p>\n";
            
            // Verificar novamente
            $checkStmt->execute([$testId, $userId]);
            $finalStatus = $checkStmt->fetchColumn();
            echo "<p>Status final: <strong>$finalStatus</strong></p>\n";
            
            if ($finalStatus === $newStatus) {
                echo "<p class='success'>✅ Update direto funcionou!</p>\n";
                echo "<p class='warning'>⚠️ Problema pode estar no método updateStatus do modelo Account</p>\n";
            } else {
                echo "<p class='error'>❌ Nem o update direto funcionou! Problema mais sério.</p>\n";
                
                // Debug adicional
                echo "<h3>Debug Adicional</h3>\n";
                echo "<p>Verificando se a conta existe:</p>\n";
                $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE id = ? AND user_id = ?");
                $existsStmt->execute([$testId, $userId]);
                $exists = $existsStmt->fetchColumn();
                echo "<p>Conta existe: " . ($exists ? 'SIM' : 'NÃO') . "</p>\n";
                
                if ($exists) {
                    echo "<p>Verificando estrutura da coluna status:</p>\n";
                    $structStmt = $pdo->query("DESCRIBE accounts");
                    $columns = $structStmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($columns as $col) {
                        if ($col['Field'] === 'status') {
                            echo "<pre>" . print_r($col, true) . "</pre>\n";
                            break;
                        }
                    }
                }
            }
        }
    }
    
    // 3. Informações do sistema
    echo "<h2>3. Informações do Sistema</h2>\n";
    echo "<p>PHP Version: " . phpversion() . "</p>\n";
    echo "<p>PDO Driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "</p>\n";
    echo "<p>MySQL Version: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "</p>\n";
    echo "<p>Autocommit: " . ($pdo->query("SELECT @@autocommit")->fetchColumn() ? 'ON' : 'OFF') . "</p>\n";
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro: " . $e->getMessage() . "</p>\n";
    echo "<pre>" . $e->getTraceAsString() . "</pre>\n";
}

echo "<hr>\n";
echo "<p><a href='accounts.php'>🔗 Voltar para Contas</a> | <a href='?'>🔄 Recarregar</a></p>\n";
echo "<p><em>Teste executado em: " . date('Y-m-d H:i:s') . "</em></p>\n";
?>