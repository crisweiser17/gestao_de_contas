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
    } elseif ($action == 'delete_recurring_all' && isset($_POST['account_id'])) {
        try {
            $conn = $recurringModel->getConnection();
            $conn->beginTransaction();

            $aid = intval($_POST['account_id']);

            // Garantir que pertence ao usuário
            $verify = $conn->prepare("SELECT id FROM accounts WHERE id = :id AND user_id = :user_id AND is_recurring = 1");
            $verify->execute([':id' => $aid, ':user_id' => $userId]);
            $row = $verify->fetch(PDO::FETCH_ASSOC);
            if (!$row) { throw new Exception('Conta recorrente não encontrada ou não pertence ao usuário'); }

            // Remover filhos
            $delChildren = $conn->prepare("DELETE FROM accounts WHERE recurring_parent_id = :parent_id AND user_id = :user_id");
            $delChildren->execute([':parent_id' => $aid, ':user_id' => $userId]);

            // Remover configuração
            $delSetting = $conn->prepare("DELETE FROM recurring_settings WHERE account_id = :account_id");
            $delSetting->execute([':account_id' => $aid]);

            // Remover pai
            $delParent = $conn->prepare("DELETE FROM accounts WHERE id = :id AND user_id = :user_id");
            $delParent->execute([':id' => $aid, ':user_id' => $userId]);

            $conn->commit();
            $message = "Recorrência e instâncias excluídas com sucesso!";
            $messageType = 'success';
        } catch (Exception $e) {
            if ($recurringModel->getConnection()) { $recurringModel->getConnection()->rollBack(); }
            $message = "Erro ao excluir recorrência: " . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($action == 'terminate_recurring' && isset($_POST['account_id'])) {
        try {
            $conn = $recurringModel->getConnection();
            $conn->beginTransaction();

            $aid = intval($_POST['account_id']);
            $effective = $_POST['terminate_effective'] ?? 'tomorrow';
            $cutoff = ($effective === 'today') ? date('Y-m-d') : date('Y-m-d', strtotime('+1 day'));

            $verify = $conn->prepare("SELECT id FROM accounts WHERE id = :id AND user_id = :user_id AND is_recurring = 1");
            $verify->execute([':id' => $aid, ':user_id' => $userId]);
            $row = $verify->fetch(PDO::FETCH_ASSOC);
            if (!$row) { throw new Exception('Conta recorrente não encontrada ou não pertence ao usuário'); }

            $delFuture = $conn->prepare("DELETE FROM accounts WHERE recurring_parent_id = :parent_id AND user_id = :user_id AND status = 'pendente' AND due_date >= :cutoff");
            $delFuture->execute([':parent_id' => $aid, ':user_id' => $userId, ':cutoff' => $cutoff]);

            $updSetting = $conn->prepare("UPDATE recurring_settings SET is_active = 0, end_date = :cutoff, next_generation_date = NULL WHERE account_id = :account_id");
            $updSetting->execute([':account_id' => $aid, ':cutoff' => $cutoff]);

            $conn->commit();
            $message = "Recorrência encerrada. Parcelas futuras removidas e histórico mantido.";
            $messageType = 'success';
        } catch (Exception $e) {
            if ($recurringModel->getConnection()) { $recurringModel->getConnection()->rollBack(); }
            $message = "Erro ao encerrar recorrência: " . $e->getMessage();
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

// Endpoint JSON: listar instâncias de uma recorrência
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['action']) && $_GET['action'] === 'list_instances') {
    header('Content-Type: application/json');
    try {
        $parentId = intval($_GET['parent_id'] ?? 0);
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(50, max(1, intval($_GET['limit'] ?? 10)));
        $offset = ($page - 1) * $limit;

        // Validar posse e recorrência
        $verify = $recurringModel->getConnection()->prepare("SELECT id FROM accounts WHERE id = :id AND user_id = :user_id AND is_recurring = 1");
        $verify->execute([':id' => $parentId, ':user_id' => $userId]);
        $row = $verify->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['error' => 'Conta recorrente inválida']); exit; }

        // Total
        $countStmt = $recurringModel->getConnection()->prepare("SELECT COUNT(*) AS total FROM accounts WHERE recurring_parent_id = :pid AND user_id = :uid");
        $countStmt->execute([':pid' => $parentId, ':uid' => $userId]);
        $total = intval($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        // Lista paginada
        $listStmt = $recurringModel->getConnection()->prepare(
            "SELECT id, description, amount, due_date, status, type, url 
             FROM accounts 
             WHERE recurring_parent_id = :pid AND user_id = :uid 
             ORDER BY due_date ASC 
             LIMIT :limit OFFSET :offset"
        );
        $listStmt->bindValue(':pid', $parentId, PDO::PARAM_INT);
        $listStmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $listStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $listStmt->execute();
        $items = $listStmt->fetchAll(PDO::FETCH_ASSOC);

        // Metadados da recorrência para exibir total ou indeterminado
        $metaStmt = $recurringModel->getConnection()->prepare("SELECT end_date, max_occurrences FROM recurring_settings WHERE account_id = :pid");
        $metaStmt->execute([':pid' => $parentId]);
        $recMeta = $metaStmt->fetch(PDO::FETCH_ASSOC) ?: ['end_date' => null, 'max_occurrences' => null];
        $isIndeterminate = empty($recMeta['end_date']) && empty($recMeta['max_occurrences']);

        echo json_encode([
            'items' => $items,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => $limit ? ceil($total / $limit) : 1,
            'recurrence' => [
                'end_date' => $recMeta['end_date'] ?? null,
                'max_occurrences' => $recMeta['max_occurrences'] ?? null,
                'is_indeterminate' => $isIndeterminate
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Falha ao listar instâncias']);
    }
    exit;
}
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

                <?php if (!empty($needsProcessing)): ?>
                <div class="mt-6">
                    <details class="bg-yellow-50 border border-yellow-200 rounded-lg">
                        <summary class="p-4 cursor-pointer flex items-center justify-between">
                            <span class="text-lg font-semibold text-yellow-800">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                Contas Pendentes de Processamento
                            </span>
                            <span class="text-sm text-yellow-700">(<?= count($needsProcessing) ?>)</span>
                        </summary>
                        <div class="p-4 space-y-2 bg-white">
                            <?php foreach ($needsProcessing as $account): ?>
                            <div class="flex justify-between items-center p-3 rounded border">
                                <span class="text-sm text-gray-900"><?= htmlspecialchars($account['description']) ?></span>
                                <?php if (!empty($account['name'])): ?>
                                <span class="text-xs text-gray-500"><?= htmlspecialchars($account['name']) ?></span>
                                <?php endif; ?>
                                <span class="text-xs text-gray-500">
                                    Próxima: <?= $account['next_generation_date'] ? formatDate($account['next_generation_date']) : 'Agora' ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                </div>
                <?php endif; ?>

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
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Núm. parcelas</th>
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
                            <?php
                            $metaStmt = $recurringModel->getConnection()->prepare("SELECT end_date, max_occurrences FROM recurring_settings WHERE account_id = :pid");
                            $metaStmt->execute([':pid' => $account['id']]);
                            $meta = $metaStmt->fetch(PDO::FETCH_ASSOC) ?: ['end_date' => null, 'max_occurrences' => null];
                            $endDateRaw = $meta['end_date'] ?? null;
                            $noEnd = empty($endDateRaw) || $endDateRaw === '0000-00-00';
                            $isIndeterminate = $noEnd && empty($meta['max_occurrences']);
                            $numParcelas = $isIndeterminate ? 'sem data de término' : (int)$meta['max_occurrences'];
                            ?>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs">
                                    <?= $numParcelas ?>
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
                                    <button onclick="openInstancesModal(<?= $account['id'] ?>)" class="text-gray-600 hover:text-gray-900" title="Ver parcelas">
                                        <i class="fas fa-list-ul"></i>
                                    </button>
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
                                    <button type="button" onclick="openTerminateModal(<?= $account['id'] ?>)" class="text-gray-700 hover:text-gray-900" title="Encerrar recorrência">
                                        <i class="fas fa-stop-circle"></i>
                                    </button>
                                    <?php else: ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="action" value="activate">
                                        <input type="hidden" name="account_id" value="<?= $account['id'] ?>">
                                        <button type="submit" class="text-green-600 hover:text-green-900">
                                            <i class="fas fa-play"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>

                                    <form method="POST" class="inline" onsubmit="return confirm('Excluir recorrência e todas as instâncias?')">
                                        <input type="hidden" name="action" value="delete_recurring_all">
                                        <input type="hidden" name="account_id" value="<?= $account['id'] ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-800">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
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
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Núm. parcelas</th>
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
                            <?php
                            $metaStmt = $recurringModel->getConnection()->prepare("SELECT end_date, max_occurrences FROM recurring_settings WHERE account_id = :pid");
                            $metaStmt->execute([':pid' => $account['id']]);
                            $meta = $metaStmt->fetch(PDO::FETCH_ASSOC) ?: ['end_date' => null, 'max_occurrences' => null];
                            $endDateRaw = $meta['end_date'] ?? null;
                            $noEnd = empty($endDateRaw) || $endDateRaw === '0000-00-00';
                            $isIndeterminate = $noEnd && empty($meta['max_occurrences']);
                            $numParcelas = $isIndeterminate ? 'sem data de término' : (int)$meta['max_occurrences'];
                            ?>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs">
                                    <?= $numParcelas ?>
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
                                    <button onclick="openInstancesModal(<?= $account['id'] ?>)" class="text-gray-600 hover:text-gray-900" title="Ver parcelas">
                                        <i class="fas fa-list-ul"></i>
                                    </button>
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
                                    <button type="button" onclick="openTerminateModal(<?= $account['id'] ?>)" class="text-gray-700 hover:text-gray-900" title="Encerrar recorrência">
                                        <i class="fas fa-stop-circle"></i>
                                    </button>
                                    <?php else: ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="action" value="activate">
                                        <input type="hidden" name="account_id" value="<?= $account['id'] ?>">
                                        <button type="submit" class="text-green-600 hover:text-green-900">
                                            <i class="fas fa-play"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>

                                    <form method="POST" class="inline" onsubmit="return confirm('Excluir recorrência e todas as instâncias?')">
                                        <input type="hidden" name="action" value="delete_recurring_all">
                                        <input type="hidden" name="account_id" value="<?= $account['id'] ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-800">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>


    </main>

    <!-- Modal de Instâncias -->
    <div id="instancesModal" class="fixed inset-0 bg-black bg-opacity-40 hidden items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl">
            <div class="px-4 py-3 border-b flex justify-between items-center">
                <h3 class="text-lg font-semibold">Parcelas da recorrência <span id="instancesMeta" class="ml-2 text-sm text-gray-600"></span></h3>
                <button onclick="closeInstancesModal()" class="text-gray-600 hover:text-gray-900"><i class="fas fa-times"></i></button>
            </div>
            <div class="p-4">
                <div id="instancesContent" class="space-y-2"></div>
                <div class="mt-4 flex items-center justify-between">
                    <button id="prevInstances" class="px-3 py-1 bg-gray-100 rounded hover:bg-gray-200">Anterior</button>
                    <span id="instancesPageInfo" class="text-sm text-gray-600"></span>
                    <button id="nextInstances" class="px-3 py-1 bg-gray-100 rounded hover:bg-gray-200">Próximo</button>
                </div>
            </div>
        </div>
    </div>

    <div id="terminateModal" class="fixed inset-0 bg-black bg-opacity-30 hidden items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-lg w-full max-w-md p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Encerrar recorrência</h3>
            <p class="text-sm text-gray-600 mb-4">Escolha a partir de quando encerrar a recorrência.</p>
            <div class="flex gap-3">
                <button onclick="submitTerminate('today')" class="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700">Hoje</button>
                <button onclick="submitTerminate('tomorrow')" class="px-4 py-2 bg-yellow-600 text-white rounded hover:bg-yellow-700">A partir de amanhã</button>
                <button onclick="closeTerminateModal()" class="px-4 py-2 bg-gray-200 text-gray-800 rounded hover:bg-gray-300">Cancelar</button>
            </div>
        </div>
    </div>

    <form id="terminateForm" method="POST" class="hidden">
        <input type="hidden" name="action" value="terminate_recurring">
        <input type="hidden" name="account_id" id="terminate_account_id">
        <input type="hidden" name="terminate_effective" id="terminate_effective" value="tomorrow">
    </form>

    <script>
        function openEditModal(accountId) {
            window.location.href = 'accounts.php?action=edit&id=' + accountId + '&edit_context=parent';
        }

        let instancesParentId = null;
        let instancesPage = 1;
        const instancesLimit = 10;

        function openInstancesModal(parentId) {
            instancesParentId = parentId;
            instancesPage = 1;
            document.getElementById('instancesModal').classList.remove('hidden');
            document.getElementById('instancesModal').classList.add('flex');
            fetchInstances(instancesPage);
        }
        function closeInstancesModal() {
            document.getElementById('instancesModal').classList.add('hidden');
            document.getElementById('instancesModal').classList.remove('flex');
        }
        async function fetchInstances(page) {
            try {
                const url = `recurring.php?action=list_instances&parent_id=${instancesParentId}&page=${page}&limit=${instancesLimit}`;
                const res = await fetch(url);
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                renderInstances(data);
            } catch (e) {
                document.getElementById('instancesContent').innerHTML = '<p class="text-red-600">Erro ao carregar parcelas.</p>';
            }
        }
        function renderInstances(data) {
            const content = document.getElementById('instancesContent');
            content.innerHTML = '';
            if (!data.items || data.items.length === 0) {
                content.innerHTML = '<p class="text-gray-600">Nenhuma parcela encontrada.</p>';
            } else {
                const rows = data.items.map(it => (
                    `<div class=\"flex justify-between border p-2 rounded\">\n                        <span class=\"text-sm text-gray-900\">${it.description ?? ''}</span>\n                        <span class=\"text-sm text-gray-700\">${it.due_date}</span>\n                        <span class=\"text-sm ${it.status === 'pendente' ? 'text-yellow-700' : 'text-green-700'}\">${it.status}</span>\n                    </div>`
                ));
                content.innerHTML = rows.join('');
            }
            const meta = document.getElementById('instancesMeta');
            if (data.recurrence && data.recurrence.is_indeterminate) {
                meta.textContent = 'sem data de término';
            } else if (data.recurrence && data.recurrence.max_occurrences) {
                meta.textContent = `Total: ${data.recurrence.max_occurrences} parcelas`;
            } else {
                meta.textContent = '';
            }
            const info = document.getElementById('instancesPageInfo');
            info.textContent = `Página ${data.page} de ${data.pages}`;
            document.getElementById('prevInstances').onclick = () => {
                if (instancesPage > 1) { instancesPage--; fetchInstances(instancesPage); }
            };
            document.getElementById('nextInstances').onclick = () => {
                if (instancesPage < data.pages) { instancesPage++; fetchInstances(instancesPage); }
            };
        }
        function openTerminateModal(id) {
            document.getElementById('terminate_account_id').value = id;
            const m = document.getElementById('terminateModal');
            m.classList.remove('hidden');
            m.classList.add('flex');
            m.style.display = 'flex';
        }
        function closeTerminateModal() {
            const m = document.getElementById('terminateModal');
            m.classList.add('hidden');
            m.classList.remove('flex');
            m.style.display = 'none';
        }
        function submitTerminate(effect) {
            document.getElementById('terminate_effective').value = effect;
            document.getElementById('terminateForm').submit();
            closeTerminateModal();
        }
    </script>
</body>
</html>