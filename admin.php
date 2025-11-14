<?php
require_once 'config/config.php';
require_once 'models/AdminUser.php';

// Verificar se o usuário está logado
if (!isLoggedIn()) {
    redirect('login.php');
}

// Verificar se o usuário é admin
$adminModel = new AdminUser();
if (!$adminModel->isAdmin($_SESSION['user_email'])) {
    redirect('index.php');
}

// Obter dados para o dashboard
$users = $adminModel->getAllUsersWithStats();
$systemStats = $adminModel->getSystemStats();
$recentActivity = $adminModel->getRecentActivity(15);
$chartData = $adminModel->getUsageChartData();

require_once 'partials/header.php';
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Administrativo - <?= APP_NAME ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .stat-card-green {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        .stat-card-orange {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        }
        .stat-card-red {
            background: linear-gradient(135deg, #ff6b6b 0%, #ffa726 100%);
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php render_header('admin'); ?>
    
    <div class="container mx-auto px-4 py-8">
        <!-- Cabeçalho -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900 mb-2">
                <i class="fas fa-shield-alt text-blue-600 mr-3"></i>
                Painel Administrativo
            </h1>
            <p class="text-gray-600">Monitoramento e estatísticas do sistema</p>
        </div>

        <!-- Cards de Estatísticas Gerais -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="stat-card rounded-lg p-6 text-white">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-white/80 text-sm">Total de Usuários</p>
                        <p class="text-2xl font-bold"><?= number_format($systemStats['total_users']) ?></p>
                    </div>
                    <i class="fas fa-users text-3xl text-white/60"></i>
                </div>
            </div>

            <div class="stat-card-green rounded-lg p-6 text-white">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-white/80 text-sm">Usuários Ativos (30d)</p>
                        <p class="text-2xl font-bold"><?= number_format($systemStats['active_users']) ?></p>
                    </div>
                    <i class="fas fa-user-check text-3xl text-white/60"></i>
                </div>
            </div>

            <div class="stat-card-orange rounded-lg p-6 text-white">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-white/80 text-sm">Total de Contas</p>
                        <p class="text-2xl font-bold"><?= number_format($systemStats['total_accounts']) ?></p>
                    </div>
                    <i class="fas fa-file-invoice-dollar text-3xl text-white/60"></i>
                </div>
            </div>

            <div class="stat-card-red rounded-lg p-6 text-white">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-white/80 text-sm">Nunca Logaram</p>
                        <p class="text-2xl font-bold"><?= number_format($systemStats['never_logged_in']) ?></p>
                    </div>
                    <i class="fas fa-user-times text-3xl text-white/60"></i>
                </div>
            </div>
        </div>

        <!-- Usuários Mais Ativos -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">
                <i class="fas fa-trophy text-yellow-600 mr-2"></i>
                Usuários Mais Ativos
            </h3>
            <div class="space-y-3">
                <?php foreach (array_slice($systemStats['most_active_users'], 0, 5) as $index => $user): ?>
                    <div class="flex items-center justify-between p-2 hover:bg-gray-50 rounded">
                        <div class="flex items-center">
                            <span class="w-6 h-6 bg-yellow-100 text-yellow-800 rounded-full flex items-center justify-center text-xs font-bold mr-3">
                                <?= $index + 1 ?>
                            </span>
                            <div>
                                <p class="font-medium text-gray-900"><?= htmlspecialchars($user['name']) ?></p>
                                <p class="text-xs text-gray-500"><?= htmlspecialchars($user['email']) ?></p>
                            </div>
                        </div>
                        <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs font-medium">
                            <?= $user['account_count'] ?> contas originais
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Gráfico de Atividade -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">
                <i class="fas fa-chart-line text-blue-600 mr-2"></i>
                Atividade dos Últimos 12 Meses
            </h3>
            <canvas id="activityChart" height="100"></canvas>
        </div>

        <!-- Tabela de Usuários -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-users text-gray-600 mr-2"></i>
                    Todos os Usuários
                </h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Usuário
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Último Login
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Contas Criadas
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Categorias
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Receitas/Despesas
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Valor Total
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Cadastro
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Ações
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($users as $user): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="w-10 h-10 bg-gray-200 rounded-full flex items-center justify-center mr-3">
                                            <i class="fas fa-user text-gray-500"></i>
                                        </div>
                                        <div>
                                            <div class="text-sm font-medium text-gray-900">
                                                <?= htmlspecialchars($user['name']) ?>
                                                <?php if ($user['email'] === 'hello@crisweiser.com'): ?>
                                                    <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">
                                                        Admin
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-sm text-gray-500"><?= htmlspecialchars($user['email']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php if ($user['last_login']): ?>
                                        <span class="text-green-600">
                                            <i class="fas fa-circle text-xs mr-1"></i>
                                            <?= date('d/m/Y H:i', strtotime($user['last_login'])) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-red-600">
                                            <i class="fas fa-times-circle text-xs mr-1"></i>
                                            Nunca logou
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                        <?= $user['total_accounts'] ?> contas originais
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                        <?= $user['categories_count'] ?> categorias
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <div class="flex space-x-2">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            <?= $user['total_revenues'] ?> receitas
                                        </span>
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                            <?= $user['total_expenses'] ?> despesas
                                        </span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <div>
                                        <div class="text-green-600 text-xs">
                                            +R$ <?= number_format($user['total_revenue_amount'], 2, ',', '.') ?>
                                        </div>
                                        <div class="text-red-600 text-xs">
                                            -R$ <?= number_format($user['total_expense_amount'], 2, ',', '.') ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?= date('d/m/Y', strtotime($user['created_at'])) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <a href="admin_impersonate.php?user_id=<?= (int)$user['id'] ?>" class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded-md text-xs">
                                        <i class="fas fa-user-secret mr-2"></i>
                                        Entrar como
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Atividade Recente -->
        <div class="mt-8 bg-white rounded-lg shadow-md">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-clock text-gray-600 mr-2"></i>
                    Atividade Recente
                </h3>
            </div>
            <div class="p-6">
                <div class="space-y-4">
                    <?php foreach ($recentActivity as $activity): ?>
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                            <div class="flex items-center">
                                <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center mr-3">
                                    <i class="fas fa-plus text-blue-600 text-xs"></i>
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($activity['user_name']) ?> criou uma nova 
                                        <span class="<?= $activity['account_type'] === 'receita' ? 'text-green-600' : 'text-red-600' ?>">
                                            <?= $activity['account_type'] ?>
                                        </span>
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        <?= htmlspecialchars($activity['description']) ?> - 
                                        R$ <?= number_format($activity['amount'], 2, ',', '.') ?>
                                    </p>
                                </div>
                            </div>
                            <span class="text-xs text-gray-400">
                                <?= date('d/m H:i', strtotime($activity['created_at'])) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <!-- Execução automática de recorrências (cron) -->
        <div class="mt-8 bg-white rounded-lg shadow-md p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">
                <i class="fas fa-calendar-alt text-blue-600 mr-2"></i>
                Agendamento de Processamento de Recorrências (cron)
            </h3>
            <p class="text-sm text-gray-700 mb-3">
                Para executar diariamente a geração de parcelas de contas recorrentes, configure um cron no servidor:
            </p>
            <pre class="bg-gray-100 p-3 rounded text-xs overflow-x-auto"><code>0 6 * * * /usr/bin/php <?= htmlspecialchars(realpath(__DIR__ . '/cron/process_recurring.php')) ?></code></pre>
            <p class="text-xs text-gray-500 mt-2">
                Dica: ajuste o horário conforme sua necessidade. O caminho acima é gerado com base na instalação atual.
            </p>
        </div>
    </div>

    <script>
        // Gráfico de atividade
        const ctx = document.getElementById('activityChart').getContext('2d');
        
        // Preparar dados dos gráficos
        const accountsData = <?= json_encode($chartData['accounts_by_month']) ?>;
        const usersData = <?= json_encode($chartData['users_by_month']) ?>;
        
        // Criar arrays de labels e dados
        const months = [];
        const accountCounts = [];
        const userCounts = [];
        
        // Últimos 12 meses
        for (let i = 11; i >= 0; i--) {
            const date = new Date();
            date.setMonth(date.getMonth() - i);
            const monthKey = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0');
            const monthLabel = date.toLocaleDateString('pt-BR', { month: 'short', year: '2-digit' });
            
            months.push(monthLabel);
            
            // Encontrar dados para este mês
            const accountData = accountsData.find(item => item.month === monthKey);
            const userData = usersData.find(item => item.month === monthKey);
            
            accountCounts.push(accountData ? parseInt(accountData.count) : 0);
            userCounts.push(userData ? parseInt(userData.count) : 0);
        }
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: months,
                datasets: [{
                    label: 'Contas Criadas (originais)',
                    data: accountCounts,
                    borderColor: 'rgb(59, 130, 246)',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    tension: 0.4
                }, {
                    label: 'Novos Usuários',
                    data: userCounts,
                    borderColor: 'rgb(16, 185, 129)',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>