<?php
require_once 'config/config.php';

// Se não estiver logado, redireciona para login
if (!isLoggedIn()) {
    redirect('login.php');
}

require_once 'models/Category.php';

$categoryModel = new Category($pdo);
$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? 'list';
$categoryId = $_GET['id'] ?? null;

$errors = [];
$success = '';

// Processar ações
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($action == 'add' || $action == 'edit') {
        $data = [
            'user_id' => $userId,
            'name' => trim($_POST['name'] ?? ''),
            'type' => $_POST['type'] ?? '',
            'color' => $_POST['color'] ?? '#3B82F6',
            'description' => trim($_POST['description'] ?? '')
        ];

        // Validação
        if (empty($data['name'])) {
            $errors[] = 'Nome é obrigatório';
        } else if ($categoryModel->nameExists($data['name'], $userId, $action == 'edit' ? $categoryId : null)) {
            $errors[] = 'Já existe uma categoria com este nome';
        }
        
        if (empty($data['type']) || !in_array($data['type'], ['receita', 'despesa'])) {
            $errors[] = 'Tipo deve ser receita ou despesa';
        }

        if (empty($errors)) {
            if ($action == 'add') {
                if ($categoryModel->create($data)) {
                    $success = 'Categoria criada com sucesso!';
                    $action = 'list';
                } else {
                    $errors[] = 'Erro ao criar categoria';
                }
            } else if ($action == 'edit' && $categoryId) {
                if ($categoryModel->update($categoryId, $data, $userId)) {
                    $success = 'Categoria atualizada com sucesso!';
                    $action = 'list';
                } else {
                    $errors[] = 'Erro ao atualizar categoria';
                }
            }
        }
    } else if ($action == 'delete' && $categoryId) {
        $result = $categoryModel->delete($categoryId, $userId);
        if ($result === true) {
            $success = 'Categoria excluída com sucesso!';
        } else if ($result === 'has_accounts') {
            $errors[] = 'Não é possível excluir esta categoria pois existem contas associadas a ela';
        } else {
            $errors[] = 'Erro ao excluir categoria';
        }
        $action = 'list';
    }
}

