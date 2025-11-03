<?php
/**
 * CONFIGURAÇÃO PRINCIPAL - SISTEMA DE AMBIENTE
 * 
 * 🎯 CONTROLE DE AMBIENTE:
 * Mude apenas a variável $environment abaixo:
 * - 'PROD' = Produção (Cloudways)
 * - 'STAGE' = Desenvolvimento Local
 */

// ============================================
// 🚀 CONTROLE DE AMBIENTE - MUDE AQUI!
// ============================================
$environment = 'STAGE'; // Mude para 'PROD' para produção

// ============================================
// 📊 CONFIGURAÇÕES POR AMBIENTE
// ============================================

if ($environment === 'PROD') {
    // 🌐 AMBIENTE PRODUÇÃO (Cloudways)
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'juwvrjmpxq');
    define('DB_USER', 'juwvrjmpxq');
    define('DB_PASS', 'uPPhR6d9ZE');
    define('BASE_URL', 'https://gestaodecontas.crisweiser.com/');
    define('DEBUG_MODE', false);
    define('ENVIRONMENT', 'production');
    
    // Configurações de erro para produção
    error_reporting(0);
    ini_set('display_errors', 0);
    
} elseif ($environment === 'STAGE') {
    // 🏠 AMBIENTE LOCAL/DESENVOLVIMENTO
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'moneyview');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('BASE_URL', 'http://localhost:8000/');
    define('DEBUG_MODE', true);
    define('ENVIRONMENT', 'development');
    
    // Configurações de erro para desenvolvimento
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
} else {
    // ❌ AMBIENTE INVÁLIDO
    die("❌ ERRO: Ambiente '$environment' não reconhecido. Use 'PROD' ou 'STAGE'.");
}

// ============================================
// 🔧 CONFIGURAÇÕES GERAIS
// ============================================

// Nome da aplicação
define('APP_NAME', 'Gestão de Contas');
define('APP_VERSION', '1.0.0');

// Configurações de timezone
date_default_timezone_set('America/Sao_Paulo');

// Configurações de sessão
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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