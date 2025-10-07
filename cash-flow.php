<?php
require_once 'config/config.php';

// Se não estiver logado, redireciona para login
if (!isLoggedIn()) {
    redirect('login.php');
}

require_once 'models/Account.php';
require_once 'models/Category.php';
require_once 'models/RecurringSetting.php';

$accountModel = new Account();
$categoryModel = new Category($pdo);
$recurringModel = new RecurringSetting();

$userId = $_SESSION['user_id'];

// Períodos disponíveis para projeção
$periods = [
    30 => '30 dias',
    60 => '60 dias', 
    90 => '90 dias',
    180 => '180 dias',
    365 => '1 ano'
];

$selectedPeriod = $_GET['period'] ?? 90;
$selectedPeriod = in_array($selectedPeriod, array_keys($periods)) ? $selectedPeriod : 90;

// Calcular projeções
function calculateCashFlowProjection($userId, $days) {
    global $accountModel, $recurringModel;
    
    $startDate = date('Y-m-d');
    $endDate = date('Y-m-d', strtotime("+{$days} days"));
    
    // Buscar saldo atual (contas já pagas/recebidas)
    $currentBalance = $accountModel->getCurrentBalance($userId);
    
    // Buscar contas pendentes no período
    $pendingAccounts = $accountModel->getWithFilters($userId, [
        'status' => 'pendente',
        'date_from' => $startDate,
        'date_to' => $endDate
    ]);
    
    // Gerar contas recorrentes para o período
    $recurringAccounts = $recurringModel->generateProjections($userId, $days);
    
    // Combinar contas pendentes e recorrentes
    $allAccounts = array_merge($pendingAccounts, $recurringAccounts);
    
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
                <div class="flex justify-between items-center">
                    <h2 class="text-xl font-semibold text-gray-900">
                        <i class="fas fa-chart-area mr-2"></i>
                        Projeção de Fluxo de Caixa
                    </h2>
                    <div class="flex space-x-2">
                        <?php foreach ($periods as $days => $label): ?>
                        <a href="?period=<?= $days ?>" 
                           class="px-4 py-2 rounded-md transition-colors <?= $selectedPeriod == $days ? 'bg-primary text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">
                            <?= $label ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Resumo -->
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-5 gap-6">
                    <div class="text-center">
                        <div class="text-2xl font-bold text-blue-600"><?= formatCurrency($projection['current_balance']) ?></div>
                        <div class="text-sm text-gray-600">Saldo Atual</div>
                    </div>
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
                    <div class="text-center">
                        <div class="text-2xl font-bold <?= $projection['summary']['lowest_balance'] >= 0 ? 'text-green-600' : 'text-red-600' ?>">
                            <?= formatCurrency($projection['summary']['lowest_balance']) ?>
                        </div>
                        <div class="text-sm text-gray-600">Menor Saldo</div>
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
                <canvas id="cashFlowChart" height="100"></canvas>
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
            </div>
            <div class="max-h-96 overflow-y-auto">
                <?php foreach ($projection['daily_flow'] as $day): ?>
                <?php if (!empty($day['accounts'])): ?>
                <div class="border-b border-gray-100 p-4">
                    <div class="flex justify-between items-center mb-2">
                        <h4 class="font-medium text-gray-900"><?= formatDate($day['date']) ?></h4>
                        <div class="flex space-x-4 text-sm">
                            <?php if ($day['income'] > 0): ?>
                            <span class="text-green-600">+<?= formatCurrency($day['income']) ?></span>
                            <?php endif; ?>
                            <?php if ($day['expense'] > 0): ?>
                            <span class="text-red-600">-<?= formatCurrency($day['expense']) ?></span>
                            <?php endif; ?>
                            <span class="font-medium <?= $day['running_balance'] >= 0 ? 'text-green-600' : 'text-red-600' ?>">
                                Saldo: <?= formatCurrency($day['running_balance']) ?>
                            </span>
                        </div>
                    </div>
                    <div class="space-y-1">
                        <?php foreach ($day['accounts'] as $account): ?>
                        <div class="flex justify-between items-center text-sm">
                            <div class="flex items-center">
                                <?php if (isset($account['is_projection']) && $account['is_projection']): ?>
                                <i class="fas fa-sync-alt text-blue-500 mr-2" title="Conta recorrente projetada"></i>
                                <?php endif; ?>
                                <span class="text-gray-700"><?= htmlspecialchars($account['description']) ?></span>
                                <span class="text-gray-500 ml-2">(<?= htmlspecialchars($account['category_name'] ?? '') ?>)</span>
                            </div>
                            <span class="font-medium <?= $account['type'] == 'receita' ? 'text-green-600' : 'text-red-600' ?>">
                                <?= $account['type'] == 'receita' ? '+' : '-' ?><?= formatCurrency($account['amount']) ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php endforeach; ?>
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