// Buscar dados para exibição
if ($action == 'list') {
    $categories = $categoryModel->getByUserId($userId);
    $stats = $categoryModel->getUsageStats($userId);
} else if ($action == 'edit' && $categoryId) {
    $category = $categoryModel->getById($categoryId, $userId);
    if (!$category) {
        $action = 'list';
        $errors[] = 'Categoria não encontrada';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Categorias</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#1e40af',
                        secondary: '#64748b'
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50">
    <?php require_once 'partials/header.php'; render_header('categories'); ?>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Mensagens -->
        <?php if (!empty($errors)): ?>
        <div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded">
            <ul class="list-disc list-inside">
                <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded">
            <i class="fas fa-check-circle mr-2"></i>
            <?= htmlspecialchars($success) ?>
        </div>
        <?php endif; ?>

        <?php if ($action == 'list'): ?>
        <!-- Lista de Categorias -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Categorias de Receita -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-lg shadow">
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex justify-between items-center">
                            <h2 class="text-xl font-semibold text-gray-900">
                                <i class="fas fa-tags mr-2"></i>
                                Minhas Categorias
                            </h2>
                            <a href="?action=add" class="bg-primary hover:bg-blue-700 text-white px-4 py-2 rounded-md transition-colors">
                                <i class="fas fa-plus mr-2"></i>
                                Nova Categoria
                            </a>
                        </div>
                    </div>

                    <!-- Categorias de Receita -->
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-green-600 mb-4">
                            <i class="fas fa-arrow-up mr-2"></i>
                            Receitas
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <?php 
                            $revenueCategories = array_filter($categories, function($cat) { return $cat['type'] == 'receita'; });
                            if (empty($revenueCategories)): 
                            ?>
                            <div class="col-span-2 text-center text-gray-500 py-8">
                                <i class="fas fa-inbox text-3xl mb-2"></i>
                                <p>Nenhuma categoria de receita encontrada</p>
                            </div>
                            <?php else: ?>
                            <?php foreach ($revenueCategories as $category): ?>
                            <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center">
                                        <div class="w-4 h-4 rounded-full mr-3" style="background-color: <?= htmlspecialchars($category['color']) ?>"></div>
                                        <div>
                                            <h4 class="font-medium text-gray-900"><?= htmlspecialchars($category['name']) ?></h4>
                                            <?php if (isset($category['description']) && !empty($category['description'])): ?>
                                            <p class="text-sm text-gray-600"><?= htmlspecialchars($category['description']) ?></p>
                                            <?php endif; ?>
                                            <?php 
                                            $categoryStats = array_filter($stats, function($stat) use ($category) { 
                                                return $stat['category_id'] == $category['id']; 
                                            });
                                            if (!empty($categoryStats)):
                                                $stat = reset($categoryStats);
                                            ?>
                                            <p class="text-xs text-gray-500"><?= $stat['account_count'] ?> conta(s)</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="flex space-x-2">
                                        <a href="?action=edit&id=<?= $category['id'] ?>" 
                                           class="text-blue-600 hover:text-blue-800">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if (!isset($category['is_default']) || !$category['is_default']): ?>
                                        <form method="POST" class="inline" onsubmit="return confirm('Tem certeza que deseja excluir esta categoria?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $category['id'] ?>">
                                            <button type="submit" class="text-red-600 hover:text-red-800">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Categorias de Despesa -->
                    <div class="p-6 border-t border-gray-200">
                        <h3 class="text-lg font-medium text-red-600 mb-4">
                            <i class="fas fa-arrow-down mr-2"></i>
                            Despesas
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <?php 
                            $expenseCategories = array_filter($categories, function($cat) { return $cat['type'] == 'despesa'; });
                            if (empty($expenseCategories)): 
                            ?>
                            <div class="col-span-2 text-center text-gray-500 py-8">
                                <i class="fas fa-inbox text-3xl mb-2"></i>
                                <p>Nenhuma categoria de despesa encontrada</p>
                            </div>
                            <?php else: ?>
                            <?php foreach ($expenseCategories as $category): ?>
                            <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center">
                                        <div class="w-4 h-4 rounded-full mr-3" style="background-color: <?= htmlspecialchars($category['color']) ?>"></div>
                                        <div>
                                            <h4 class="font-medium text-gray-900"><?= htmlspecialchars($category['name']) ?></h4>
                                            <?php if (isset($category['description']) && !empty($category['description'])): ?>
                                            <p class="text-sm text-gray-600"><?= htmlspecialchars($category['description']) ?></p>
                                            <?php endif; ?>
                                            <?php 
                                            $categoryStats = array_filter($stats, function($stat) use ($category) { 
                                                return $stat['category_id'] == $category['id']; 
                                            });
                                            if (!empty($categoryStats)):
                                                $stat = reset($categoryStats);
                                            ?>
                                            <p class="text-xs text-gray-500"><?= $stat['account_count'] ?> conta(s)</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="flex space-x-2">
                                        <a href="?action=edit&id=<?= $category['id'] ?>" 
                                           class="text-blue-600 hover:text-blue-800">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if (!isset($category['is_default']) || !$category['is_default']): ?>
                                        <form method="POST" class="inline" onsubmit="return confirm('Tem certeza que deseja excluir esta categoria?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $category['id'] ?>">
                                            <button type="submit" class="text-red-600 hover:text-red-800">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Estatísticas -->
            <div class="space-y-6">
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">
                        <i class="fas fa-chart-pie mr-2"></i>
                        Estatísticas
                    </h3>
                    
                    <div class="space-y-4">
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-600">Total de Categorias</span>
                            <span class="font-semibold"><?= count($categories) ?></span>
                        </div>
                        
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-600">Receitas</span>
                            <span class="font-semibold text-green-600"><?= count($revenueCategories) ?></span>
                        </div>
                        
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-600">Despesas</span>
                            <span class="font-semibold text-red-600"><?= count($expenseCategories) ?></span>
                        </div>
                    </div>
                </div>

                <?php if (!empty($stats)): ?>
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">
                        <i class="fas fa-list-ol mr-2"></i>
                        Mais Utilizadas
                    </h3>
                    
                    <div class="space-y-3">
                        <?php 
                        usort($stats, function($a, $b) { return $b['account_count'] - $a['account_count']; });
                        foreach (array_slice($stats, 0, 5) as $stat): 
                        ?>
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <div class="w-3 h-3 rounded-full mr-2" style="background-color: <?= htmlspecialchars($stat['color']) ?>"></div>
                                <span class="text-sm text-gray-700"><?= htmlspecialchars($stat['category_name']) ?></span>
                            </div>
                            <span class="text-sm font-medium"><?= $stat['account_count'] ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php else: ?>
        <!-- Formulário de Adicionar/Editar -->
        <div class="max-w-2xl mx-auto">
            <div class="bg-white rounded-lg shadow">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-900">
                        <i class="fas <?= $action == 'add' ? 'fa-plus' : 'fa-edit' ?> mr-2"></i>
                        <?= $action == 'add' ? 'Nova Categoria' : 'Editar Categoria' ?>
                    </h2>
                </div>

                <form method="POST" class="p-6 space-y-6">
                    <input type="hidden" name="action" value="<?= $action ?>">
                    <?php if ($action == 'edit'): ?>
                    <input type="hidden" name="id" value="<?= $categoryId ?>">
                    <?php endif; ?>

                    <!-- Nome -->
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 mb-2">
                            Nome *
                        </label>
                        <input type="text" id="name" name="name" required
                               value="<?= htmlspecialchars($category['name'] ?? '') ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    </div>

                    <!-- Tipo -->
                    <div>
                        <label for="type" class="block text-sm font-medium text-gray-700 mb-2">
                            Tipo *
                        </label>
                        <select id="type" name="type" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                            <option value="">Selecione...</option>
                            <option value="receita" <?= ($category['type'] ?? '') == 'receita' ? 'selected' : '' ?>>Receita</option>
                            <option value="despesa" <?= ($category['type'] ?? '') == 'despesa' ? 'selected' : '' ?>>Despesa</option>
                        </select>
                    </div>

                    <!-- Cor -->
                    <div>
                        <label for="color" class="block text-sm font-medium text-gray-700 mb-2">
                            Cor
                        </label>
                        <div class="flex items-center space-x-3">
                            <input type="color" id="color" name="color" 
                                   value="<?= htmlspecialchars($category['color'] ?? '#3B82F6') ?>"
                                   class="h-10 w-20 border border-gray-300 rounded cursor-pointer">
                            <span class="text-sm text-gray-600">Escolha uma cor para identificar esta categoria</span>
                        </div>
                    </div>

                    <!-- Descrição -->
                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700 mb-2">
                            Descrição (opcional)
                        </label>
                        <textarea id="description" name="description" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"><?= htmlspecialchars($category['description'] ?? '') ?></textarea>
                    </div>

                    <div class="flex justify-end space-x-4">
                        <a href="?action=list" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-6 py-2 rounded-md transition-colors">
                            Cancelar
                        </a>
                        <button type="submit" class="bg-primary hover:bg-blue-700 text-white px-6 py-2 rounded-md transition-colors">
                            <?= $action == 'add' ? 'Criar Categoria' : 'Atualizar Categoria' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </main>
</body>
</html>