<?php
// Script de diagnóstico para ambiente online
// Para usar: acesse via browser no hosting

require_once 'config/config.php';
require_once 'models/User.php';
require_once 'models/Category.php';

echo "<h1>Diagnóstico MoneyView - Ambiente Online</h1>\n";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .success { color: green; }
    .error { color: red; }
    .warning { color: orange; }
    .info { color: blue; }
    pre { background: #f5f5f5; padding: 10px; border-radius: 5px; }
</style>\n";

// 1. Testar conexão com banco
echo "<h2>1. Teste de Conexão com Banco de Dados</h2>\n";
try {
    if ($pdo) {
        echo "<p class='success'>✅ Conexão com banco estabelecida</p>\n";
        
        // Verificar se tabelas existem
        $tables = ['users', 'categories', 'accounts'];
        foreach ($tables as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() > 0) {
                echo "<p class='success'>✅ Tabela '$table' existe</p>\n";
            } else {
                echo "<p class='error'>❌ Tabela '$table' não encontrada</p>\n";
            }
        }
    } else {
        echo "<p class='error'>❌ Falha na conexão com banco</p>\n";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro: " . $e->getMessage() . "</p>\n";
}

// 2. Verificar usuários existentes
echo "<h2>2. Usuários no Sistema</h2>\n";
try {
    $stmt = $pdo->query("SELECT id, name, email, created_at FROM users ORDER BY created_at DESC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($users)) {
        echo "<p class='warning'>⚠️ Nenhum usuário encontrado</p>\n";
    } else {
        echo "<p class='info'>📊 Total de usuários: " . count($users) . "</p>\n";
        echo "<table border='1' cellpadding='5'>\n";
        echo "<tr><th>ID</th><th>Nome</th><th>Email</th><th>Criado em</th></tr>\n";
        foreach ($users as $user) {
            echo "<tr>";
            echo "<td>{$user['id']}</td>";
            echo "<td>{$user['name']}</td>";
            echo "<td>{$user['email']}</td>";
            echo "<td>{$user['created_at']}</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro ao buscar usuários: " . $e->getMessage() . "</p>\n";
}

// 3. Verificar categorias por usuário
echo "<h2>3. Categorias por Usuário</h2>\n";
try {
    $stmt = $pdo->query("
        SELECT u.id as user_id, u.name as user_name, 
               COUNT(c.id) as total_categories,
               SUM(CASE WHEN c.type = 'receita' THEN 1 ELSE 0 END) as receitas,
               SUM(CASE WHEN c.type = 'despesa' THEN 1 ELSE 0 END) as despesas
        FROM users u 
        LEFT JOIN categories c ON u.id = c.user_id 
        GROUP BY u.id, u.name
        ORDER BY u.id
    ");
    $userCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='5'>\n";
    echo "<tr><th>User ID</th><th>Nome</th><th>Total Categorias</th><th>Receitas</th><th>Despesas</th></tr>\n";
    foreach ($userCategories as $uc) {
        $class = $uc['total_categories'] == 0 ? 'error' : 'success';
        echo "<tr class='$class'>";
        echo "<td>{$uc['user_id']}</td>";
        echo "<td>{$uc['user_name']}</td>";
        echo "<td>{$uc['total_categories']}</td>";
        echo "<td>{$uc['receitas']}</td>";
        echo "<td>{$uc['despesas']}</td>";
        echo "</tr>\n";
    }
    echo "</table>\n";
    
    // Mostrar usuários sem categorias
    $usersWithoutCategories = array_filter($userCategories, function($uc) {
        return $uc['total_categories'] == 0;
    });
    
    if (!empty($usersWithoutCategories)) {
        echo "<h3 class='error'>⚠️ Usuários sem categorias:</h3>\n";
        foreach ($usersWithoutCategories as $uc) {
            echo "<p class='error'>- {$uc['user_name']} (ID: {$uc['user_id']})</p>\n";
        }
    }
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro ao verificar categorias: " . $e->getMessage() . "</p>\n";
}

// 4. Testar criação de categorias padrão
echo "<h2>4. Teste de Criação de Categorias Padrão</h2>\n";
try {
    $categoryModel = new Category($pdo);
    
    // Verificar se método existe
    if (method_exists($categoryModel, 'createDefaultCategories')) {
        echo "<p class='success'>✅ Método createDefaultCategories existe</p>\n";
        
        // Encontrar usuário sem categorias para testar
        $stmt = $pdo->query("
            SELECT u.id 
            FROM users u 
            LEFT JOIN categories c ON u.id = c.user_id 
            WHERE c.id IS NULL 
            LIMIT 1
        ");
        $userWithoutCategories = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($userWithoutCategories) {
            echo "<p class='info'>🧪 Testando criação para usuário ID: {$userWithoutCategories['id']}</p>\n";
            
            $result = $categoryModel->createDefaultCategories($userWithoutCategories['id']);
            
            if ($result) {
                echo "<p class='success'>✅ Categorias padrão criadas com sucesso</p>\n";
                
                // Verificar quantas foram criadas
                $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM categories WHERE user_id = ?");
                $stmt->execute([$userWithoutCategories['id']]);
                $count = $stmt->fetch(PDO::FETCH_ASSOC);
                echo "<p class='info'>📊 Total de categorias criadas: {$count['total']}</p>\n";
                
            } else {
                echo "<p class='error'>❌ Falha ao criar categorias padrão</p>\n";
            }
        } else {
            echo "<p class='info'>ℹ️ Todos os usuários já possuem categorias</p>\n";
        }
        
    } else {
        echo "<p class='error'>❌ Método createDefaultCategories não encontrado</p>\n";
    }
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro no teste de criação: " . $e->getMessage() . "</p>\n";
}

// 5. Verificar estrutura da tabela categories
echo "<h2>5. Estrutura da Tabela Categories</h2>\n";
try {
    $stmt = $pdo->query("DESCRIBE categories");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='5'>\n";
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
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro ao verificar estrutura: " . $e->getMessage() . "</p>\n";
}

echo "<hr>\n";
echo "<h2>6. Ações Recomendadas</h2>\n";
echo "<p><strong>Se encontrou usuários sem categorias:</strong></p>\n";
echo "<ol>\n";
echo "<li><a href='?action=fix_categories'>🔧 Corrigir categorias para todos os usuários</a></li>\n";
echo "<li><a href='?action=create_test_user'>👤 Criar usuário de teste</a></li>\n";
echo "</ol>\n";

// Ações de correção
if (isset($_GET['action'])) {
    echo "<hr>\n";
    echo "<h2>Executando Ação: {$_GET['action']}</h2>\n";
    
    if ($_GET['action'] === 'fix_categories') {
        try {
            $categoryModel = new Category($pdo);
            
            // Buscar usuários sem categorias
            $stmt = $pdo->query("
                SELECT u.id, u.name 
                FROM users u 
                LEFT JOIN categories c ON u.id = c.user_id 
                WHERE c.id IS NULL
            ");
            $usersWithoutCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($usersWithoutCategories)) {
                echo "<p class='info'>ℹ️ Todos os usuários já possuem categorias</p>\n";
            } else {
                foreach ($usersWithoutCategories as $user) {
                    echo "<p class='info'>🔧 Criando categorias para: {$user['name']} (ID: {$user['id']})</p>\n";
                    
                    $result = $categoryModel->createDefaultCategories($user['id']);
                    if ($result) {
                        echo "<p class='success'>✅ Categorias criadas para {$user['name']}</p>\n";
                    } else {
                        echo "<p class='error'>❌ Falha ao criar categorias para {$user['name']}</p>\n";
                    }
                }
            }
            
        } catch (Exception $e) {
            echo "<p class='error'>❌ Erro na correção: " . $e->getMessage() . "</p>\n";
        }
    }
    
    if ($_GET['action'] === 'create_test_user') {
        try {
            $userModel = new User($pdo);
            
            $testEmail = 'teste_' . time() . '@exemplo.com';
            $testName = 'Usuário Teste ' . date('Y-m-d H:i:s');
            $testPassword = 'teste123';
            
            $userId = $userModel->create($testName, $testEmail, $testPassword);
            
            if ($userId) {
                echo "<p class='success'>✅ Usuário de teste criado:</p>\n";
                echo "<ul>\n";
                echo "<li><strong>ID:</strong> $userId</li>\n";
                echo "<li><strong>Nome:</strong> $testName</li>\n";
                echo "<li><strong>Email:</strong> $testEmail</li>\n";
                echo "<li><strong>Senha:</strong> $testPassword</li>\n";
                echo "</ul>\n";
                
                // Verificar se categorias foram criadas
                $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM categories WHERE user_id = ?");
                $stmt->execute([$userId]);
                $count = $stmt->fetch(PDO::FETCH_ASSOC);
                echo "<p class='info'>📊 Categorias criadas automaticamente: {$count['total']}</p>\n";
                
            } else {
                echo "<p class='error'>❌ Falha ao criar usuário de teste</p>\n";
            }
            
        } catch (Exception $e) {
            echo "<p class='error'>❌ Erro ao criar usuário: " . $e->getMessage() . "</p>\n";
        }
    }
}

echo "<hr>\n";
echo "<p><em>Diagnóstico executado em: " . date('Y-m-d H:i:s') . "</em></p>\n";
?>