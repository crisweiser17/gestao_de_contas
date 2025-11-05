<?php
require_once 'config/config.php';
require_once 'models/User.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$adminEmail = $_SESSION['user_email'] ?? null;
require_once 'models/AdminUser.php';
$adminModel = new AdminUser();
if (!$adminModel->isAdmin($adminEmail)) {
    redirect('index.php');
}

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if ($userId <= 0) {
    redirect('admin.php');
}

// Buscar usuário alvo corretamente via instância do modelo
$userModel = new User();
$user = $userModel->getById($userId);
if (!$user) {
    redirect('admin.php');
}

// Guardar quem está impersonando para permitir sair depois
$_SESSION['impersonator_email'] = $adminEmail;
$_SESSION['impersonator_user_id'] = $_SESSION['user_id'] ?? null;

// Trocar a sessão para o usuário alvo
$_SESSION['user_id'] = $user['id'];
$_SESSION['user_email'] = $user['email'];
$_SESSION['user_name'] = $user['name'];

// Opcional: marcar visualmente no header com uma flag
$_SESSION['is_impersonating'] = true;

redirect('index.php');