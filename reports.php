<?php
require_once 'config/config.php';

// Se não estiver logado, redireciona para login
if (!isLoggedIn()) {
    redirect('login.php');
}

require_once 'models/Account.php';
require_once 'models/Category.php';

$accountModel = new Account();
$categoryModel = new Category($pdo);

$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? 'view';

// Filtros padrão
$filters = [
    'date_from' => $_GET['date_from'] ?? date('Y-m-01'), // Primeiro dia do mês atual
    'date_to' => $_GET['date_to'] ?? date('Y-m-t'), // Último dia do mês atual
    'category_id' => $_GET['category_id'] ?? '',
    'type' => $_GET['type'] ?? '',
    'status' => $_GET['status'] ?? ''
];

// Buscar dados
$categories = $categoryModel->getByUserId($userId);
$accounts = $accountModel->getWithFilters($userId, array_filter($filters));

// Calcular totais
$totals = [
    'receitas' => 0,
    'despesas' => 0,
    'receitas_pagas' => 0,
    'despesas_pagas' => 0,
    'receitas_pendentes' => 0,
    'despesas_pendentes' => 0
];

foreach ($accounts as $account) {
    if ($account['type'] == 'receita') {
        $totals['receitas'] += $account['amount'];
        if ($account['status'] == 'recebida') {
            $totals['receitas_pagas'] += $account['amount'];
        } else {
            $totals['receitas_pendentes'] += $account['amount'];
        }
    } else {
        $totals['despesas'] += $account['amount'];
        if ($account['status'] == 'paga') {
            $totals['despesas_pagas'] += $account['amount'];
        } else {
            $totals['despesas_pendentes'] += $account['amount'];
        }
    }
}

$totals['saldo_total'] = $totals['receitas'] - $totals['despesas'];
$totals['saldo_realizado'] = $totals['receitas_pagas'] - $totals['despesas_pagas'];
$totals['saldo_pendente'] = $totals['receitas_pendentes'] - $totals['despesas_pendentes'];

// Agrupar por categoria
$byCategory = [];
foreach ($accounts as $account) {
    $catId = $account['category_id'];
    if (!isset($byCategory[$catId])) {
        $byCategory[$catId] = [
            'name' => $account['category_name'],
            'type' => $account['type'],
            'total' => 0,
            'paid' => 0,
            'pending' => 0,
            'count' => 0
        ];
    }
    
    $byCategory[$catId]['total'] += $account['amount'];
    $byCategory[$catId]['count']++;
    
    if (($account['type'] == 'receita' && $account['status'] == 'recebida') || 
        ($account['type'] == 'despesa' && $account['status'] == 'paga')) {
        $byCategory[$catId]['paid'] += $account['amount'];
    } else {
        $byCategory[$catId]['pending'] += $account['amount'];
    }
}

// Separar receitas e despesas em arrays diferentes
$receitas = [];
$despesas = [];

foreach ($byCategory as $category) {
    if ($category['type'] == 'receita') {
        $receitas[] = $category;
    } else {
        $despesas[] = $category;
    }
}

// Ordenar cada array alfabeticamente por nome da categoria
usort($receitas, function($a, $b) {
    return strcmp($a['name'], $b['name']);
});

usort($despesas, function($a, $b) {
    return strcmp($a['name'], $b['name']);
});

// Exportação CSV
if ($action == 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="relatorio_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // BOM para UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Cabeçalho
    fputcsv($output, [
        'Data',
        'Descrição',
        'Categoria',
        'Tipo',
        'Valor',
        'Status',
        'Vencimento',
        'URL'
    ], ';');
    
    // Dados
    foreach ($accounts as $account) {
        fputcsv($output, [
            date('d/m/Y', strtotime($account['due_date'])),
            $account['description'],
            $account['category_name'],
            ucfirst($account['type']),
            number_format($account['amount'], 2, ',', '.'),
            ucfirst($account['status']),
            date('d/m/Y', strtotime($account['due_date'])),
            $account['url']
        ], ';');
    }
    
    fclose($output);
    exit;
}

