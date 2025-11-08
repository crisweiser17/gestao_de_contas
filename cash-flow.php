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

// Períodos disponíveis para projeção
$periods = [
    30 => '1 mês',
    60 => '2 meses', 
    90 => '3 meses',
    180 => '6 meses',
    365 => '1 ano'
];

$selectedPeriod = $_GET['period'] ?? 90;
$selectedPeriod = in_array($selectedPeriod, array_keys($periods)) ? $selectedPeriod : 90;

// Ajustar período para incluir o fim do mês do último mês do intervalo
$today = date('Y-m-d');
$endCandidate = date('Y-m-d', strtotime("+{$selectedPeriod} days"));
$endMonthLastDay = date('Y-m-t', strtotime($endCandidate));
$daysAdjusted = (int) floor((strtotime($endMonthLastDay) - strtotime($today)) / 86400);
if ($daysAdjusted > $selectedPeriod) {
    $selectedPeriod = $daysAdjusted;
}

// Calcular projeções
function calculateCashFlowProjection($userId, $days) {
    global $accountModel, $recurringModel;
    
    $startDate = date('Y-m-d');
    $endDate = date('Y-m-d', strtotime("+{$days} days"));
    
    // Buscar saldo atual (contas já pagas/recebidas)
    $currentBalance = $accountModel->getCurrentBalance($userId);
    
    // Buscar TODAS as contas no período (pendentes, pagas e recebidas)
    $allAccountsInPeriod = $accountModel->getWithFilters($userId, [
        'date_from' => $startDate,
        'date_to' => $endDate
    ]);
    
    // Gerar contas recorrentes para o período
    $recurringAccounts = $recurringModel->generateProjections($userId, $days);
    
    // Combinar contas do período e recorrentes
    $allAccounts = array_merge($allAccountsInPeriod, $recurringAccounts);
    
    // Organizar por data
    usort($allAccounts, function($a, $b) {
        return strtotime($a['due_date']) - strtotime($b['due_date']);
    });
    
    // Calcular fluxo dia a dia
    $dailyFlow = [];
    $runningBalance = $currentBalance;
    $currentDate = $startDate;
    
    while ($currentDate <= $endDate) {
        $dayAccounts = array_filter($allAccounts, function($account) use ($currentDate) {
            return $account['due_date'] == $currentDate;
        });
        
        $dayIncome = 0;
        $dayExpense = 0;
        
        foreach ($dayAccounts as $account) {
            if ($account['type'] == 'receita') {
                $dayIncome += $account['amount'];
            } else {
                $dayExpense += $account['amount'];
            }
        }
        
        $dayBalance = $dayIncome - $dayExpense;
        $runningBalance += $dayBalance;
        
        $dailyFlow[] = [
            'date' => $currentDate,
            'income' => $dayIncome,
            'expense' => $dayExpense,
            'balance' => $dayBalance,
            'running_balance' => $runningBalance,
            'accounts' => $dayAccounts
        ];
        
        $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
    }
    
    return [
        'current_balance' => $currentBalance,
        'daily_flow' => $dailyFlow,
        'summary' => [
            'total_income' => array_sum(array_column($dailyFlow, 'income')),
            'total_expense' => array_sum(array_column($dailyFlow, 'expense')),
            'final_balance' => end($dailyFlow)['running_balance'] ?? $currentBalance,
            'lowest_balance' => min(array_column($dailyFlow, 'running_balance')),
            'highest_balance' => max(array_column($dailyFlow, 'running_balance'))
        ]
    ];
}

$projection = calculateCashFlowProjection($userId, $selectedPeriod);

// Agrupar por mês para visualização resumida
function groupByMonth($dailyFlow) {
    $months = [];
    $currentMonth = null;
    
    foreach ($dailyFlow as $day) {
        $monthKey = date('Y-m', strtotime($day['date']));
        
        if ($monthKey !== $currentMonth) {
            $currentMonth = $monthKey;
            $monthStart = date('Y-m-01', strtotime($day['date']));
            $monthEnd = date('Y-m-t', strtotime($day['date']));
            
            $months[$monthKey] = [
                'start_date' => $monthStart,
                'end_date' => $monthEnd,
                'income' => 0,
                'expense' => 0,
                'balance' => 0,
                'final_balance' => 0,
                'days' => []
            ];
        }
        
        $months[$monthKey]['income'] += $day['income'];
        $months[$monthKey]['expense'] += $day['expense'];
        $months[$monthKey]['balance'] += $day['balance'];
        $months[$monthKey]['final_balance'] = $day['running_balance'];
        $months[$monthKey]['days'][] = $day;
    }
    
    return $months;
}

$monthlyFlow = groupByMonth($projection['daily_flow']);

