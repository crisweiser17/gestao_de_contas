<?php
require_once 'config/config.php';
require_once 'models/User.php';
require_once 'models/Category.php';

// Simular login do novo usuário
$_SESSION['user_id'] = 2;  // ID do novo usuário
$_SESSION['user_name'] = 'Usuário Teste';
$_SESSION['user_email'] = 'teste@exemplo.com';

echo "<h2>Teste de Login - Novo Usuário</h2>\n";
echo "<p>Usuário logado: {$_SESSION['user_name']} (ID: {$_SESSION['user_id']})</p>\n";

// Testar carregamento de categorias
$categoryModel = new Category($pdo);
$userId = $_SESSION['user_id'];

echo "<h3>Testando carregamento de categorias</h3>\n";

try {
    $categories = $categoryModel->getByUserId($userId);
    
    if (empty($categories)) {
        echo "<p style='color: red;'>❌ PROBLEMA: Nenhuma categoria encontrada!</p>\n";
    } else {
        echo "<p style='color: green;'>✅ Encontradas " . count($categories) . " categorias</p>\n";
        
        echo "<h4>Categorias por tipo:</h4>\n";
        
        $receitas = array_filter($categories, function($cat) { return $cat['type'] === 'receita'; });
        $despesas = array_filter($categories, function($cat) { return $cat['type'] === 'despesa'; });
        
        echo "<h5>Receitas (" . count($receitas) . "):</h5>\n";
        echo "<ul>\n";
        foreach ($receitas as $cat) {
            echo "<li>ID: {$cat['id']} - {$cat['name']} (Cor: {$cat['color']})</li>\n";
        }
        echo "</ul>\n";
        
        echo "<h5>Despesas (" . count($despesas) . "):</h5>\n";
        echo "<ul>\n";
        foreach ($despesas as $cat) {
            echo "<li>ID: {$cat['id']} - {$cat['name']} (Cor: {$cat['color']})</li>\n";
        }
        echo "</ul>\n";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro ao carregar categorias: " . $e->getMessage() . "</p>\n";
}

echo "<hr>\n";
echo "<p><strong>Agora teste manualmente:</strong></p>\n";
echo "<p>1. <a href='accounts.php?action=add' target='_blank'>Adicionar Nova Conta</a></p>\n";
echo "<p>2. <a href='categories.php' target='_blank'>Ver Categorias</a></p>\n";
echo "<p>3. <a href='index.php' target='_blank'>Dashboard</a></p>\n";
?>