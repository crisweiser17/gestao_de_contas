<?php
require_once 'config/config.php';
require_once 'models/User.php';
require_once 'models/Category.php';

// Criar um novo usuário de teste
$userModel = new User();
$categoryModel = new Category($pdo);

echo "<h2>Teste de Criação de Novo Usuário</h2>\n";

// Dados do novo usuário
$userData = [
    'name' => 'Usuário Teste',
    'email' => 'teste@exemplo.com',
    'password' => '123456'
];

echo "<p>Criando usuário: {$userData['name']} ({$userData['email']})</p>\n";

// Verificar se email já existe
if ($userModel->emailExists($userData['email'])) {
    echo "<p style='color: orange;'>Email já existe. Vou verificar as categorias do usuário existente.</p>\n";
    
    // Buscar o usuário existente
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$userData['email']]);
    $existingUser = $stmt->fetch();
    
    if ($existingUser) {
        $userId = $existingUser['id'];
        echo "<p>ID do usuário existente: {$userId}</p>\n";
    }
} else {
    // Criar novo usuário
    $result = $userModel->create($userData);
    
    if ($result['success']) {
        $userId = $result['user_id'];
        echo "<p style='color: green;'>✅ Usuário criado com sucesso! ID: {$userId}</p>\n";
    } else {
        echo "<p style='color: red;'>❌ Erro ao criar usuário: {$result['message']}</p>\n";
        exit;
    }
}

// Verificar categorias do usuário
echo "<h3>Verificando categorias do usuário ID: {$userId}</h3>\n";

try {
    $categories = $categoryModel->getByUserId($userId);
    
    if (empty($categories)) {
        echo "<p style='color: red;'>❌ PROBLEMA: Usuário não tem categorias!</p>\n";
        echo "<p>Criando categorias padrão manualmente...</p>\n";
        
        // Criar categorias padrão manualmente
        $categoryModel->createDefaultCategories($userId);
        
        // Verificar novamente
        $categories = $categoryModel->getByUserId($userId);
        
        if (!empty($categories)) {
            echo "<p style='color: green;'>✅ Categorias padrão criadas com sucesso!</p>\n";
        } else {
            echo "<p style='color: red;'>❌ Ainda não foi possível criar categorias!</p>\n";
        }
    } else {
        echo "<p style='color: green;'>✅ Usuário tem " . count($categories) . " categorias</p>\n";
    }
    
    // Listar categorias
    echo "<h4>Categorias encontradas:</h4>\n";
    echo "<ul>\n";
    foreach ($categories as $category) {
        echo "<li>{$category['name']} ({$category['type']}) - Cor: {$category['color']}</li>\n";
    }
    echo "</ul>\n";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro ao buscar categorias: " . $e->getMessage() . "</p>\n";
}

echo "<hr>\n";
echo "<p><a href='accounts.php'>Ir para Contas</a> | <a href='categories.php'>Ir para Categorias</a></p>\n";
?>