// Exportação PDF (usando HTML/CSS para impressão)
if ($action == 'export_pdf') {
    $html = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Relatório Financeiro</title>
        <style>
            body { font-family: Arial, sans-serif; font-size: 12px; }
            .header { text-align: center; margin-bottom: 20px; }
            .summary { margin-bottom: 20px; }
            .summary table { width: 100%; border-collapse: collapse; }
            .summary th, .summary td { border: 1px solid #ddd; padding: 8px; text-align: right; }
            .summary th { background-color: #f5f5f5; }
            .accounts table { width: 100%; border-collapse: collapse; font-size: 11px; }
            .accounts th, .accounts td { border: 1px solid #ddd; padding: 6px; }
            .accounts th { background-color: #f5f5f5; }
            .receita { color: green; }
            .despesa { color: red; }
            @media print {
                body { margin: 0; }
                .no-print { display: none; }
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>' . APP_NAME . '</h1>
            <h2>Relatório Financeiro</h2>
            <p>Período: ' . formatDate($filters['date_from']) . ' a ' . formatDate($filters['date_to']) . '</p>
            <p>Gerado em: ' . formatDate(date('Y-m-d')) . '</p>
        </div>
        
        <div class="summary">
            <h3>Resumo</h3>
            <table>
                <tr><th>Receitas Totais</th><td class="receita">R$ ' . number_format($totals['receitas'], 2, ',', '.') . '</td></tr>
                <tr><th>Despesas Totais</th><td class="despesa">R$ ' . number_format($totals['despesas'], 2, ',', '.') . '</td></tr>
                <tr><th>Saldo Total</th><td class="' . ($totals['saldo_total'] >= 0 ? 'receita' : 'despesa') . '">R$ ' . number_format($totals['saldo_total'], 2, ',', '.') . '</td></tr>
                <tr><th>Receitas Recebidas</th><td class="receita">R$ ' . number_format($totals['receitas_pagas'], 2, ',', '.') . '</td></tr>
                <tr><th>Despesas Pagas</th><td class="despesa">R$ ' . number_format($totals['despesas_pagas'], 2, ',', '.') . '</td></tr>
                <tr><th>Saldo Realizado</th><td class="' . ($totals['saldo_realizado'] >= 0 ? 'receita' : 'despesa') . '">R$ ' . number_format($totals['saldo_realizado'], 2, ',', '.') . '</td></tr>
            </table>
        </div>
        
        <div class="accounts">
            <h3>Detalhamento</h3>
            <table>
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Descrição</th>
                        <th>Categoria</th>
                        <th>Tipo</th>
                        <th>Valor</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>';
    
    foreach ($accounts as $account) {
        $html .= '<tr>
            <td>' . formatDate($account['due_date']) . '</td>
            <td>' . htmlspecialchars($account['description']) . '</td>
            <td>' . htmlspecialchars($account['category_name']) . '</td>
            <td>' . ucfirst($account['type']) . '</td>
            <td class="' . $account['type'] . '">R$ ' . number_format($account['amount'], 2, ',', '.') . '</td>
            <td>' . ucfirst($account['status']) . '</td>
        </tr>';
    }
    
    $html .= '</tbody>
            </table>
        </div>
        
        <script>
            window.onload = function() {
                window.print();
            }
        </script>
    </body>
    </html>';
    
    echo $html;
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Relatórios</title>
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
    <?php require_once 'partials/header.php'; render_header('reports'); ?>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Filtros -->
        <div class="bg-white rounded-lg shadow mb-8">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-900">
                    <i class="fas fa-chart-bar mr-2"></i>
                    Relatórios Financeiros
                </h2>
            </div>

            <form method="GET" class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
                    <div>
                        <label for="date_from" class="block text-sm font-medium text-gray-700 mb-2">
                            Data Inicial
                        </label>
                        <input type="date" id="date_from" name="date_from" 
                               value="<?= $filters['date_from'] ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    </div>

                    <div>
                        <label for="date_to" class="block text-sm font-medium text-gray-700 mb-2">
                            Data Final
                        </label>
                        <input type="date" id="date_to" name="date_to" 
                               value="<?= $filters['date_to'] ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    </div>

                    <div>
                        <label for="category_id" class="block text-sm font-medium text-gray-700 mb-2">
                            Categoria
                        </label>
                        <select id="category_id" name="category_id"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                            <option value="">Todas</option>
                            <?php foreach ($categories as $category): ?>
                            <option value="<?= $category['id'] ?>" <?= $filters['category_id'] == $category['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($category['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="type" class="block text-sm font-medium text-gray-700 mb-2">
                            Tipo
                        </label>
                        <select id="type" name="type"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                            <option value="">Todos</option>
                            <option value="receita" <?= $filters['type'] == 'receita' ? 'selected' : '' ?>>Receita</option>
                            <option value="despesa" <?= $filters['type'] == 'despesa' ? 'selected' : '' ?>>Despesa</option>
                        </select>
                    </div>

                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700 mb-2">
                            Status
                        </label>
                        <select id="status" name="status"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                            <option value="">Todos</option>
                            <option value="pendente" <?= $filters['status'] == 'pendente' ? 'selected' : '' ?>>Pendente</option>
                            <option value="paga" <?= $filters['status'] == 'paga' ? 'selected' : '' ?>>Paga</option>
                            <option value="recebida" <?= $filters['status'] == 'recebida' ? 'selected' : '' ?>>Recebida</option>
                        </select>
                    </div>
                </div>

                <div class="flex justify-between items-center">
                    <button type="submit" class="bg-primary hover:bg-blue-700 text-white px-6 py-2 rounded-md transition-colors">
                        <i class="fas fa-search mr-2"></i>
                        Gerar Relatório
                    </button>

                    <div class="flex space-x-2">
                        <a href="?action=export_csv&<?= http_build_query($filters) ?>" 
                           class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md transition-colors">
                            <i class="fas fa-file-csv mr-2"></i>
                            Exportar CSV
                        </a>
                        <a href="?action=export_pdf&<?= http_build_query($filters) ?>" 
                           target="_blank"
                           class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-md transition-colors">
                            <i class="fas fa-file-pdf mr-2"></i>
                            Exportar PDF
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Resumo -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">
                    <i class="fas fa-calculator mr-2"></i>
                    Totais
                </h3>
                <div class="space-y-3">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Receitas:</span>
                        <span class="font-semibold text-green-600"><?= formatCurrency($totals['receitas']) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Despesas:</span>
                        <span class="font-semibold text-red-600"><?= formatCurrency($totals['despesas']) ?></span>
                    </div>
                    <div class="flex justify-between border-t pt-3">
                        <span class="font-medium">Saldo Total:</span>
                        <span class="font-bold <?= $totals['saldo_total'] >= 0 ? 'text-green-600' : 'text-red-600' ?>">
                            <?= formatCurrency($totals['saldo_total']) ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">
                    <i class="fas fa-check-circle mr-2"></i>
                    Realizados
                </h3>
                <div class="space-y-3">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Recebidas:</span>
                        <span class="font-semibold text-green-600"><?= formatCurrency($totals['receitas_pagas']) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Pagas:</span>
                        <span class="font-semibold text-red-600"><?= formatCurrency($totals['despesas_pagas']) ?></span>
                    </div>
                    <div class="flex justify-between border-t pt-3">
                        <span class="font-medium">Saldo Realizado:</span>
                        <span class="font-bold <?= $totals['saldo_realizado'] >= 0 ? 'text-green-600' : 'text-red-600' ?>">
                            <?= formatCurrency($totals['saldo_realizado']) ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">
                    <i class="fas fa-clock mr-2"></i>
                    Pendentes
                </h3>
                <div class="space-y-3">
                    <div class="flex justify-between">
                        <span class="text-gray-600">A Receber:</span>
                        <span class="font-semibold text-green-600"><?= formatCurrency($totals['receitas_pendentes']) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">A Pagar:</span>
                        <span class="font-semibold text-red-600"><?= formatCurrency($totals['despesas_pendentes']) ?></span>
                    </div>
                    <div class="flex justify-between border-t pt-3">
                        <span class="font-medium">Saldo Pendente:</span>
                        <span class="font-bold <?= $totals['saldo_pendente'] >= 0 ? 'text-green-600' : 'text-red-600' ?>">
                            <?= formatCurrency($totals['saldo_pendente']) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Gráficos -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <!-- Gráfico por Categoria -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-chart-pie mr-2"></i>
                        Por Categoria
                    </h3>
                </div>
                <div class="p-6">
                    <canvas id="categoryChart" height="200"></canvas>
                </div>
            </div>

            <!-- Gráfico Receitas vs Despesas -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-chart-bar mr-2"></i>
                        Receitas vs Despesas
                    </h3>
                </div>
                <div class="p-6">
                    <canvas id="comparisonChart" height="200"></canvas>
                </div>
            </div>
        </div>

        <!-- Tabelas por Categoria - Lado a Lado -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Tabela de Receitas -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-green-600">
                        <i class="fas fa-plus-circle mr-2"></i>
                        Receitas por Categoria
                    </h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-green-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Categoria</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Realizado</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Pendente</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contas</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($receitas)): ?>
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                                    <i class="fas fa-inbox text-2xl mb-2"></i>
                                    <p>Nenhuma receita encontrada</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($receitas as $category): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-4 text-sm font-medium text-gray-900"><?= htmlspecialchars($category['name']) ?></td>
                                <td class="px-4 py-4 text-sm font-medium text-green-600">
                                    <?= formatCurrency($category['total']) ?>
                                </td>
                                <td class="px-4 py-4 text-sm font-medium text-green-600">
                                    <?= formatCurrency($category['paid']) ?>
                                </td>
                                <td class="px-4 py-4 text-sm font-medium text-gray-600">
                                    <?= formatCurrency($category['pending']) ?>
                                </td>
                                <td class="px-4 py-4 text-sm text-gray-900"><?= $category['count'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tabela de Despesas -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-red-600">
                        <i class="fas fa-minus-circle mr-2"></i>
                        Despesas por Categoria
                    </h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-red-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Categoria</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Realizado</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Pendente</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contas</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($despesas)): ?>
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                                    <i class="fas fa-inbox text-2xl mb-2"></i>
                                    <p>Nenhuma despesa encontrada</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($despesas as $category): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-4 text-sm font-medium text-gray-900"><?= htmlspecialchars($category['name']) ?></td>
                                <td class="px-4 py-4 text-sm font-medium text-red-600">
                                    <?= formatCurrency($category['total']) ?>
                                </td>
                                <td class="px-4 py-4 text-sm font-medium text-red-600">
                                    <?= formatCurrency($category['paid']) ?>
                                </td>
                                <td class="px-4 py-4 text-sm font-medium text-gray-600">
                                    <?= formatCurrency($category['pending']) ?>
                                </td>
                                <td class="px-4 py-4 text-sm text-gray-900"><?= $category['count'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Dados para gráfico por categoria
        const categoryData = {
            labels: [
                <?php foreach ($byCategory as $category): ?>
                '<?= addslashes($category['name']) ?>',
                <?php endforeach; ?>
            ],
            datasets: [{
                data: [
                    <?php foreach ($byCategory as $category): ?>
                    <?= $category['total'] ?>,
                    <?php endforeach; ?>
                ],
                backgroundColor: [
                    '#3B82F6', '#EF4444', '#10B981', '#F59E0B', '#8B5CF6',
                    '#EC4899', '#14B8A6', '#F97316', '#6366F1', '#84CC16'
                ]
            }]
        };

        // Gráfico por categoria
        new Chart(document.getElementById('categoryChart'), {
            type: 'doughnut',
            data: categoryData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Gráfico de comparação
        new Chart(document.getElementById('comparisonChart'), {
            type: 'bar',
            data: {
                labels: ['Total', 'Realizado', 'Pendente'],
                datasets: [{
                    label: 'Receitas',
                    data: [<?= $totals['receitas'] ?>, <?= $totals['receitas_pagas'] ?>, <?= $totals['receitas_pendentes'] ?>],
                    backgroundColor: '#10B981'
                }, {
                    label: 'Despesas',
                    data: [<?= $totals['despesas'] ?>, <?= $totals['despesas_pagas'] ?>, <?= $totals['despesas_pendentes'] ?>],
                    backgroundColor: '#EF4444'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'R$ ' + value.toLocaleString('pt-BR');
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>