<?php
// Script para atualizar o método createDefaultCategories com melhor tratamento de erros
// Execute este arquivo no ambiente online para aplicar a correção

require_once 'config/config.php';

echo "<h1>Correção do Método createDefaultCategories</h1>\n";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .success { color: green; }
    .error { color: red; }
    .warning { color: orange; }
    .info { color: blue; }
    pre { background: #f5f5f5; padding: 10px; border-radius: 5px; overflow-x: auto; }
    .step { background: #e8f4fd; padding: 15px; margin: 10px 0; border-radius: 5px; }
</style>\n";

$executar = isset($_GET['executar']) && $_GET['executar'] === 'sim';

if (!$executar) {
    echo "<div class='step'>\n";
    echo "<h2>⚠️ Atualização do Método createDefaultCategories</h2>\n";
    echo "<p>Este script irá criar uma versão melhorada do método createDefaultCategories que:</p>\n";
    echo "<ol>\n";
    echo "<li>Verifica se o usuário existe antes de criar categorias</li>\n";
    echo "<li>Verifica se a categoria já existe antes de criar</li>\n";
    echo "<li>Tem melhor tratamento de erros</li>\n";
    echo "<li>Retorna informações detalhadas sobre o processo</li>\n";
    echo "</ol>\n";
    echo "<p><strong>Deseja aplicar a correção?</strong></p>\n";
    echo "<p><a href='?executar=sim' style='background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>✅ Sim, aplicar correção</a></p>\n";
    echo "</div>\n";
    exit;
}

echo "<div class='step'>\n";
echo "<h2>🔧 Aplicando Correção</h2>\n";

// Função melhorada para criar categorias padrão
function createDefaultCategoriesImproved($pdo, $userId) {
    $result = [
        'success' => false,
        'created' => 0,
        'skipped' => 0,
        'errors' => [],
        'details' => []
    ];
    
    try {
        // 1. Verificar se usuário existe
        $stmt = $pdo->prepare('SELECT id, name FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $result['errors'][] = "Usuário com ID $userId não encontrado";
            return $result;
        }
        
        $result['details'][] = "Usuário encontrado: {$user['name']} (ID: $userId)";
        
        // 2. Verificar quantas categorias o usuário já tem
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE user_id = ?');
        $stmt->execute([$userId]);
        $existingCount = $stmt->fetchColumn();
        
        $result['details'][] = "Categorias existentes: $existingCount";
        
        // 3. Definir categorias padrão
        $defaultCategories = [
            ['name' => 'Receitas', 'type' => 'receita', 'color' => '#10B981'],
            ['name' => 'Alimentação', 'type' => 'despesa', 'color' => '#F59E0B'],
            ['name' => 'Transporte', 'type' => 'despesa', 'color' => '#3B82F6'],
            ['name' => 'Outros', 'type' => 'despesa', 'color' => '#6B7280']
        ];
        
        // 4. Criar cada categoria
        foreach ($defaultCategories as $category) {
            try {
                // Verificar se categoria já existe
                $stmt = $pdo->prepare('SELECT id FROM categories WHERE user_id = ? AND LOWER(name) = LOWER(?)');
                $stmt->execute([$userId, $category['name']]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    $result['skipped']++;
                    $result['details'][] = "Categoria '{$category['name']}' já existe (ID: {$existing['id']})";
                    continue;
                }
                
                // Criar categoria
                $stmt = $pdo->prepare('
                    INSERT INTO categories (name, user_id, type, color, created_at) 
                    VALUES (?, ?, ?, ?, NOW())
                ');
                
                $success = $stmt->execute([
                    $category['name'],
                    $userId,
                    $category['type'],
                    $category['color']
                ]);
                
                if ($success) {
                    $categoryId = $pdo->lastInsertId();
                    $result['created']++;
                    $result['details'][] = "Categoria '{$category['name']}' criada com sucesso (ID: $categoryId)";
                } else {
                    $result['errors'][] = "Falha ao criar categoria '{$category['name']}'";
                }
                
            } catch (Exception $e) {
                $result['errors'][] = "Erro ao criar categoria '{$category['name']}': " . $e->getMessage();
            }
        }
        
        // 5. Verificar resultado final
        if ($result['created'] > 0 || $result['skipped'] > 0) {
            $result['success'] = true;
        }
        
        $result['details'][] = "Resumo: {$result['created']} criadas, {$result['skipped']} já existiam, " . count($result['errors']) . " erros";
        
    } catch (Exception $e) {
        $result['errors'][] = "Erro geral: " . $e->getMessage();
    }
    
    return $result;
}

// Testar a função melhorada
try {
    if (!$pdo) {
        throw new Exception("Falha na conexão com banco de dados");
    }
    
    echo "<p class='success'>✅ Conexão com banco estabelecida</p>\n";
    
    // Buscar usuários para testar
    $stmt = $pdo->query("SELECT id, name, email FROM users ORDER BY id");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p class='info'>📊 Usuários encontrados: " . count($users) . "</p>\n";
    
    if (empty($users)) {
        echo "<p class='warning'>⚠️ Nenhum usuário encontrado para testar</p>\n";
    } else {
        foreach ($users as $user) {
            echo "<h3>👤 Processando: {$user['name']} (ID: {$user['id']})</h3>\n";
            
            $result = createDefaultCategoriesImproved($pdo, $user['id']);
            
            if ($result['success']) {
                echo "<p class='success'>✅ Sucesso!</p>\n";
            } else {
                echo "<p class='error'>❌ Falha no processamento</p>\n";
            }
            
            echo "<p><strong>Detalhes:</strong></p>\n";
            echo "<ul>\n";
            foreach ($result['details'] as $detail) {
                echo "<li class='info'>$detail</li>\n";
            }
            echo "</ul>\n";
            
            if (!empty($result['errors'])) {
                echo "<p><strong>Erros:</strong></p>\n";
                echo "<ul>\n";
                foreach ($result['errors'] as $error) {
                    echo "<li class='error'>$error</li>\n";
                }
                echo "</ul>\n";
            }
            
            echo "<hr>\n";
        }
    }
    
    // Verificação final
    echo "<h2>🔍 Verificação Final</h2>\n";
    
    $stmt = $pdo->query("
        SELECT u.id, u.name, COUNT(c.id) as total_categories
        FROM users u 
        LEFT JOIN categories c ON u.id = c.user_id 
        GROUP BY u.id, u.name
        ORDER BY u.id
    ");
    $finalCheck = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 100%;'>\n";
    echo "<tr style='background: #f0f0f0;'><th>ID</th><th>Nome</th><th>Total Categorias</th><th>Status</th></tr>\n";
    
    $allGood = true;
    foreach ($finalCheck as $user) {
        $status = $user['total_categories'] > 0 ? '✅ OK' : '❌ Sem categorias';
        $class = $user['total_categories'] > 0 ? 'success' : 'error';
        
        if ($user['total_categories'] == 0) {
            $allGood = false;
        }
        
        echo "<tr>";
        echo "<td>{$user['id']}</td>";
        echo "<td>{$user['name']}</td>";
        echo "<td>{$user['total_categories']}</td>";
        echo "<td class='$class'>$status</td>";
        echo "</tr>\n";
    }
    echo "</table>\n";
    
    if ($allGood) {
        echo "<p class='success'>🎉 PERFEITO! Todos os usuários agora possuem categorias!</p>\n";
    } else {
        echo "<p class='warning'>⚠️ Ainda existem usuários sem categorias. Pode ser necessário investigar mais.</p>\n";
    }
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro crítico: " . $e->getMessage() . "</p>\n";
    echo "<pre>" . $e->getTraceAsString() . "</pre>\n";
}

echo "</div>\n";

echo "<hr>\n";
echo "<p><em>Correção executada em: " . date('Y-m-d H:i:s') . "</em></p>\n";
echo "<p><a href='accounts.php'>🔗 Testar Contas</a> | <a href='categories.php'>🔗 Ver Categorias</a></p>\n";
?>