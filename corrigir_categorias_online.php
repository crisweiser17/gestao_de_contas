<?php
// Script de correção para garantir que todos os usuários tenham categorias
// Execute este arquivo no ambiente online via browser

require_once 'config/config.php';
require_once 'models/User.php';
require_once 'models/Category.php';

echo "<h1>Correção de Categorias - MoneyView</h1>\n";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .success { color: green; }
    .error { color: red; }
    .warning { color: orange; }
    .info { color: blue; }
    pre { background: #f5f5f5; padding: 10px; border-radius: 5px; }
    .step { background: #e8f4fd; padding: 15px; margin: 10px 0; border-radius: 5px; }
</style>\n";

$executar = isset($_GET['executar']) && $_GET['executar'] === 'sim';

if (!$executar) {
    echo "<div class='step'>\n";
    echo "<h2>⚠️ Confirmação Necessária</h2>\n";
    echo "<p>Este script irá:</p>\n";
    echo "<ol>\n";
    echo "<li>Verificar todos os usuários no sistema</li>\n";
    echo "<li>Identificar usuários sem categorias</li>\n";
    echo "<li>Criar categorias padrão para usuários que não possuem</li>\n";
    echo "</ol>\n";
    echo "<p><strong>Deseja continuar?</strong></p>\n";
    echo "<p><a href='?executar=sim' style='background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>✅ Sim, executar correção</a></p>\n";
    echo "</div>\n";
    exit;
}

echo "<div class='step'>\n";
echo "<h2>🔍 Iniciando Diagnóstico</h2>\n";

try {
    // 1. Verificar conexão
    if (!$pdo) {
        throw new Exception("Falha na conexão com banco de dados");
    }
    echo "<p class='success'>✅ Conexão com banco estabelecida</p>\n";
    
    // 2. Buscar todos os usuários
    $stmt = $pdo->query("SELECT id, name, email FROM users ORDER BY id");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<p class='info'>📊 Total de usuários encontrados: " . count($users) . "</p>\n";
    
    if (empty($users)) {
        echo "<p class='warning'>⚠️ Nenhum usuário encontrado no sistema</p>\n";
        exit;
    }
    
    // 3. Verificar categorias por usuário
    $usersWithoutCategories = [];
    $usersWithCategories = [];
    
    foreach ($users as $user) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM categories WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $count = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($count['total'] == 0) {
            $usersWithoutCategories[] = $user;
        } else {
            $usersWithCategories[] = array_merge($user, ['categories' => $count['total']]);
        }
    }
    
    echo "<p class='success'>✅ Usuários com categorias: " . count($usersWithCategories) . "</p>\n";
    echo "<p class='error'>❌ Usuários SEM categorias: " . count($usersWithoutCategories) . "</p>\n";
    
    echo "</div>\n";
    
    // Mostrar usuários com categorias
    if (!empty($usersWithCategories)) {
        echo "<div class='step'>\n";
        echo "<h3>👥 Usuários com Categorias</h3>\n";
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 100%;'>\n";
        echo "<tr style='background: #f0f0f0;'><th>ID</th><th>Nome</th><th>Email</th><th>Categorias</th></tr>\n";
        foreach ($usersWithCategories as $user) {
            echo "<tr>";
            echo "<td>{$user['id']}</td>";
            echo "<td>{$user['name']}</td>";
            echo "<td>{$user['email']}</td>";
            echo "<td class='success'>{$user['categories']}</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
        echo "</div>\n";
    }
    
    // Corrigir usuários sem categorias
    if (!empty($usersWithoutCategories)) {
        echo "<div class='step'>\n";
        echo "<h3>🔧 Corrigindo Usuários sem Categorias</h3>\n";
        
        $categoryModel = new Category($pdo);
        $sucessos = 0;
        $falhas = 0;
        
        foreach ($usersWithoutCategories as $user) {
            echo "<p class='info'>🔄 Processando: {$user['name']} (ID: {$user['id']})</p>\n";
            
            try {
                $result = $categoryModel->createDefaultCategories($user['id']);
                
                if ($result) {
                    // Verificar quantas categorias foram criadas
                    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM categories WHERE user_id = ?");
                    $stmt->execute([$user['id']]);
                    $count = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    echo "<p class='success'>✅ Sucesso! {$count['total']} categorias criadas para {$user['name']}</p>\n";
                    $sucessos++;
                } else {
                    echo "<p class='error'>❌ Falha ao criar categorias para {$user['name']}</p>\n";
                    $falhas++;
                }
                
            } catch (Exception $e) {
                echo "<p class='error'>❌ Erro para {$user['name']}: " . $e->getMessage() . "</p>\n";
                $falhas++;
            }
        }
        
        echo "<hr>\n";
        echo "<h4>📊 Resumo da Correção</h4>\n";
        echo "<p class='success'>✅ Sucessos: $sucessos</p>\n";
        echo "<p class='error'>❌ Falhas: $falhas</p>\n";
        echo "</div>\n";
        
    } else {
        echo "<div class='step'>\n";
        echo "<h3>✅ Todos os Usuários Possuem Categorias</h3>\n";
        echo "<p class='success'>Nenhuma correção necessária!</p>\n";
        echo "</div>\n";
    }
    
    // Verificação final
    echo "<div class='step'>\n";
    echo "<h3>🔍 Verificação Final</h3>\n";
    
    $stmt = $pdo->query("
        SELECT u.id, u.name, COUNT(c.id) as total_categories
        FROM users u 
        LEFT JOIN categories c ON u.id = c.user_id 
        GROUP BY u.id, u.name
        HAVING total_categories = 0
    ");
    $stillWithoutCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($stillWithoutCategories)) {
        echo "<p class='success'>🎉 SUCESSO! Todos os usuários agora possuem categorias!</p>\n";
    } else {
        echo "<p class='error'>⚠️ Ainda existem " . count($stillWithoutCategories) . " usuários sem categorias:</p>\n";
        foreach ($stillWithoutCategories as $user) {
            echo "<p class='error'>- {$user['name']} (ID: {$user['id']})</p>\n";
        }
    }
    echo "</div>\n";
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro crítico: " . $e->getMessage() . "</p>\n";
    echo "<pre>" . $e->getTraceAsString() . "</pre>\n";
}

echo "<hr>\n";
echo "<p><em>Correção executada em: " . date('Y-m-d H:i:s') . "</em></p>\n";
echo "<p><a href='accounts.php'>🔗 Ir para Contas</a> | <a href='categories.php'>🔗 Ir para Categorias</a></p>\n";
?>