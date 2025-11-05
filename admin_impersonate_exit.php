<?php
require_once 'config/config.php';
require_once 'models/User.php';
require_once 'models/AdminUser.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

// Verifica se está em modo de impersonação e se quem iniciou é admin
$adminModel = new AdminUser();
$impersonatorEmail = $_SESSION['impersonator_email'] ?? null;
$impersonatorUserId = $_SESSION['impersonator_user_id'] ?? null;

if (empty($_SESSION['is_impersonating']) || !$impersonatorEmail || !$adminModel->isAdmin($impersonatorEmail)) {
    // Nada a fazer, volta para o dashboard
    redirect('index.php');
}

// Restaurar sessão do admin
$userModel = new User();
$adminUser = $userModel->getById((int)$impersonatorUserId);

if ($adminUser) {
    $_SESSION['user_id'] = $adminUser['id'];
    $_SESSION['user_email'] = $adminUser['email'];
    $_SESSION['user_name'] = $adminUser['name'];
} else {
    // Se não conseguir recuperar, restaura pelo email (mínimo)
    $_SESSION['user_email'] = $impersonatorEmail;
}

// Limpar flags de impersonação
unset($_SESSION['is_impersonating']);
unset($_SESSION['impersonator_email']);
unset($_SESSION['impersonator_user_id']);

redirect('admin.php');