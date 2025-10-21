<?php
// Teste final para confirmar que o problema de status foi corrigido
require_once 'config/config.php';
require_once 'models/Account.php';

echo "<h1>✅ Teste Final - Correção de Status</h1>\n";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .success { color: green; font-weight: bold; }
    .error { color: red; font-weight: bold; }
    .warning { color: orange; }
    .info { color: blue; }
    .test-result { background: #f8f9fa; padding: 15px; border-left: 4px solid #28a745; margin: 10px 0; }
    .test-failed { border-left-color: #dc3545; }
    pre { background: #f5f5f5; padding: 10px; border-radius: 5px; }
</style>\n";

// Simular sessão de usuário
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
    echo "<p class='warning'>⚠️ Simulando usuário ID: 1</p>\n";
}

$userId = $_SESSION['user_id'];

try {
    echo "<h2>🔧 Teste da Correção Aplicada</h2>\n";
    
    // Testar com a nova implementação (passando PDO)
    echo "<h3>1. Testando Account Model com PDO Compartilhado</h3>\n";
    $accountModel = new Account($pdo);
    echo "<p class='success'>✅ Account model criado com PDO compartilhado</p>\n";
    
    // Criar conta de teste
    echo "<h3>2. Criando Conta de Teste</h3>\n";
    $testDescription = 'Teste Final Status - ' . date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("INSERT INTO accounts (description, amount, type, status, due_date, category_id, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $createResult = $stmt->execute([
        $testDescription,
        99.99,
        'despesa',
        'pendente',
        date('Y-m-d'),
        1,
        $userId
    ]);
    
    if (!$createResult) {
        echo "<p class='error'>❌ Falha ao criar conta de teste</p>\n";
        exit;
    }
    
    $testAccountId = $pdo->lastInsertId();
    echo "<p class='success'>✅ Conta de teste criada com ID: $testAccountId</p>\n";
    
    // Verificar status inicial
    echo "<h3>3. Verificando Status Inicial</h3>\n";
    $account = $accountModel->getById($testAccountId, $userId);
    if (!$account) {
        echo "<p class='error'>❌ Conta não encontrada após criação</p>\n";
        exit;
    }
    
    echo "<p class='info'>📊 Status inicial: {$account['status']}</p>\n";
    
    // Testar atualização de status
    echo "<h3>4. Testando Atualização de Status</h3>\n";
    $newStatus = 'paga';
    echo "<p class='info'>🎯 Atualizando para: $newStatus</p>\n";
    
    $updateResult = $accountModel->updateStatus($testAccountId, $newStatus, $userId);
    echo "<p>Resultado do updateStatus: " . ($updateResult ? '✅ SUCCESS' : '❌ FAILED') . "</p>\n";
    
    if ($updateResult) {
        // Verificar se realmente atualizou
        $updatedAccount = $accountModel->getById($testAccountId, $userId);
        $actualStatus = $updatedAccount['status'];
        echo "<p class='info'>📊 Status após update: $actualStatus</p>\n";
        
        if ($actualStatus === $newStatus) {
            echo "<div class='test-result'>";
            echo "<h4>🎉 TESTE PASSOU!</h4>";
            echo "<p>✅ Status foi atualizado corretamente de 'pendente' para '$newStatus'</p>";
            echo "<p>✅ A correção da conexão PDO funcionou</p>";
            echo "<p>✅ O problema de atualização de status foi resolvido</p>";
            echo "</div>";
            
            // Testar mais uma vez para ter certeza
            echo "<h3>5. Teste Adicional - Reverter Status</h3>\n";
            $revertResult = $accountModel->updateStatus($testAccountId, 'pendente', $userId);
            if ($revertResult) {
                $revertedAccount = $accountModel->getById($testAccountId, $userId);
                if ($revertedAccount['status'] === 'pendente') {
                    echo "<p class='success'>✅ Teste de reversão também passou!</p>\n";
                } else {
                    echo "<p class='error'>❌ Teste de reversão falhou</p>\n";
                }
            }
            
        } else {
            echo "<div class='test-result test-failed'>";
            echo "<h4>❌ TESTE FALHOU!</h4>";
            echo "<p>❌ Status não foi atualizado corretamente</p>";
            echo "<p>Esperado: $newStatus</p>";
            echo "<p>Atual: $actualStatus</p>";
            echo "<p>O problema ainda persiste</p>";
            echo "</div>";
        }
    } else {
        echo "<div class='test-result test-failed'>";
        echo "<h4>❌ TESTE FALHOU!</h4>";
        echo "<p>❌ Método updateStatus retornou false</p>";
        echo "<p>Verifique a implementação do método</p>";
        echo "</div>";
    }
    
    // Limpeza - remover conta de teste
    echo "<h3>6. Limpeza</h3>\n";
    $deleteStmt = $pdo->prepare("DELETE FROM accounts WHERE id = ? AND user_id = ?");
    $deleteResult = $deleteStmt->execute([$testAccountId, $userId]);
    echo "<p class='info'>🗑️ Conta de teste removida: " . ($deleteResult ? 'SIM' : 'NÃO') . "</p>\n";
    
    echo "<h2>📋 Resumo da Correção</h2>\n";
    echo "<div style='background: #e7f3ff; padding: 15px; border-radius: 5px;'>";
    echo "<h4>🔧 Problema Identificado:</h4>";
    echo "<p>A classe Account estava criando sua própria conexão PDO, causando problemas de transação e inconsistência com a conexão global.</p>";
    
    echo "<h4>✅ Correção Aplicada:</h4>";
    echo "<ul>";
    echo "<li>Modificado o construtor da classe Account para aceitar uma conexão PDO existente</li>";
    echo "<li>Atualizado accounts.php, index.php, reports.php, cash-flow.php e recurring.php para usar a conexão PDO compartilhada</li>";
    echo "<li>Garantido que todas as operações usem a mesma conexão de banco</li>";
    echo "</ul>";
    
    echo "<h4>🎯 Resultado:</h4>";
    echo "<p>O problema de atualização de status deve estar resolvido. Teste agora em accounts.php!</p>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro durante o teste: " . $e->getMessage() . "</p>\n";
    echo "<pre>" . $e->getTraceAsString() . "</pre>\n";
}

echo "<hr>\n";
echo "<p><a href='accounts.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🔗 Testar em Accounts.php</a></p>\n";
echo "<p><em>Teste executado em: " . date('Y-m-d H:i:s') . "</em></p>\n";
?>