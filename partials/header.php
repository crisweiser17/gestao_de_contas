<?php
function render_header($active) {
    $items = [
        'index' => ['label' => 'Dashboard', 'href' => 'index.php'],
        'accounts' => ['label' => 'Contas', 'href' => 'accounts.php'],
        'reports' => ['label' => 'Relatórios', 'href' => 'reports.php'],
    ];
    ?>
    <?php if (!empty($_SESSION['is_impersonating'])): ?>
        <div class="bg-yellow-50 border-b border-yellow-200">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-2 flex items-center justify-between text-sm text-yellow-800">
                <div class="flex items-center">
                    <i class="fas fa-user-secret mr-2"></i>
                    <span>
                        Impersonando como <strong><?= htmlspecialchars($_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'Usuário') ?></strong>
                    </span>
                </div>
                <a href="admin_impersonate_exit.php" class="inline-flex items-center px-3 py-1 rounded-md bg-yellow-600 hover:bg-yellow-700 text-white">
                    <i class="fas fa-undo mr-2"></i>
                    Voltar para Admin
                </a>
            </div>
        </div>
    <?php endif; ?>
    <header class="bg-white shadow-sm border-b">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center flex-shrink-0 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 whitespace-nowrap">
                        <i class="fas fa-chart-line text-primary mr-2"></i>
                        <?= APP_NAME ?>
                    </h1>
                </div>
                
                <div class="flex items-center justify-between flex-1 ml-8">
                    <nav class="flex space-x-4">
                    <?php foreach ($items as $key => $item): ?>
                        <?php 
                            // Classes padrão
                            $isActive = ($key === $active);
                            $classes = 'text-gray-600 hover:text-primary';
                            if ($isActive) $classes = 'text-primary font-medium';
                            if (!empty($item['danger'])) $classes = 'text-red-600 hover:text-red-800';
                        ?>
                        <?php if ($key === 'accounts'): ?>
                            <?php $parentActive = in_array($active, ['accounts','recurring','categories']); ?>
                            <div class="relative group">
                                <button type="button" class="<?= $parentActive ? 'text-primary font-medium' : $classes ?> inline-flex items-center cursor-default">
                                    <?= $item['label'] ?>
                                    <i class="fas fa-caret-down ml-1 text-xs"></i>
                                </button>
                                <div class="absolute left-0 top-full w-44 bg-white border border-gray-200 rounded shadow-lg p-2 hidden group-hover:block hover:block focus-within:block z-20">
                                    <a href="accounts.php" class="block px-3 py-2 rounded <?= $active === 'accounts' ? 'text-primary font-medium bg-gray-50' : 'text-gray-700 hover:bg-gray-100' ?>">
                                        Contas
                                    </a>
                                    <a href="categories.php" class="block px-3 py-2 rounded <?= $active === 'categories' ? 'text-primary font-medium bg-gray-50' : 'text-gray-700 hover:bg-gray-100' ?>">
                                        Categorias
                                    </a>
                                    <a href="recurring.php" class="block px-3 py-2 rounded <?= $active === 'recurring' ? 'text-primary font-medium bg-gray-50' : 'text-gray-700 hover:bg-gray-100' ?>">
                                        Recorrências
                                    </a>
                                </div>
                            </div>
                        <?php elseif ($key === 'reports'): ?>
                            <?php $parentActive = in_array($active, ['reports','cash-flow']); ?>
                            <div class="relative group">
                                <button type="button" class="<?= $parentActive ? 'text-primary font-medium' : $classes ?> inline-flex items-center cursor-default">
                                    <?= $item['label'] ?>
                                    <i class="fas fa-caret-down ml-1 text-xs"></i>
                                </button>
                                <div class="absolute left-0 top-full w-52 bg-white border border-gray-200 rounded shadow-lg p-2 hidden group-hover:block hover:block focus-within:block z-20">
                                    <a href="reports.php" class="block px-3 py-2 rounded <?= $active === 'reports' ? 'text-primary font-medium bg-gray-50' : 'text-gray-700 hover:bg-gray-100' ?>">
                                        Relatórios
                                    </a>
                                    <a href="cash-flow.php" class="block px-3 py-2 rounded <?= $active === 'cash-flow' ? 'text-primary font-medium bg-gray-50' : 'text-gray-700 hover:bg-gray-100' ?>">
                                        Fluxo de Caixa
                                    </a>
                                </div>
                            </div>
                        <?php else: ?>
                            <a href="<?= $item['href'] ?>" class="<?= $classes ?>"><?= $item['label'] ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </nav>
                    
                    <!-- Menu do usuário -->
                    <?php if (isset($_SESSION['user_name'])): ?>
                        <div class="relative group">
                            <button type="button" class="flex items-center text-gray-600 hover:text-primary cursor-default">
                                <i class="fas fa-user text-sm mr-2"></i>
                                <span class="text-sm">Olá, <?= htmlspecialchars($_SESSION['user_name']) ?></span>
                                <i class="fas fa-caret-down ml-1 text-xs"></i>
                            </button>
                            <div class="absolute right-0 top-full w-44 bg-white border border-gray-200 rounded shadow-lg p-2 hidden group-hover:block hover:block focus-within:block z-20">
                                <a href="profile.php" class="block px-3 py-2 rounded text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-user-cog mr-2"></i>
                                    Perfil
                                </a>
                                <?php if (isset($_SESSION['user_email']) && $_SESSION['user_email'] === 'hello@crisweiser.com'): ?>
                                    <a href="admin.php" class="block px-3 py-2 rounded text-gray-700 hover:bg-gray-100">
                                        <i class="fas fa-shield-alt mr-2"></i>
                                        Admin
                                    </a>
                                <?php endif; ?>
                                <hr class="my-1 border-gray-200">
                                <a href="logout.php" class="block px-3 py-2 rounded text-red-600 hover:bg-red-50">
                                    <i class="fas fa-sign-out-alt mr-2"></i>
                                    Sair
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>
    <?php
}
?>