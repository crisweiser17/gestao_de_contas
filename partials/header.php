<?php
function render_header($active) {
    $items = [
        'index' => ['label' => 'Dashboard', 'href' => 'index.php'],
        'accounts' => ['label' => 'Contas', 'href' => 'accounts.php'],
        'reports' => ['label' => 'Relatórios', 'href' => 'reports.php'],
    ];
    ?>
    <?php if (!empty($_SESSION['is_impersonating'])): ?>
        <div class="bg-yellow-50 border-b border-yellow-200 relative z-50">
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
    <header class="bg-white shadow-sm border-b sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center flex-shrink-0 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 whitespace-nowrap">
                        <i class="fas fa-chart-line text-primary mr-2"></i>
                        <?= APP_NAME ?>
                    </h1>
                </div>
                
                <!-- Botão hamburger visível apenas no mobile -->
                <button id="mobileMenuBtn" class="md:hidden inline-flex items-center px-3 py-2 rounded text-gray-600 hover:text-primary focus:outline-none focus:ring focus:ring-primary/30" aria-label="Abrir menu" aria-controls="mobileMenu" aria-expanded="false">
                    <i class="fas fa-bars text-lg" id="mobileMenuIconOpen"></i>
                    <i class="fas fa-times text-lg hidden" id="mobileMenuIconClose"></i>
                </button>
                
                <!-- Navegação desktop -->
                <div class="hidden md:flex items-center justify-between flex-1 ml-8">
                    <nav class="flex space-x-4" role="navigation" aria-label="Navegação principal">
                    <?php foreach ($items as $key => $item): ?>
                        <?php 
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
                            <a href="<?= $item['href'] ?>" class="<?= $classes ?> focus:outline-none focus:ring focus:ring-primary/30"><?= $item['label'] ?></a>
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
                                <?php if (isset($_SESSION['user_email']) && $_SESSION['user_email'] === 'hello@crisweiser.com' && empty($_SESSION['is_impersonating'])): ?>
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
        
        <!-- Menu mobile -->
        <div id="mobileMenu" class="md:hidden hidden border-t" role="navigation" aria-label="Navegação móvel">
            <div class="px-4 py-3 space-y-2 bg-white">
                <a href="index.php" class="block px-3 py-2 rounded text-gray-700 hover:bg-gray-100 <?= $active === 'index' ? 'bg-gray-50 text-primary font-medium' : '' ?> focus:outline-none focus:ring focus:ring-primary/30">
                    <i class="fas fa-home mr-2"></i> Dashboard
                </a>
                <div class="border-t pt-2"></div>
                <a href="accounts.php" class="block px-3 py-2 rounded text-gray-700 hover:bg-gray-100 <?= $active === 'accounts' ? 'bg-gray-50 text-primary font-medium' : '' ?> focus:outline-none focus:ring focus:ring-primary/30">
                    <i class="fas fa-file-invoice-dollar mr-2"></i> Contas
                </a>
                <a href="categories.php" class="block px-3 py-2 rounded text-gray-700 hover:bg-gray-100 <?= $active === 'categories' ? 'bg-gray-50 text-primary font-medium' : '' ?> focus:outline-none focus:ring focus:ring-primary/30">
                    <i class="fas fa-tags mr-2"></i> Categorias
                </a>
                <a href="recurring.php" class="block px-3 py-2 rounded text-gray-700 hover:bg-gray-100 <?= $active === 'recurring' ? 'bg-gray-50 text-primary font-medium' : '' ?> focus:outline-none focus:ring focus:ring-primary/30">
                    <i class="fas fa-sync-alt mr-2"></i> Recorrências
                </a>
                <div class="border-t pt-2"></div>
                <a href="reports.php" class="block px-3 py-2 rounded text-gray-700 hover:bg-gray-100 <?= $active === 'reports' ? 'bg-gray-50 text-primary font-medium' : '' ?> focus:outline-none focus:ring focus:ring-primary/30">
                    <i class="fas fa-chart-bar mr-2"></i> Relatórios
                </a>
                <a href="cash-flow.php" class="block px-3 py-2 rounded text-gray-700 hover:bg-gray-100 <?= $active === 'cash-flow' ? 'bg-gray-50 text-primary font-medium' : '' ?> focus:outline-none focus:ring focus:ring-primary/30">
                    <i class="fas fa-water mr-2"></i> Fluxo de Caixa
                </a>
                <?php if (isset($_SESSION['user_name'])): ?>
                    <div class="border-t pt-2"></div>
                    <a href="profile.php" class="block px-3 py-2 rounded text-gray-700 hover:bg-gray-100 focus:outline-none focus:ring focus:ring-primary/30">
                        <i class="fas fa-user-cog mr-2"></i> Perfil
                    </a>
                    <?php if (isset($_SESSION['user_email']) && $_SESSION['user_email'] === 'hello@crisweiser.com' && empty($_SESSION['is_impersonating'])): ?>
                        <a href="admin.php" class="block px-3 py-2 rounded text-gray-700 hover:bg-gray-100 focus:outline-none focus:ring focus:ring-primary/30">
                            <i class="fas fa-shield-alt mr-2"></i> Admin
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($_SESSION['is_impersonating'])): ?>
                        <a href="admin_impersonate_exit.php" class="block px-3 py-2 rounded bg-yellow-600 text-white hover:bg-yellow-700">
                            <i class="fas fa-undo mr-2"></i> Voltar para Admin
                        </a>
                    <?php endif; ?>
                    <a href="logout.php" class="block px-3 py-2 rounded text-red-600 hover:bg-red-50 focus:outline-none focus:ring focus:ring-red-300">
                        <i class="fas fa-sign-out-alt mr-2"></i> Sair
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const btn = document.getElementById('mobileMenuBtn');
            const menu = document.getElementById('mobileMenu');
            const iconOpen = document.getElementById('mobileMenuIconOpen');
            const iconClose = document.getElementById('mobileMenuIconClose');

            if (!btn || !menu) return;

            const openMenu = () => {
                menu.classList.remove('hidden');
                iconOpen.classList.add('hidden');
                iconClose.classList.remove('hidden');
                btn.setAttribute('aria-expanded', 'true');
            };
            const closeMenu = () => {
                menu.classList.add('hidden');
                iconOpen.classList.remove('hidden');
                iconClose.classList.add('hidden');
                btn.setAttribute('aria-expanded', 'false');
            };

            btn.addEventListener('click', function() {
                const isHidden = menu.classList.contains('hidden');
                isHidden ? openMenu() : closeMenu();
            });

            // Fechar ao clicar em qualquer link no menu mobile
            menu.querySelectorAll('a').forEach(a => {
                a.addEventListener('click', closeMenu);
            });

            // Fechar quando clicar fora do header/menu
            document.addEventListener('click', (e) => {
                const clickOutside = !menu.contains(e.target) && !btn.contains(e.target);
                const isOpen = !menu.classList.contains('hidden');
                if (isOpen && clickOutside) closeMenu();
            });

            // Fechar com ESC
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') closeMenu();
            });

            // Sincronizar com breakpoint md (>=768px)
            const mql = window.matchMedia('(min-width: 768px)');
            const onBreakpoint = (e) => { if (e.matches) closeMenu(); };
            if (mql.addEventListener) mql.addEventListener('change', onBreakpoint);
            else mql.addListener(onBreakpoint);
        });
    </script>
    <?php
}
?>