// Formatar mês e ano em PT-BR (ex.: Outubro 2025)
function formatMonthYear($date) {
    $months = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
    ];
    $timestamp = strtotime($date);
    $month = (int)date('n', $timestamp);
    $year = date('Y', $timestamp);
    return $months[$month] . ' ' . $year;
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Fluxo de Caixa</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
    <?php require_once 'partials/header.php'; render_header('cash-flow'); ?>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Seletor de Período -->
        <div class="bg-white rounded-lg shadow mb-8">
            <div class="p-6 border-b border-gray-200">
                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
                    <h2 class="text-xl font-semibold text-gray-900">
                        <i class="fas fa-chart-area mr-2"></i>
                        Projeção de Fluxo de Caixa
                    </h2>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($periods as $days => $label): ?>
                        <a href="?period=<?= $days ?>" 
                           class="px-4 py-2 rounded-md transition-colors text-sm <?= $selectedPeriod == $days ? 'bg-primary text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">
                            <?= $label ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Resumo -->
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="text-center">
                        <div class="text-2xl font-bold text-green-600"><?= formatCurrency($projection['summary']['total_income']) ?></div>
                        <div class="text-sm text-gray-600">Receitas Previstas</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-red-600"><?= formatCurrency($projection['summary']['total_expense']) ?></div>
                        <div class="text-sm text-gray-600">Despesas Previstas</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold <?= $projection['summary']['final_balance'] >= 0 ? 'text-green-600' : 'text-red-600' ?>">
                            <?= formatCurrency($projection['summary']['final_balance']) ?>
                        </div>
                        <div class="text-sm text-gray-600">Saldo Final</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Gráfico -->
        <div class="bg-white rounded-lg shadow mb-8">
            <div class="p-6 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-chart-line mr-2"></i>
                    Evolução do Saldo
                </h3>
            </div>
            <div class="p-6">
                <canvas id="cashFlowChart" height="300"></canvas>
            </div>
        </div>

        <!-- Visão Mensal -->
        <div class="bg-white rounded-lg shadow mb-8">
            <div class="p-6 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-calendar-days mr-2"></i>
                    Resumo Mensal
                </h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Período</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Receitas</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Despesas</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Saldo Mensal</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Saldo Final</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($monthlyFlow as $month): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?= htmlspecialchars(formatMonthYear($month['start_date'])) ?>
                            </td>
                            <td class="px-6 py-4 text-sm font-medium text-green-600">
                                <?= formatCurrency($month['income']) ?>
                            </td>
                            <td class="px-6 py-4 text-sm font-medium text-red-600">
                                <?= formatCurrency($month['expense']) ?>
                            </td>
                            <td class="px-6 py-4 text-sm font-medium <?= $month['balance'] >= 0 ? 'text-green-600' : 'text-red-600' ?>">
                                <?= formatCurrency($month['balance']) ?>
                            </td>
                            <td class="px-6 py-4 text-sm font-medium <?= $month['final_balance'] >= 0 ? 'text-green-600' : 'text-red-600' ?>">
                                <?= formatCurrency($month['final_balance']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Detalhamento Diário -->
        <div class="bg-white rounded-lg shadow">
            <div class="p-6 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-calendar-day mr-2"></i>
                    Detalhamento Diário
                </h3>
                <p class="text-sm text-gray-600 mt-1">Movimentações previstas por dia no período selecionado</p>
            </div>
            <div class="hidden md:block overflow-y-auto max-h-[70vh] md:max-h-96">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50 sticky top-0">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Descrição</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell">Categoria</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tipo</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Valor</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell">Saldo Acumulado</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php 
                        $previousBalance = $projection['current_balance'];
                        foreach ($projection['daily_flow'] as $day): 
                        ?>
                            <?php if (!empty($day['accounts'])): ?>
                                <!-- Cabeçalho do dia -->
                                <tr class="bg-blue-50 border-t-2 border-blue-200">
                                    <td colspan="6" class="px-6 py-3">
                                        <div class="flex flex-col md:flex-row md:justify-between md:items-center gap-2">
                                            <h4 class="font-semibold text-blue-900"><?= formatDate($day['date']) ?></h4>
                                            <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs md:text-sm">
                                                <span class="text-gray-600">Saldo Inicial: <?= formatCurrency($previousBalance) ?></span>
                                                <?php if ($day['income'] > 0): ?>
                                                <span class="text-green-600">Receitas: +<?= formatCurrency($day['income']) ?></span>
                                                <?php endif; ?>
                                                <?php if ($day['expense'] > 0): ?>
                                                <span class="text-red-600">Despesas: -<?= formatCurrency($day['expense']) ?></span>
                                                <?php endif; ?>
                                                <span class="font-medium <?= $day['running_balance'] >= 0 ? 'text-green-600' : 'text-red-600' ?>">
                                                    Saldo Final: <?= formatCurrency($day['running_balance']) ?>
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                
                                <!-- Movimentações do dia -->
                                <?php foreach ($day['accounts'] as $account): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-400 hidden md:table-cell">
                                        <!-- Data vazia para as linhas de movimentação -->
                                    </td>
                                    <td class="px-4 py-2 md:px-6 md:py-3 text-sm text-gray-900 whitespace-normal md:whitespace-nowrap">
                                        <div class="flex items-center">
                                            <?php if (isset($account['is_projection']) && $account['is_projection']): ?>
                                            <i class="fas fa-sync-alt text-blue-500 mr-2" title="Conta recorrente projetada"></i>
                                            <?php endif; ?>
                                            <?= htmlspecialchars($account['description']) ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-2 md:px-6 md:py-3 whitespace-nowrap text-sm text-gray-500 hidden md:table-cell">
                                        <?= htmlspecialchars($account['category_name'] ?? 'Sem categoria') ?>
                                    </td>
                                    <td class="px-4 py-2 md:px-6 md:py-3 whitespace-nowrap text-sm">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $account['type'] == 'receita' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                            <?= $account['type'] == 'receita' ? 'Receita' : 'Despesa' ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 md:px-6 md:py-3 whitespace-nowrap text-sm text-right font-medium <?= $account['type'] == 'receita' ? 'text-green-600' : 'text-red-600' ?>">
                                        <?= $account['type'] == 'receita' ? '+' : '-' ?><?= formatCurrency($account['amount']) ?>
                                    </td>
                                    <td class="px-4 py-2 md:px-6 md:py-3 whitespace-nowrap text-sm text-right text-gray-400 hidden md:table-cell">
                                        <!-- Saldo vazio para as linhas de movimentação -->
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <?php $previousBalance = $day['running_balance']; ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="block md:hidden">
                <?php 
                $prevBalMobile = $projection['current_balance'];
                foreach ($projection['daily_flow'] as $day): 
                    if (!empty($day['accounts'])): ?>
                    <div class="bg-blue-50 border-t-2 border-blue-200 px-4 py-3">
                        <div class="flex flex-col gap-1">
                            <div class="flex items-center justify-between">
                                <h4 class="font-semibold text-blue-900 text-sm"><?= formatDate($day['date']) ?></h4>
                                <span class="font-medium <?= $day['running_balance'] >= 0 ? 'text-green-600' : 'text-red-600' ?> text-sm">
                                    <?= formatCurrency($day['running_balance']) ?>
                                </span>
                            </div>
                            <div class="flex flex-wrap gap-x-3 gap-y-1 text-xs">
                                <span class="text-gray-600">Inicial: <?= formatCurrency($prevBalMobile) ?></span>
                                <?php if ($day['income'] > 0): ?>
                                <span class="text-green-600">Receitas: +<?= formatCurrency($day['income']) ?></span>
                                <?php endif; ?>
                                <?php if ($day['expense'] > 0): ?>
                                <span class="text-red-600">Despesas: -<?= formatCurrency($day['expense']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php foreach ($day['accounts'] as $account): ?>
                    <div class="px-4 py-3 border-b bg-white">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center text-sm text-gray-900">
                                    <?php if (isset($account['is_projection']) && $account['is_projection']): ?>
                                    <i class="fas fa-sync-alt text-blue-500 mr-2" title="Conta recorrente projetada"></i>
                                    <?php endif; ?>
                                    <span class="truncate">
                                        <?= htmlspecialchars($account['description']) ?>
                                    </span>
                                </div>
                                <div class="text-xs text-gray-500 mt-0.5">
                                    <?= htmlspecialchars($account['category_name'] ?? 'Sem categoria') ?>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-sm font-medium <?= $account['type'] == 'receita' ? 'text-green-600' : 'text-red-600' ?>">
                                    <?= $account['type'] == 'receita' ? '+' : '-' ?><?= formatCurrency($account['amount']) ?>
                                </div>
                                <div class="mt-1">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium <?= $account['type'] == 'receita' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                        <?= $account['type'] == 'receita' ? 'Receita' : 'Despesa' ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php $prevBalMobile = $day['running_balance']; ?>
                <?php endif; endforeach; ?>
            </div>
        </div>
    </main>

    <script>
        // Dados para o gráfico
        const chartData = {
            labels: [
                <?php foreach ($projection['daily_flow'] as $day): ?>
                '<?= date('d/m', strtotime($day['date'])) ?>',
                <?php endforeach; ?>
            ],
            datasets: [{
                label: 'Saldo',
                data: [
                    <?php foreach ($projection['daily_flow'] as $day): ?>
                    <?= $day['running_balance'] ?>,
                    <?php endforeach; ?>
                ],
                borderColor: '#1e40af',
                backgroundColor: 'rgba(30, 64, 175, 0.1)',
                fill: true,
                tension: 0.4
            }]
        };

        // Configuração do gráfico
        const config = {
            type: 'line',
            data: chartData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: false,
                        ticks: {
                            callback: function(value) {
                                return 'R$ ' + value.toLocaleString('pt-BR', {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2
                                });
                            }
                        }
                    }
                },
                elements: {
                    point: {
                        radius: 2,
                        hoverRadius: 6
                    }
                }
            }
        };

        // Criar gráfico
        const ctx = document.getElementById('cashFlowChart').getContext('2d');
        new Chart(ctx, config);
    </script>
</body>
</html>