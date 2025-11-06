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

// Executar manutenção leve das contas recorrentes (em background)
try {
    $recurringModel->lightMaintenance($userId);
} catch (Exception $e) {
    // Falha silenciosa - não deve interromper o carregamento da página
    error_log("Erro na manutenção de recorrências: " . $e->getMessage());
}

// Ações rápidas (marcar como paga/recebida) diretamente do dashboard
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';
    if ($postAction === 'update_status') {
        $accountId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $status = $_POST['status'] ?? '';
        if ($accountId && in_array($status, ['pendente', 'paga', 'recebida'])) {
            $accountModel->updateStatus($accountId, $status, $userId);
        }
        // Evitar repost e manter no dashboard
        redirect('index.php');
        exit;
    }
}

// Buscar dados para o dashboard
$currentMonth = date('Y-m');
$currentDate = date('Y-m-d');

// Links rápidos para contas do mês atual e próximo
$currentMonthNumber = (int)date('n');
$currentYearNumber = (int)date('Y');
$nextMonthNumber = $currentMonthNumber === 12 ? 1 : $currentMonthNumber + 1;
$nextYearNumber = $currentMonthNumber === 12 ? $currentYearNumber + 1 : $currentYearNumber;

$queryBase = [
    'action' => 'list',
    'filter_category' => '',
    'filter_status' => '',
    'filter_date_filter_type' => 'month_year',
    'filter_date_month' => $currentMonthNumber,
    'filter_date_year' => $currentYearNumber,
    'filter_date_year_only' => '',
    'filter_date_from' => '',
    'filter_date_to' => ''
];
$currentMonthUrl = 'accounts.php?' . http_build_query($queryBase);
$queryBase['filter_date_month'] = $nextMonthNumber;
$queryBase['filter_date_year'] = $nextYearNumber;
$nextMonthUrl = 'accounts.php?' . http_build_query($queryBase);

// Resumo financeiro do mês atual
$monthlyIncome = $accountModel->getMonthlyTotal($userId, $currentMonth, 'receita');
$monthlyExpenses = $accountModel->getMonthlyTotal($userId, $currentMonth, 'despesa');
$monthlyBalance = $monthlyIncome - $monthlyExpenses;

// Realizados do mês atual
$monthlyIncomeRealized = $accountModel->getMonthlyRealizedTotal($userId, $currentMonth, 'receita');
$monthlyExpensesRealized = $accountModel->getMonthlyRealizedTotal($userId, $currentMonth, 'despesa');
$monthlyBalanceRealized = $monthlyIncomeRealized - $monthlyExpensesRealized;

// Contas vencidas
$overdueAccounts = $accountModel->getOverdueAccounts($userId);

// Contas vencendo nos próximos 7 dias
$upcomingWeek = $accountModel->getUpcomingAccounts($userId, 7);

// Contas vencendo do 8º dia até o fim do mês
$upcomingRestOfMonth = $accountModel->getUpcomingAccountsRestOfMonth($userId);

// Contas vencendo nos próximos 30 dias
$upcomingMonth = $accountModel->getUpcomingAccounts($userId, 30);

// Saldo atual (todas as receitas pagas - todas as despesas pagas)
$totalBalance = $accountModel->getCurrentBalance($userId);

