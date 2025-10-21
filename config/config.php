<?php
// Configurações gerais da aplicação
define('APP_NAME', 'Gestão de Contas');
define('APP_VERSION', '1.0.0');
define('BASE_URL', 'http://gestaodecontas.crisweiser.com/');

// Configurações de timezone
date_default_timezone_set('America/Sao_Paulo');

// Configurações de sessão
session_start();

// Configurações de erro (desabilitar em produção)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Conexão com o banco de dados
require_once 'config/database.php';
$database = new Database();
$pdo = $database->getConnection();

// Autoload de classes
spl_autoload_register(function ($class_name) {
    $directories = [
        'classes/',
        'models/',
        'controllers/',
        'config/'
    ];
    
    foreach ($directories as $directory) {
        $file = $directory . $class_name . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// Funções auxiliares
function formatCurrency($value) {
    return 'R$ ' . number_format($value, 2, ',', '.');
}

function formatDate($date) {
    return date('d/m/Y', strtotime($date));
}

function formatDateTime($datetime) {
    return date('d/m/Y H:i', strtotime($datetime));
}

function redirect($url) {
    header("Location: " . BASE_URL . $url);
    exit();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        redirect('login.php');
    }
}
?>