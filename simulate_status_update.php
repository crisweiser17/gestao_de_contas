<?php
// Script para simular exatamente o que acontece no accounts.php
require_once 'config/config.php';
require_once 'models/Account.php';
require_once 'models/Category.php';

echo "<h1>Simulação de Atualização de Status</h1>\n";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .success { color: green; }
    .error { color: red; }
    .warning { color: orange; }
    .info { color: blue; }
    pre { background: #f5f5f5; padding: 10px; border-radius: 5px; }
    .form-test { background: #f8f9fa; padding: 15px; border: 1px solid #ddd; margin: 10px 0; }
    button { background: #007bff; color: white; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; }
    button:hover { background: #0056b3; }
    select { padding: 5px; margin: 5px; }
</style>\n";

// Simular sessão de usuário
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
    echo "<p class='warning'>⚠️ Simulando usuário ID: 1</p>\n";
}

$userId = $_SESSION['user_id'];
$accountModel = new Account($pdo);
$categoryModel = new Category($pdo);

// Variáveis para simular o que acontece em accounts.php
$errors = [];
$success = '';

// Processar formulário se enviado
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    echo "<h2>📨 Dados Recebidos via POST</h2>\n";
    echo "<pre>" . print_r($_POST, true) . "</pre>\n";
    
    $action = $_POST['action'] ?? '';
    $accountId = $_POST['id'] ?? '';
    
    if ($action == 'update_status' && $accountId) {
        $status = $_POST['status'] ?? '';
        
        echo "<h2>🔄 Processando Atualização de Status</h2>\n";
        echo "<p class='info'>Action: $action</p>\n";
        echo "<p class='info'>Account ID: $accountId</p>\n";
        echo "<p class='info'>User ID: $userId</p>\n";
        echo "<p class='info'>New Status: $status</p>\n";
        
        // Replicar exatamente a validação de accounts.php
        if (empty($accountId)) {
            $errors[] = "ERRO: Account ID está vazio!";
        } elseif (empty($status)) {
            $errors[] = "ERRO: Status está vazio!";
        } elseif (!in_array($status, ['pendente', 'paga', 'recebida'])) {
            $errors[] = "ERRO: Status inválido: '{$status}'. Valores aceitos: pendente, paga, recebida";
        } else {
            echo "<p class='success'>✅ Validação inicial passou</p>\n";
            
            // Get current status before update
            echo "<p class='info'>🔍 Buscando conta atual...</p>\n";
            $currentAccount = $accountModel->getById($accountId, $userId);
            
            if (!$currentAccount) {
                $errors[] = "ERRO: Conta não encontrada (ID: {$accountId}, User: {$userId})";
                echo "<p class='error'>❌ Conta não encontrada!</p>\n";
            } else {
                echo "<p class='success'>✅ Conta encontrada</p>\n";
                echo "<pre>" . print_r($currentAccount, true) . "</pre>\n";
                
                $oldStatus = $currentAccount['status'];
                echo "<p class='info'>📊 Status atual: $oldStatus</p>\n";
                echo "<p class='info'>🎯 Novo status: $status</p>\n";
                
                echo "<p class='info'>🔄 Executando updateStatus...</p>\n";
                $updateResult = $accountModel->updateStatus($accountId, $status, $userId);
                echo "<p>Resultado do updateStatus: " . ($updateResult ? '✅ SUCCESS' : '❌ FAILED') . "</p>\n";
                
                if ($updateResult) {
                    echo "<p class='info'>🔍 Verificando se a atualização foi salva...</p>\n";
                    // Verify the update immediately
                    $verifyAccount = $accountModel->getById($accountId, $userId);
                    $actualStatus = $verifyAccount ? $verifyAccount['status'] : 'unknown';
                    echo "<p class='info'>📊 Status verificado: $actualStatus</p>\n";
                    
                    if ($actualStatus === $status) {
                        $success = "✅ Status atualizado com sucesso: {$oldStatus} → {$actualStatus}";
                        echo "<p class='success'>$success</p>\n";
                    } else {
                        $errors[] = "❌ PROBLEMA: Status não foi salvo! Esperado: {$status}, Atual: {$actualStatus}";
                        echo "<p class='error'>❌ PROBLEMA: Status não foi salvo!</p>\n";
                        echo "<p class='error'>Esperado: $status</p>\n";
                        echo "<p class='error'>Atual: $actualStatus</p>\n";
                    }
                } else {
                    $errors[] = '❌ Erro ao executar UPDATE no banco de dados';
                    echo "<p class='error'>❌ Erro ao executar UPDATE no banco de dados</p>\n";
                }
            }
        }
        
        // Mostrar erros se houver
        if (!empty($errors)) {
            echo "<h3>❌ Erros Encontrados:</h3>\n";
            foreach ($errors as $error) {
                echo "<p class='error'>$error</p>\n";
            }
        }
    }
}

try {
    // Buscar contas para teste
    echo "<h2>📋 Contas Disponíveis para Teste</h2>\n";
    
    $stmt = $pdo->prepare("SELECT id, description, amount, status, type FROM accounts WHERE user_id = ? ORDER BY id DESC LIMIT 5");
    $stmt->execute([$userId]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($accounts)) {
        echo "<p class='warning'>⚠️ Nenhuma conta encontrada. Criando conta de teste...</p>\n";
        
        // Criar conta de teste
        $stmt = $pdo->prepare("INSERT INTO accounts (description, amount, type, status, due_date, category_id, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $result = $stmt->execute([
            'Teste Simulação - ' . date('Y-m-d H:i:s'),
            75.00,
            'despesa',
            'pendente',
            date('Y-m-d'),
            1,
            $userId
        ]);
        
        if ($result) {
            $testId = $pdo->lastInsertId();
            echo "<p class='success'>✅ Conta de teste criada com ID: $testId</p>\n";
            
            // Buscar novamente
            $stmt->execute([$userId]);
            $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    
    if (!empty($accounts)) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>\n";
        echo "<tr><th>ID</th><th>Descrição</th><th>Valor</th><th>Status</th><th>Tipo</th><th>Teste</th></tr>\n";
        
        foreach ($accounts as $account) {
            $statusClass = $account['status'] === 'pendente' ? 'warning' : 'success';
            echo "<tr>";
            echo "<td>{$account['id']}</td>";
            echo "<td>{$account['description']}</td>";
            echo "<td>R$ " . number_format($account['amount'], 2, ',', '.') . "</td>";
            echo "<td class='$statusClass'>{$account['status']}</td>";
            echo "<td>{$account['type']}</td>";
            echo "<td>";
            
            // Formulário de teste (replicando exatamente o de accounts.php)
            if ($account['status'] === 'pendente') {
                $newStatus = $account['type'] === 'despesa' ? 'paga' : 'recebida';
                echo "<div class='form-test'>";
                echo "<form method='POST'>";
                echo "<input type='hidden' name='action' value='update_status'>";
                echo "<input type='hidden' name='id' value='{$account['id']}'>";
                echo "<select name='status'>";
                echo "<option value='pendente'" . ($account['status'] == 'pendente' ? ' selected' : '') . ">Pendente</option>";
                echo "<option value='$newStatus'" . ($account['status'] == $newStatus ? ' selected' : '') . ">$newStatus</option>";
                echo "</select>";
                echo "<button type='submit'>✓ Atualizar</button>";
                echo "</form>";
                echo "</div>";
            } else {
                echo "Status já atualizado";
            }
            
            echo "</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    }
    
    // Informações de debug
    echo "<h2>🔧 Informações de Debug</h2>\n";
    echo "<p>Sessão User ID: " . ($_SESSION['user_id'] ?? 'não definido') . "</p>\n";
    echo "<p>Método da requisição: " . $_SERVER['REQUEST_METHOD'] . "</p>\n";
    echo "<p>PHP Version: " . phpversion() . "</p>\n";
    echo "<p>PDO Driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "</p>\n";
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro: " . $e->getMessage() . "</p>\n";
    echo "<pre>" . $e->getTraceAsString() . "</pre>\n";
}

echo "<hr>\n";
echo "<p><a href='accounts.php'>🔗 Voltar para Contas</a> | <a href='?'>🔄 Recarregar</a></p>\n";
echo "<p><em>Simulação executada em: " . date('Y-m-d H:i:s') . "</em></p>\n";
?>