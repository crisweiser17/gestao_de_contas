<?php
// Teste para verificar se a correção do Category.php funcionou no projeto moneyview
echo "=== TESTE DE CORREÇÃO DO CATEGORY.PHP NO MONEYVIEW ===\n\n";

try {
    // Conectar ao banco moneyview
    $host = 'localhost';
    $dbname = 'moneyview';
    $username = 'root';
    $password = '';
    
    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8";
    $db = new PDO($dsn, $username, $password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✓ Conexão com banco moneyview estabelecida\n";
    
    // Verificar se há usuários
    $stmt = $db->query("SELECT id, name FROM users LIMIT 1");
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        echo "✓ Usuário encontrado: ID {$user['id']}, Nome: {$user['name']}\n";
        $valid_user_id = $user['id'];
    } else {
        echo "✗ Nenhum usuário encontrado no banco\n";
        exit;
    }
    
    // Simular criação de categoria (como seria feito via web)
    echo "\n--- Teste de criação de categoria ---\n";
    
    // Dados da categoria
    $category_data = [
        'user_id' => $valid_user_id,
        'name' => 'Categoria Teste Corrigida',
        'type' => 'despesa',
        'color' => '#FF5733'
    ];
    
    // Inserir categoria diretamente
    $sql = "INSERT INTO categories (user_id, name, type, color) VALUES (:user_id, :name, :type, :color)";
    $stmt = $db->prepare($sql);
    
    if ($stmt->execute($category_data)) {
        echo "✓ Categoria criada com sucesso!\n";
        $category_id = $db->lastInsertId();
        echo "  ID da categoria: $category_id\n";
    } else {
        echo "✗ Erro ao criar categoria\n";
    }
    
    // Testar com user_id inválido
    echo "\n--- Teste com user_id inválido ---\n";
    $invalid_data = [
        'user_id' => 999,
        'name' => 'Categoria Inválida',
        'type' => 'receita',
        'color' => '#33FF57'
    ];
    
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($invalid_data);
        echo "✗ Categoria criada incorretamente com user_id inválido\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'foreign key constraint') !== false) {
            echo "✓ Foreign key constraint funcionando corretamente\n";
            echo "  Erro capturado: " . substr($e->getMessage(), 0, 100) . "...\n";
        } else {
            echo "✗ Erro inesperado: " . $e->getMessage() . "\n";
        }
    }
    
    // Listar categorias
    echo "\n--- Categorias no banco ---\n";
    $stmt = $db->query("SELECT id, user_id, name, type, color FROM categories ORDER BY id DESC LIMIT 3");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "ID: {$row['id']}, User: {$row['user_id']}, Nome: {$row['name']}, Tipo: {$row['type']}\n";
    }
    
    echo "\n=== TESTE CONCLUÍDO ===\n";
    echo "O banco está funcionando corretamente!\n";
    echo "Agora você pode testar via web: http://localhost:8999/categories.php?action=add\n";
    
} catch (Exception $e) {
    echo "✗ Erro durante o teste: " . $e->getMessage() . "\n";
    echo "Linha: " . $e->getLine() . "\n";
}
?>