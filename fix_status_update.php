<?php
// Script para corrigir problema de atualização de status
require_once 'config/config.php';

echo "<h1>Correção do Problema de Status</h1>\n";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .success { color: green; }
    .error { color: red; }
    .warning { color: orange; }
    .info { color: blue; }
    pre { background: #f5f5f5; padding: 10px; border-radius: 5px; }
    .code { background: #f8f9fa; padding: 15px; border-left: 4px solid #007bff; margin: 10px 0; }
</style>\n";

// Simular sessão de usuário
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
    echo "<p class='warning'>⚠️ Simulando usuário ID: 1</p>\n";
}

$userId = $_SESSION['user_id'];

try {
    echo "<h2>1. Diagnóstico do Problema</h2>\n";
    
    // Verificar se a tabela accounts existe e sua estrutura
    $stmt = $pdo->query("SHOW TABLES LIKE 'accounts'");
    if ($stmt->rowCount() == 0) {
        echo "<p class='error'>❌ Tabela 'accounts' não existe!</p>\n";
        exit;
    }
    
    echo "<p class='success'>✅ Tabela 'accounts' existe</p>\n";
    
    // Verificar estrutura da coluna status
    $stmt = $pdo->query("DESCRIBE accounts");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $statusColumn = null;
    
    foreach ($columns as $col) {
        if ($col['Field'] === 'status') {
            $statusColumn = $col;
            break;
        }
    }
    
    if (!$statusColumn) {
        echo "<p class='error'>❌ Coluna 'status' não existe na tabela accounts!</p>\n";
        echo "<div class='code'>";
        echo "<strong>Solução:</strong> Execute o seguinte SQL:<br>";
        echo "<code>ALTER TABLE accounts ADD COLUMN status ENUM('pendente', 'paga', 'recebida') DEFAULT 'pendente';</code>";
        echo "</div>";
        exit;
    }
    
    echo "<p class='success'>✅ Coluna 'status' existe</p>\n";
    echo "<p class='info'>Tipo: {$statusColumn['Type']}</p>\n";
    
    // Verificar se há contas para testar
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE user_id = ?");
    $stmt->execute([$userId]);
    $accountCount = $stmt->fetchColumn();
    
    echo "<p class='info'>📊 Total de contas do usuário: $accountCount</p>\n";
    
    if ($accountCount == 0) {
        echo "<p class='warning'>⚠️ Criando conta de teste...</p>\n";
        
        // Criar conta de teste
        $stmt = $pdo->prepare("INSERT INTO accounts (description, amount, type, status, due_date, category_id, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $result = $stmt->execute([
            'Teste de Status - ' . date('Y-m-d H:i:s'),
            100.00,
            'despesa',
            'pendente',
            date('Y-m-d'),
            1, // Assumindo categoria 1 existe
            $userId
        ]);
        
        if ($result) {
            $testAccountId = $pdo->lastInsertId();
            echo "<p class='success'>✅ Conta de teste criada com ID: $testAccountId</p>\n";
        } else {
            echo "<p class='error'>❌ Erro ao criar conta de teste</p>\n";
            exit;
        }
    }
    
    echo "<h2>2. Teste de Atualização</h2>\n";
    
    // Buscar uma conta pendente para testar
    $stmt = $pdo->prepare("SELECT id, description, status, type FROM accounts WHERE user_id = ? AND status = 'pendente' LIMIT 1");
    $stmt->execute([$userId]);
    $testAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$testAccount) {
        echo "<p class='warning'>⚠️ Nenhuma conta pendente encontrada. Criando uma...</p>\n";
        
        $stmt = $pdo->prepare("INSERT INTO accounts (description, amount, type, status, due_date, category_id, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $result = $stmt->execute([
            'Teste Status Update - ' . date('Y-m-d H:i:s'),
            50.00,
            'despesa',
            'pendente',
            date('Y-m-d'),
            1,
            $userId
        ]);
        
        if ($result) {
            $testAccountId = $pdo->lastInsertId();
            $testAccount = [
                'id' => $testAccountId,
                'description' => 'Teste Status Update - ' . date('Y-m-d H:i:s'),
                'status' => 'pendente',
                'type' => 'despesa'
            ];
            echo "<p class='success'>✅ Conta de teste criada</p>\n";
        } else {
            echo "<p class='error'>❌ Erro ao criar conta de teste</p>\n";
            exit;
        }
    }
    
    $accountId = $testAccount['id'];
    $newStatus = $testAccount['type'] === 'despesa' ? 'paga' : 'recebida';
    
    echo "<p class='info'>🧪 Testando conta ID: $accountId</p>\n";
    echo "<p class='info'>📝 Descrição: {$testAccount['description']}</p>\n";
    echo "<p class='info'>📊 Status atual: {$testAccount['status']}</p>\n";
    echo "<p class='info'>🎯 Novo status: $newStatus</p>\n";
    
    // Teste 1: Update direto
    echo "<h3>Teste 1: Update Direto</h3>\n";
    $stmt = $pdo->prepare("UPDATE accounts SET status = ? WHERE id = ? AND user_id = ?");
    $result1 = $stmt->execute([$newStatus, $accountId, $userId]);
    
    echo "<p>Resultado: " . ($result1 ? '✅ SUCCESS' : '❌ FAILED') . "</p>\n";
    
    // Verificar se funcionou
    $stmt = $pdo->prepare("SELECT status FROM accounts WHERE id = ? AND user_id = ?");
    $stmt->execute([$accountId, $userId]);
    $currentStatus = $stmt->fetchColumn();
    
    echo "<p>Status no banco: <strong>$currentStatus</strong></p>\n";
    
    if ($currentStatus === $newStatus) {
        echo "<p class='success'>🎉 Update direto funcionou!</p>\n";
        
        // Reverter para testar o modelo
        $stmt = $pdo->prepare("UPDATE accounts SET status = 'pendente' WHERE id = ? AND user_id = ?");
        $stmt->execute([$accountId, $userId]);
        echo "<p class='info'>🔄 Status revertido para 'pendente' para testar o modelo</p>\n";
        
        // Teste 2: Usando o modelo Account
        echo "<h3>Teste 2: Usando Modelo Account</h3>\n";
        
        require_once 'models/Account.php';
        $accountModel = new Account();
        
        $result2 = $accountModel->updateStatus($accountId, $newStatus, $userId);
        echo "<p>Resultado do modelo: " . ($result2 ? '✅ SUCCESS' : '❌ FAILED') . "</p>\n";
        
        // Verificar novamente
        $stmt->execute([$accountId, $userId]);
        $finalStatus = $stmt->fetchColumn();
        echo "<p>Status final: <strong>$finalStatus</strong></p>\n";
        
        if ($finalStatus === $newStatus) {
            echo "<p class='success'>🎉 Modelo Account também funcionou!</p>\n";
            echo "<div class='code'>";
            echo "<strong>Conclusão:</strong> O problema não está no código de atualização.<br>";
            echo "Possíveis causas:<br>";
            echo "1. Problema na interface (JavaScript/formulário)<br>";
            echo "2. Problema de sessão/autenticação<br>";
            echo "3. Problema de cache do navegador<br>";
            echo "4. Problema de validação nos dados enviados";
            echo "</div>";
        } else {
            echo "<p class='error'>❌ Modelo Account falhou!</p>\n";
            echo "<div class='code'>";
            echo "<strong>Problema identificado:</strong> O método updateStatus do modelo Account não está funcionando.<br>";
            echo "Verifique a conexão PDO e os parâmetros do método.";
            echo "</div>";
        }
    } else {
        echo "<p class='error'>❌ Update direto falhou!</p>\n";
        echo "<div class='code'>";
        echo "<strong>Problema grave:</strong> Nem o update direto funcionou.<br>";
        echo "Possíveis causas:<br>";
        echo "1. Problema de permissões no banco de dados<br>";
        echo "2. Transação não commitada<br>";
        echo "3. Problema de conexão<br>";
        echo "4. Constraint ou trigger impedindo a atualização";
        echo "</div>";
        
        // Debug adicional
        echo "<h3>Debug Adicional</h3>\n";
        
        // Verificar se a conta realmente existe
        $stmt = $pdo->prepare("SELECT * FROM accounts WHERE id = ? AND user_id = ?");
        $stmt->execute([$accountId, $userId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($account) {
            echo "<p class='success'>✅ Conta existe no banco</p>\n";
            echo "<pre>" . print_r($account, true) . "</pre>\n";
        } else {
            echo "<p class='error'>❌ Conta não encontrada!</p>\n";
        }
        
        // Verificar autocommit
        $stmt = $pdo->query("SELECT @@autocommit");
        $autocommit = $stmt->fetchColumn();
        echo "<p>Autocommit: " . ($autocommit ? 'ON' : 'OFF') . "</p>\n";
        
        // Tentar commit manual
        if (!$autocommit) {
            $pdo->commit();
            echo "<p class='info'>🔄 Commit manual executado</p>\n";
        }
    }
    
    echo "<h2>3. Solução Recomendada</h2>\n";
    
    if ($currentStatus === $newStatus) {
        echo "<div class='code'>";
        echo "<strong>O problema parece estar na interface do usuário.</strong><br><br>";
        echo "Verifique:<br>";
        echo "1. Se o JavaScript está funcionando corretamente<br>";
        echo "2. Se os dados estão sendo enviados corretamente no formulário<br>";
        echo "3. Se há cache do navegador interferindo<br>";
        echo "4. Se a validação em accounts.php está rejeitando os dados<br><br>";
        echo "Teste: Abra o console do navegador (F12) e veja se há erros JavaScript.";
        echo "</div>";
    } else {
        echo "<div class='code'>";
        echo "<strong>O problema está no backend.</strong><br><br>";
        echo "Ações necessárias:<br>";
        echo "1. Verificar conexão com banco de dados<br>";
        echo "2. Verificar permissões do usuário MySQL<br>";
        echo "3. Verificar se há triggers ou constraints na tabela<br>";
        echo "4. Verificar logs de erro do MySQL";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro: " . $e->getMessage() . "</p>\n";
    echo "<pre>" . $e->getTraceAsString() . "</pre>\n";
}

echo "<hr>\n";
echo "<p><a href='accounts.php'>🔗 Voltar para Contas</a></p>\n";
echo "<p><em>Diagnóstico executado em: " . date('Y-m-d H:i:s') . "</em></p>\n";
?>