// Últimas transações
$recentTransactions = $accountModel->getRecentTransactions($userId, 10);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Dashboard</title>
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
    <?php require_once 'partials/header.php'; render_header('index'); ?>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Cards de Resumo -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- Mês/Ano Atual -->
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="p-2 bg-blue-100 rounded-lg">
                        <i class="fas fa-calendar-alt text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <?php
                        $meses = [
                            1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
                            5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
                            9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
                        ];
                        $mesAtual = $meses[date('n')];
                        ?>
                        <p class="text-sm font-medium text-gray-600">Período Atual</p>
                        <p class="text-2xl font-bold text-blue-600"><?= $mesAtual ?> <?= date('Y') ?></p>
                    </div>
                </div>
            </div>

            <!-- Receitas do Mês -->
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="p-2 bg-green-100 rounded-lg">
                        <i class="fas fa-arrow-up text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Receitas do Mês</p>
                        <p class="text-2xl font-bold text-green-600"><?= formatCurrency($monthlyIncome) ?></p>
                    </div>
                </div>
            </div>

            <!-- Despesas do Mês -->
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="p-2 bg-red-100 rounded-lg">
                        <i class="fas fa-arrow-down text-red-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Despesas do Mês</p>
                        <p class="text-2xl font-bold text-red-600"><?= formatCurrency($monthlyExpenses) ?></p>
                    </div>
                </div>
            </div>

            <!-- Saldo do Mês -->
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="p-2 <?= $monthlyBalance >= 0 ? 'bg-green-100' : 'bg-red-100' ?> rounded-lg">
                        <i class="fas fa-balance-scale <?= $monthlyBalance >= 0 ? 'text-green-600' : 'text-red-600' ?> text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Saldo do Mês</p>
                        <p class="text-2xl font-bold <?= $monthlyBalance >= 0 ? 'text-green-600' : 'text-red-600' ?>">
                            <?= formatCurrency($monthlyBalance) ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Previsto vs Realizado (expansível) -->
        <div class="bg-white rounded-lg shadow mb-8">
            <details class="group">
                <summary class="p-6 cursor-pointer flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 select-none">
                    <span class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-chart-line mr-2"></i>
                        Previsto vs Realizado (Mês Atual)
                    </span>
                    <span class="text-gray-500 text-sm group-open:hidden">Clique para expandir</span>
                    <span class="text-gray-500 text-sm hidden group-open:inline">Clique para recolher</span>
                </summary>
                <div class="p-6 pt-0 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Previsto -->
                    <div class="rounded-lg border border-gray-200">
                        <div class="p-4 border-b border-gray-100 flex items-center justify-between">
                            <h3 class="text-md font-semibold text-gray-900">
                                <i class="fas fa-calendar-check mr-2"></i>
                                Previsto
                            </h3>
                        </div>
                        <div class="p-4 space-y-3">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Receitas do Mês</span>
                                <span class="font-semibold text-green-600"><?= formatCurrency($monthlyIncome) ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Despesas do Mês</span>
                                <span class="font-semibold text-red-600"><?= formatCurrency($monthlyExpenses) ?></span>
                            </div>
                            <div class="flex justify-between border-t pt-3">
                                <span class="font-medium">Saldo do Mês</span>
                                <span class="font-bold <?= $monthlyBalance >= 0 ? 'text-green-600' : 'text-red-600' ?>">
                                    <?= formatCurrency($monthlyBalance) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Realizado -->
                    <div class="rounded-lg border border-gray-200">
                        <div class="p-4 border-b border-gray-100 flex items-center justify-between">
                            <h3 class="text-md font-semibold text-gray-900">
                                <i class="fas fa-check-circle mr-2"></i>
                                Realizado
                            </h3>
                        </div>
                        <div class="p-4 space-y-3">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Receitas Recebidas</span>
                                <span class="font-semibold text-green-600"><?= formatCurrency($monthlyIncomeRealized) ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Despesas Pagas</span>
                                <span class="font-semibold text-red-600"><?= formatCurrency($monthlyExpensesRealized) ?></span>
                            </div>
                            <div class="flex justify-between border-t pt-3">
                                <span class="font-medium">Saldo Realizado</span>
                                <span class="font-bold <?= $monthlyBalanceRealized >= 0 ? 'text-green-600' : 'text-red-600' ?>">
                                    <?= formatCurrency($monthlyBalanceRealized) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </details>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Alertas de Contas -->
            <div class="space-y-6">
                <div class="bg-white rounded-lg shadow">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-blue-600">
                            <i class="fas fa-calendar-alt mr-2"></i>
                            Acesso Rápido
                        </h3>
                    </div>
                    <div class="p-6">
                        <p class="text-gray-600 mb-4 text-sm sm:text-base">Acesso rápido às contas por período:</p>
                        <div class="flex flex-wrap gap-3">
                            <a href="<?= htmlspecialchars($currentMonthUrl) ?>" class="inline-flex items-center justify-center w-full sm:w-auto bg-blue-600 hover:bg-blue-700 text-white px-4 py-3 rounded-md text-base" aria-label="Contas do mês atual">
                                <i class="fas fa-calendar-day mr-2"></i>
                                Contas do Mês Atual
                            </a>
                            <a href="<?= htmlspecialchars($nextMonthUrl) ?>" class="inline-flex items-center justify-center w-full sm:w-auto bg-blue-500 hover:bg-blue-600 text-white px-4 py-3 rounded-md text-base" aria-label="Contas do próximo mês">
                                <i class="fas fa-calendar-plus mr-2"></i>
                                Contas do Próximo Mês
                            </a>
                        </div>
                    </div>
                </div>
                <!-- Contas Vencidas -->
                <?php if (count($overdueAccounts) > 0): ?>
                <div class="bg-white rounded-lg shadow">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-red-600">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            Contas Vencidas (<?= count($overdueAccounts) ?>)
                        </h3>
                    </div>
                    <div class="p-6">
                        <div class="space-y-3">
                            <?php foreach ($overdueAccounts as $account): ?>
                            <div class="flex items-center justify-between p-3 bg-red-50 rounded-lg border-l-4 <?= $account['type'] == 'receita' ? 'border-green-500' : 'border-red-500' ?>">
                                <div class="flex-1">
                                    <div class="flex items-center mb-1">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium mr-2 <?= $account['type'] == 'receita' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                            <i class="fas <?= $account['type'] == 'receita' ? 'fa-arrow-down' : 'fa-arrow-up' ?> mr-1"></i>
                                            <?= $account['type'] == 'receita' ? 'A Receber' : 'A Pagar' ?>
                                        </span>
                                    </div>
                                    <p class="font-medium text-gray-900"><?= htmlspecialchars($account['description']) ?></p>
                                    <?php if (!empty($account['name'])): ?>
                                    <p class="text-xs text-gray-500"><?= htmlspecialchars($account['name']) ?></p>
                                    <?php endif; ?>
                                    <p class="text-sm text-gray-600"><?= formatDate($account['due_date']) ?></p>
                                </div>
                                <div class="text-right">
                                    <p class="font-bold <?= $account['type'] == 'receita' ? 'text-green-600' : 'text-red-600' ?>"><?= formatCurrency($account['amount']) ?></p>
                                    <span class="text-xs bg-red-100 text-red-800 px-2 py-1 rounded"><?= ucfirst($account['status']) ?></span>
                                    <form method="POST" action="index.php" class="mt-2 inline-block">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="id" value="<?= $account['id'] ?>">
                                        <input type="hidden" name="status" value="<?= $account['type'] == 'despesa' ? 'paga' : 'recebida' ?>">
                                        <button type="submit" class="text-xs px-3 py-1 rounded-md border <?= $account['type'] == 'despesa' ? 'border-red-300 text-red-700 hover:bg-red-50' : 'border-green-300 text-green-700 hover:bg-green-50' ?>">
                                            <i class="fas <?= $account['type'] == 'despesa' ? 'fa-check' : 'fa-check' ?> mr-1"></i>
                                            <?= $account['type'] == 'despesa' ? 'Marcar como Paga' : 'Marcar como Recebida' ?>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Próximos 7 dias -->
                <?php if (count($upcomingWeek) > 0): ?>
                <div class="bg-white rounded-lg shadow">
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 mb-4">
                            <h3 class="text-lg font-medium text-yellow-600">
                                <i class="fas fa-clock mr-2"></i>
                                Vencendo em 7 dias (<span id="week-count"><?= count($upcomingWeek) ?></span>)
                            </h3>
                            <div class="flex flex-wrap gap-2 sm:flex-nowrap sm:space-x-2">
                                <button onclick="filterWeekAccounts('all')" id="week-filter-all" class="px-4 py-2 text-sm rounded-md bg-gray-200 text-gray-700 hover:bg-gray-300 active min-w-[112px]">Todas</button>
                                <button onclick="filterWeekAccounts('receita')" id="week-filter-receita" class="px-4 py-2 text-sm rounded-md bg-gray-100 text-gray-600 hover:bg-green-100 hover:text-green-700 min-w-[112px]">A Receber</button>
                                <button onclick="filterWeekAccounts('despesa')" id="week-filter-despesa" class="px-4 py-2 text-sm rounded-md bg-gray-100 text-gray-600 hover:bg-red-100 hover:text-red-700 min-w-[112px]">A Pagar</button>
                            </div>
                        </div>
                    </div>
                    <div class="p-6">
                        <div class="space-y-3" id="week-accounts-container">
                            <?php foreach ($upcomingWeek as $account): ?>
                            <div class="flex items-center justify-between p-3 bg-yellow-50 rounded-lg week-account" data-type="<?= $account['type'] ?>">
                                <div>
                                    <p class="font-medium text-gray-900"><?= htmlspecialchars($account['description']) ?></p>
                                    <?php if (!empty($account['name'])): ?>
                                    <p class="text-xs text-gray-500"><?= htmlspecialchars($account['name']) ?></p>
                                    <?php endif; ?>
                                    <p class="text-sm text-gray-600"><?= formatDate($account['due_date']) ?></p>
                                </div>
                                <div class="text-right">
                                    <p class="font-bold <?= $account['type'] == 'receita' ? 'text-green-600' : 'text-red-600' ?>">
                                        <?= formatCurrency($account['amount']) ?>
                                    </p>
                                    <span class="text-xs bg-yellow-100 text-yellow-800 px-2 py-1 rounded"><?= ucfirst($account['status']) ?></span>
                                    <form method="POST" action="index.php" class="mt-2 inline-block">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="id" value="<?= $account['id'] ?>">
                                        <input type="hidden" name="status" value="<?= $account['type'] == 'despesa' ? 'paga' : 'recebida' ?>">
                                        <button type="submit" class="text-xs px-3 py-1 rounded-md border <?= $account['type'] == 'despesa' ? 'border-red-300 text-red-700 hover:bg-red-50' : 'border-green-300 text-green-700 hover:bg-green-50' ?>">
                                            <i class="fas <?= $account['type'] == 'despesa' ? 'fa-check' : 'fa-check' ?> mr-1"></i>
                                            <?= $account['type'] == 'despesa' ? 'Marcar como Paga' : 'Marcar como Recebida' ?>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Resto do mês (8º dia até fim do mês) -->
                <?php if (count($upcomingRestOfMonth) > 0): ?>
                <div class="bg-white rounded-lg shadow">
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 mb-4">
                            <h3 class="text-lg font-medium text-blue-600">
                                <i class="fas fa-calendar-week mr-2"></i>
                                Vencendo até o fim do mês (<span id="month-count"><?= count($upcomingRestOfMonth) ?></span>)
                            </h3>
                            <div class="flex flex-wrap gap-2 sm:flex-nowrap sm:space-x-2">
                                <button onclick="filterMonthAccounts('all')" id="month-filter-all" class="px-4 py-2 text-sm rounded-md bg-gray-200 text-gray-700 hover:bg-gray-300 active min-w-[112px]">Todas</button>
                                <button onclick="filterMonthAccounts('receita')" id="month-filter-receita" class="px-4 py-2 text-sm rounded-md bg-gray-100 text-gray-600 hover:bg-green-100 hover:text-green-700 min-w-[112px]">A Receber</button>
                                <button onclick="filterMonthAccounts('despesa')" id="month-filter-despesa" class="px-4 py-2 text-sm rounded-md bg-gray-100 text-gray-600 hover:bg-red-100 hover:text-red-700 min-w-[112px]">A Pagar</button>
                            </div>
                        </div>
                    </div>
                    <div class="p-6">
                        <div class="space-y-3" id="month-accounts-container">
                            <?php foreach ($upcomingRestOfMonth as $account): ?>
                            <div class="flex items-center justify-between p-3 bg-blue-50 rounded-lg month-account" data-type="<?= $account['type'] ?>">
                                <div>
                                    <p class="font-medium text-gray-900"><?= htmlspecialchars($account['description']) ?></p>
                                    <p class="text-sm text-gray-600"><?= formatDate($account['due_date']) ?></p>
                                </div>
                                <div class="text-right">
                                    <p class="font-bold <?= $account['type'] == 'receita' ? 'text-green-600' : 'text-red-600' ?>">
                                        <?= formatCurrency($account['amount']) ?>
                                    </p>
                                    <span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded"><?= ucfirst($account['status']) ?></span>
                                    <form method="POST" action="index.php" class="mt-2 inline-block">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="id" value="<?= $account['id'] ?>">
                                        <input type="hidden" name="status" value="<?= $account['type'] == 'despesa' ? 'paga' : 'recebida' ?>">
                                        <button type="submit" class="text-xs px-3 py-1 rounded-md border <?= $account['type'] == 'despesa' ? 'border-red-300 text-red-700 hover:bg-red-50' : 'border-green-300 text-green-700 hover:bg-green-50' ?>">
                                            <i class="fas <?= $account['type'] == 'despesa' ? 'fa-check' : 'fa-check' ?> mr-1"></i>
                                            <?= $account['type'] == 'despesa' ? 'Marcar como Paga' : 'Marcar como Recebida' ?>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Últimas Transações -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">
                        <i class="fas fa-history mr-2"></i>
                        Últimas Transações
                    </h3>
                </div>
                <div class="p-6">
                    <?php if (count($recentTransactions) > 0): ?>
                    <div class="space-y-3">
                        <?php foreach ($recentTransactions as $transaction): ?>
                        <div class="flex items-center justify-between p-3 border border-gray-200 rounded-lg">
                            <div class="flex items-center">
                                <div class="p-2 <?= $transaction['type'] == 'receita' ? 'bg-green-100' : 'bg-red-100' ?> rounded-lg mr-3">
                                    <i class="fas <?= $transaction['type'] == 'receita' ? 'fa-arrow-up text-green-600' : 'fa-arrow-down text-red-600' ?>"></i>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-900"><?= htmlspecialchars($transaction['description']) ?></p>
                                    <div class="flex items-center space-x-2">
                                        <p class="text-sm text-gray-600"><?= htmlspecialchars($transaction['category_name']) ?> • <?= formatDate($transaction['due_date']) ?></p>
                                        <?php if (!empty($transaction['attachment'])): ?>
                                            <?php 
                                            $fileExtension = strtolower(pathinfo($transaction['attachment'], PATHINFO_EXTENSION));
                                            ?>
                                            <a href="javascript:void(0)" onclick="openLightbox('<?= htmlspecialchars($transaction['attachment']) ?>')" 
                                               class="inline-flex items-center text-blue-600 hover:text-blue-800 text-xs" title="Ver comprovante">
                                                <?php if ($fileExtension === 'pdf'): ?>
                                                    <i class="fas fa-file-pdf text-red-500"></i>
                                                <?php elseif (in_array($fileExtension, ['jpg', 'jpeg', 'png'])): ?>
                                                    <i class="fas fa-file-image text-green-500"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-file text-gray-500"></i>
                                                <?php endif; ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="font-bold <?= $transaction['type'] == 'receita' ? 'text-green-600' : 'text-red-600' ?>">
                                    <?= formatCurrency($transaction['amount']) ?>
                                </p>
                                <span class="text-xs px-2 py-1 rounded <?= 
                                    $transaction['status'] == 'paga' || $transaction['status'] == 'recebida' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' 
                                ?>">
                                    <?= ucfirst($transaction['status']) ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-gray-500 text-center py-8">Nenhuma transação encontrada</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Botão de Ação Rápida -->
        <div class="fixed bottom-6 right-6">
            <a href="accounts.php?action=add" class="bg-primary hover:bg-blue-700 text-white rounded-full p-4 shadow-lg transition-colors" aria-label="Adicionar conta">
                <i class="fas fa-plus text-xl"></i>
            </a>
        </div>
    </main>

    <!-- Lightbox Modal -->
    <div id="lightbox" class="fixed inset-0 bg-black bg-opacity-75 hidden z-50 flex items-center justify-center">
        <div class="relative max-w-4xl max-h-full w-full h-full flex items-center justify-center p-4">
            <!-- Botão de fechar -->
            <button onclick="closeLightbox()" class="absolute top-4 right-4 text-white hover:text-gray-300 z-10">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
            
            <!-- Conteúdo do lightbox -->
            <div id="lightbox-content" class="w-full h-full flex items-center justify-center">
                <!-- Conteúdo será inserido dinamicamente -->
            </div>
        </div>
    </div>

    <style>
        #lightbox {
            backdrop-filter: blur(4px);
        }
        
        #lightbox iframe {
            max-width: 90vw;
            max-height: 90vh;
            width: 100%;
            height: 100%;
            border: none;
            border-radius: 8px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        
        #lightbox img {
            max-width: 90vw;
            max-height: 90vh;
            object-fit: contain;
            border-radius: 8px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        
        .lightbox-loading {
            color: white;
            font-size: 18px;
            text-align: center;
        }
    </style>

    <script>
        // Funções do Lightbox
        function openLightbox(filePath) {
            const lightbox = document.getElementById('lightbox');
            const content = document.getElementById('lightbox-content');
            
            // Mostrar loading
            content.innerHTML = '<div class="lightbox-loading">Carregando...</div>';
            lightbox.classList.remove('hidden');
            
            // Detectar tipo de arquivo
            const fileExtension = filePath.split('.').pop().toLowerCase();
            const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg'];
            const pdfExtensions = ['pdf'];
            
            if (imageExtensions.includes(fileExtension)) {
                // Exibir imagem
                content.innerHTML = `<img src="${filePath}" alt="Comprovante" onload="this.style.opacity=1" style="opacity:0; transition: opacity 0.3s;">`;
            } else if (pdfExtensions.includes(fileExtension)) {
                // Exibir PDF em iframe
                content.innerHTML = `<iframe src="${filePath}" onload="this.style.opacity=1" style="opacity:0; transition: opacity 0.3s;"></iframe>`;
            } else {
                // Outros tipos de arquivo - tentar iframe
                content.innerHTML = `<iframe src="${filePath}" onload="this.style.opacity=1" style="opacity:0; transition: opacity 0.3s;"></iframe>`;
            }
        }
        
        function closeLightbox() {
            const lightbox = document.getElementById('lightbox');
            lightbox.classList.add('hidden');
            
            // Limpar conteúdo após animação
            setTimeout(() => {
                document.getElementById('lightbox-content').innerHTML = '';
            }, 300);
        }
        
        // Fechar lightbox ao clicar fora do conteúdo
        document.addEventListener('DOMContentLoaded', function() {
            const lightbox = document.getElementById('lightbox');
            lightbox.addEventListener('click', function(e) {
                if (e.target === lightbox) {
                    closeLightbox();
                }
            });
            
            // Fechar com ESC
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && !lightbox.classList.contains('hidden')) {
                    closeLightbox();
                }
            });
        });

        // Função para filtrar contas da seção "Vencendo em 7 dias"
        function filterWeekAccounts(type) {
            const accounts = document.querySelectorAll('.week-account');
            const buttons = document.querySelectorAll('[id^="week-filter-"]');
            const counter = document.getElementById('week-count');
            let visibleCount = 0;

            // Atualizar botões
            buttons.forEach(btn => {
                btn.classList.remove('bg-gray-200', 'bg-green-100', 'bg-red-100', 'text-gray-700', 'text-green-700', 'text-red-700', 'active');
                btn.classList.add('bg-gray-100', 'text-gray-600');
            });

            const activeButton = document.getElementById(`week-filter-${type}`);
            if (type === 'all') {
                activeButton.classList.remove('bg-gray-100', 'text-gray-600');
                activeButton.classList.add('bg-gray-200', 'text-gray-700', 'active');
            } else if (type === 'receita') {
                activeButton.classList.remove('bg-gray-100', 'text-gray-600');
                activeButton.classList.add('bg-green-100', 'text-green-700');
            } else if (type === 'despesa') {
                activeButton.classList.remove('bg-gray-100', 'text-gray-600');
                activeButton.classList.add('bg-red-100', 'text-red-700');
            }

            // Filtrar contas
            accounts.forEach(account => {
                const accountType = account.getAttribute('data-type');
                if (type === 'all' || accountType === type) {
                    account.style.display = 'flex';
                    visibleCount++;
                } else {
                    account.style.display = 'none';
                }
            });

            // Atualizar contador
            counter.textContent = visibleCount;
        }

        // Função para filtrar contas da seção "Vencendo até o fim do mês"
        function filterMonthAccounts(type) {
            const accounts = document.querySelectorAll('.month-account');
            const buttons = document.querySelectorAll('[id^="month-filter-"]');
            const counter = document.getElementById('month-count');
            let visibleCount = 0;

            // Atualizar botões
            buttons.forEach(btn => {
                btn.classList.remove('bg-gray-200', 'bg-green-100', 'bg-red-100', 'text-gray-700', 'text-green-700', 'text-red-700', 'active');
                btn.classList.add('bg-gray-100', 'text-gray-600');
            });

            const activeButton = document.getElementById(`month-filter-${type}`);
            if (type === 'all') {
                activeButton.classList.remove('bg-gray-100', 'text-gray-600');
                activeButton.classList.add('bg-gray-200', 'text-gray-700', 'active');
            } else if (type === 'receita') {
                activeButton.classList.remove('bg-gray-100', 'text-gray-600');
                activeButton.classList.add('bg-green-100', 'text-green-700');
            } else if (type === 'despesa') {
                activeButton.classList.remove('bg-gray-100', 'text-gray-600');
                activeButton.classList.add('bg-red-100', 'text-red-700');
            }

            // Filtrar contas
            accounts.forEach(account => {
                const accountType = account.getAttribute('data-type');
                if (type === 'all' || accountType === type) {
                    account.style.display = 'flex';
                    visibleCount++;
                } else {
                    account.style.display = 'none';
                }
            });

            // Atualizar contador
            counter.textContent = visibleCount;
        }

        // Atualizar dados a cada 5 minutos
        setInterval(() => {
            location.reload();
        }, 300000);
    </script>
</body>
</html>