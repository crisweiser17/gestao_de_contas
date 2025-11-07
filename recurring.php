<?php
require_once 'config/config.php';

// Se não estiver logado, redireciona para login
if (!isLoggedIn()) {
    redirect('login.php');
}

require_once 'models/Account.php';
require_once 'models/Category.php';
require_once 'models/RecurringSetting.php';

$accountModel = new Account($pdo);
$categoryModel = new Category($pdo);
$recurringModel = new RecurringSetting();

$userId = $_SESSION['user_id'];
$message = '';
$messageType = '';

// Executar manutenção leve das contas recorrentes (em background)
try {
    $recurringModel->lightMaintenance($userId);
} catch (Exception $e) {
    // Falha silenciosa - não deve interromper o carregamento da página
    error_log("Erro na manutenção de recorrências: " . $e->getMessage());
}

// Processar ações
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action == 'process_all') {
        try {
            $generated = $recurringModel->processUserRecurrences($userId);
            $message = "Processamento concluído! $generated contas foram geradas.";
            $messageType = 'success';
        } catch (Exception $e) {
            $message = "Erro ao processar recorrências: " . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($action == 'deactivate' && isset($_POST['account_id'])) {
        try {
            $recurringModel->deactivate($_POST['account_id']);
            $message = "Recorrência desativada com sucesso!";
            $messageType = 'success';
        } catch (Exception $e) {
            $message = "Erro ao desativar recorrência: " . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($action == 'activate' && isset($_POST['account_id'])) {
        try {
            // Usar método público para ativar
            $recurringModel->activate($_POST['account_id']);
            $message = "Recorrência ativada com sucesso!";
            $messageType = 'success';
        } catch (Exception $e) {
            $message = "Erro ao ativar recorrência: " . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Buscar contas recorrentes a pagar (despesas)
$queryExpenses = "SELECT a.*, c.name as category_name, rs.frequency_type, rs.frequency_interval, 
                         rs.end_date, rs.max_occurrences, rs.next_generation_date, rs.is_active,
                         (SELECT COUNT(*) FROM accounts child WHERE child.recurring_parent_id = a.id) as generated_count
                  FROM accounts a
                  INNER JOIN recurring_settings rs ON a.id = rs.account_id
                  LEFT JOIN categories c ON a.category_id = c.id
                  WHERE a.user_id = :user_id AND a.is_recurring = 1 AND a.type = 'despesa'
                  ORDER BY a.created_at DESC";

$stmt = $recurringModel->getConnection()->prepare($queryExpenses);
$stmt->bindParam(':user_id', $userId);
$stmt->execute();
$recurringExpenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar contas recorrentes a receber (receitas)
$queryIncomes = "SELECT a.*, c.name as category_name, rs.frequency_type, rs.frequency_interval, 
                        rs.end_date, rs.max_occurrences, rs.next_generation_date, rs.is_active,
                        (SELECT COUNT(*) FROM accounts child WHERE child.recurring_parent_id = a.id) as generated_count
                 FROM accounts a
                 INNER JOIN recurring_settings rs ON a.id = rs.account_id
                 LEFT JOIN categories c ON a.category_id = c.id
                 WHERE a.user_id = :user_id AND a.is_recurring = 1 AND a.type = 'receita'
                 ORDER BY a.created_at DESC";

$stmt = $recurringModel->getConnection()->prepare($queryIncomes);
$stmt->bindParam(':user_id', $userId);
$stmt->execute();
$recurringIncomes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Manter compatibilidade com código existente
$recurringAccounts = array_merge($recurringExpenses, $recurringIncomes);

// Buscar contas que precisam ser processadas
$accountsNeedingGeneration = $recurringModel->getAccountsNeedingGeneration();
$needsProcessing = array_filter($accountsNeedingGeneration, function($account) use ($userId) {
    return $account['user_id'] == $userId;
});
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Recorrências</title>
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
    <?php require_once 'partials/header.php'; render_header('recurring'); ?>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Mensagens -->
        <?php if ($message): ?>
        <div class="mb-6 p-4 rounded-md <?= $messageType == 'success' ? 'bg-green-50 text-green-800 border border-green-200' : 'bg-red-50 text-red-800 border border-red-200' ?>">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas <?= $messageType == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium"><?= htmlspecialchars($message) ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Ações Rápidas -->
        <div class="bg-white rounded-lg shadow mb-8">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-900">
                    <i class="fas fa-cogs mr-2"></i>
                    Gerenciar Recorrências
                </h2>
            </div>

            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                    <!-- Estatísticas -->
                    <div class="bg-red-50 rounded-lg p-4">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <i class="fas fa-credit-card text-red-600 text-2xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-red-600">Contas a Pagar</p>
                                <p class="text-2xl font-bold text-red-900"><?= count($recurringExpenses) ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-green-50 rounded-lg p-4">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <i class="fas fa-money-bill-wave text-green-600 text-2xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-green-600">Contas a Receber</p>
                                <p class="text-2xl font-bold text-green-900"><?= count($recurringIncomes) ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-yellow-50 rounded-lg p-4">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <i class="fas fa-clock text-yellow-600 text-2xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-yellow-600">Pendentes</p>
                                <p class="text-2xl font-bold text-yellow-900"><?= count($needsProcessing) ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-blue-50 rounded-lg p-4">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <i class="fas fa-check-circle text-blue-600 text-2xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-blue-600">Ativas</p>
                                <p class="text-2xl font-bold text-blue-900">
                                    <?= count(array_filter($recurringAccounts, function($acc) { return $acc['is_active']; })) ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Ações -->
                <div class="mt-6 flex flex-wrap gap-4">
                    <form method="POST" class="inline">
                        <input type="hidden" name="action" value="process_all">
                        <button type="submit" 
                                class="bg-primary hover:bg-blue-700 text-white px-6 py-2 rounded-md transition-colors"
                                <?= count($needsProcessing) == 0 ? 'disabled' : '' ?>>
                            <i class="fas fa-play mr-2"></i>
                            Processar Todas (<?= count($needsProcessing) ?>)
                        </button>
                    </form>

                    <a href="accounts.php?recurring=1" 
                       class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-md transition-colors">
                        <i class="fas fa-plus mr-2"></i>
                        Nova Recorrência
                    </a>
                </div>
            </div>
        </div>

        <!-- Contas Recorrentes a Pagar -->
        <div class="bg-white rounded-lg shadow mb-6">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-red-700">
                    <i class="fas fa-credit-card mr-2"></i>
                    Contas Recorrentes a Pagar
                    <span class="text-sm font-normal text-gray-500 ml-2">(<?= count($recurringExpenses) ?> contas)</span>
                </h2>
            </div>

            <?php if (empty($recurringExpenses)): ?>
            <div class="p-8 text-center">
                <i class="fas fa-credit-card text-gray-400 text-4xl mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">Nenhuma conta recorrente a pagar</h3>
                <p class="text-gray-600 mb-4">Configure contas recorrentes de despesas para automatizar seu controle.</p>
                <a href="accounts.php?recurring=1&type=expense" 
                   class="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-md transition-colors">
                    <i class="fas fa-plus mr-2"></i>
                    Criar Conta a Pagar
                </a>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-red-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Conta</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Frequência</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Próxima Geração</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Geradas</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($recurringExpenses as $account): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <div>
                                    <div class="text-sm font-medium text-gray-900">
                                        <i class="fas fa-sync-alt text-blue-500 mr-2" title="Conta Recorrente"></i>
                                        <?= htmlspecialchars($account['description']) ?>
                                    </div>
                                    <?php if (!empty($account['name'])): ?>
                                    <div class="text-xs text-gray-500"><?= htmlspecialchars($account['name']) ?></div>
                                    <?php endif; ?>
                                    <div class="text-sm text-gray-500">
                                        <?= htmlspecialchars($account['category_name']) ?> • 
                                        <span class="text-red-600">
                                            <?= formatCurrency($account['amount']) ?>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php
                                $frequency = '';
                                switch ($account['frequency_type']) {
                                    case 'semanal':
                                        $frequency = "Semanal";
                                        break;
                                    case 'mensal':
                                        $frequency = "Mensal";
                                        break;
                                    case 'bimestral':
                                        $frequency = "Bimestral";
                                        break;
                                    case 'trimestral':
                                        $frequency = "Trimestral";
                                        break;
                                    case 'semestral':
                                        $frequency = "Semestral";
                                        break;
                                    case 'anual':
                                        $frequency = "Anual";
                                        break;
                                    case 'personalizado':
                                        $frequency = "Personalizado ({$account['frequency_interval']} dias)";
                                        break;
                                    default:
                                        $frequency = 'Não definida';
                                        break;
                                }
                                echo $frequency;
                                ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?= $account['next_generation_date'] ? formatDate($account['next_generation_date']) : 'Não definida' ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs">
                                    <?= $account['generated_count'] ?>
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($account['is_active']): ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    <i class="fas fa-check-circle mr-1"></i>
                                    Ativa
                                </span>
                                <?php else: ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                    <i class="fas fa-pause-circle mr-1"></i>
                                    Inativa
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-sm font-medium">
                                <div class="flex space-x-2">
                                    <button onclick="openEditModal(<?= $account['id'] ?>)" 
                                       class="text-blue-600 hover:text-blue-900">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    
                                    <?php if ($account['is_active']): ?>
                                    <form method="POST" class="inline" onsubmit="return confirm('Desativar esta recorrência?')">
                                        <input type="hidden" name="action" value="deactivate">
                                        <input type="hidden" name="account_id" value="<?= $account['id'] ?>">
                                        <button type="submit" class="text-yellow-600 hover:text-yellow-900">
                                            <i class="fas fa-pause"></i>
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="action" value="activate">
                                        <input type="hidden" name="account_id" value="<?= $account['id'] ?>">
                                        <button type="submit" class="text-green-600 hover:text-green-900">
                                            <i class="fas fa-play"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Contas Recorrentes a Receber -->
        <div class="bg-white rounded-lg shadow mb-6">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-green-700">
                    <i class="fas fa-money-bill-wave mr-2"></i>
                    Contas Recorrentes a Receber
                    <span class="text-sm font-normal text-gray-500 ml-2">(<?= count($recurringIncomes) ?> contas)</span>
                </h2>
            </div>

            <?php if (empty($recurringIncomes)): ?>
            <div class="p-8 text-center">
                <i class="fas fa-money-bill-wave text-gray-400 text-4xl mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">Nenhuma conta recorrente a receber</h3>
                <p class="text-gray-600 mb-4">Configure contas recorrentes de receitas para automatizar seu controle.</p>
                <a href="accounts.php?recurring=1&type=income" 
                   class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-md transition-colors">
                    <i class="fas fa-plus mr-2"></i>
                    Criar Conta a Receber
                </a>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-green-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Conta</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Frequência</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Próxima Geração</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Geradas</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($recurringIncomes as $account): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <div>
                                    <div class="text-sm font-medium text-gray-900">
                                        <i class="fas fa-sync-alt text-blue-500 mr-2" title="Conta Recorrente"></i>
                                        <?= htmlspecialchars($account['description']) ?>
                                    </div>
                                    <?php if (!empty($account['name'])): ?>
                                    <div class="text-xs text-gray-500"><?= htmlspecialchars($account['name']) ?></div>
                                    <?php endif; ?>
                                    <div class="text-sm text-gray-500">
                                        <?= htmlspecialchars($account['category_name']) ?> • 
                                        <span class="text-green-600">
                                            <?= formatCurrency($account['amount']) ?>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php
                                $frequency = '';
                                switch ($account['frequency_type']) {
                                    case 'semanal':
                                        $frequency = "Semanal";
                                        break;
                                    case 'mensal':
                                        $frequency = "Mensal";
                                        break;
                                    case 'bimestral':
                                        $frequency = "Bimestral";
                                        break;
                                    case 'trimestral':
                                        $frequency = "Trimestral";
                                        break;
                                    case 'semestral':
                                        $frequency = "Semestral";
                                        break;
                                    case 'anual':
                                        $frequency = "Anual";
                                        break;
                                    case 'personalizado':
                                        $frequency = "Personalizado ({$account['frequency_interval']} dias)";
                                        break;
                                    default:
                                        $frequency = 'Não definida';
                                        break;
                                }
                                echo $frequency;
                                ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?= $account['next_generation_date'] ? formatDate($account['next_generation_date']) : 'Não definida' ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs">
                                    <?= $account['generated_count'] ?>
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($account['is_active']): ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    <i class="fas fa-check-circle mr-1"></i>
                                    Ativa
                                </span>
                                <?php else: ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                    <i class="fas fa-pause-circle mr-1"></i>
                                    Inativa
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-sm font-medium">
                                <div class="flex space-x-2">
                                    <button onclick="openEditModal(<?= $account['id'] ?>)" 
                                       class="text-blue-600 hover:text-blue-900">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    
                                    <?php if ($account['is_active']): ?>
                                    <form method="POST" class="inline" onsubmit="return confirm('Desativar esta recorrência?')">
                                        <input type="hidden" name="action" value="deactivate">
                                        <input type="hidden" name="account_id" value="<?= $account['id'] ?>">
                                        <button type="submit" class="text-yellow-600 hover:text-yellow-900">
                                            <i class="fas fa-pause"></i>
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="action" value="activate">
                                        <input type="hidden" name="account_id" value="<?= $account['id'] ?>">
                                        <button type="submit" class="text-green-600 hover:text-green-900">
                                            <i class="fas fa-play"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Contas Pendentes de Processamento -->
        <?php if (!empty($needsProcessing)): ?>
        <div class="mt-8 bg-yellow-50 border border-yellow-200 rounded-lg">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-yellow-800 mb-4">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    Contas Pendentes de Processamento
                </h3>
                <div class="space-y-2">
                    <?php foreach ($needsProcessing as $account): ?>
                    <div class="flex justify-between items-center bg-white p-3 rounded border">
                        <span class="text-sm text-gray-900"><?= htmlspecialchars($account['description']) ?></span>
                        <?php if (!empty($account['name'])): ?>
                        <span class="text-xs text-gray-500 block"><?= htmlspecialchars($account['name']) ?></span>
                        <?php endif; ?>
                        <span class="text-xs text-gray-500">
                            Próxima: <?= $account['next_generation_date'] ? formatDate($account['next_generation_date']) : 'Agora' ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <script>
        function openEditModal(accountId) {
            window.location.href = 'accounts.php?action=edit&id=' + accountId + '&edit_context=parent';
        }
    </script>
</body>
</html>