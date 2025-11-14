<?php
/**
 * CONFIGURAÇÃO PRINCIPAL - SISTEMA DE AMBIENTE PERSISTENTE
 * 
 * 🎯 CONTROLE DE AMBIENTE PERSISTENTE:
 * 1. Via URL: index.php?setvar=PROD ou index.php?setvar=STAGE
 *    → REESCREVE este arquivo permanentemente!
 * 2. Via edição manual: Mude $environment abaixo
 * 
 * 💾 COMO FUNCIONA:
 * - Ao acessar ?setvar=PROD → Salva PROD no arquivo
 * - Ao acessar ?setvar=STAGE → Salva STAGE no arquivo  
 * - Todos os links subsequentes usam o ambiente salvo
 * - Não precisa mais do parâmetro setvar na URL
 * 
 * 🔧 AMBIENTES:
 * - 'PROD' = Produção (Cloudways juwvrjmpxq)
 * - 'STAGE' = Desenvolvimento Local (root@localhost)
 */

// ============================================
// 🚀 CONTROLE DE AMBIENTE DINÂMICO
// ============================================

// Detecta parâmetro setvar na URL para alternar ambiente PERSISTENTEMENTE
if (isset($_GET['setvar'])) {
    $requested_env = strtoupper($_GET['setvar']);
    if (in_array($requested_env, ['PROD', 'STAGE'])) {
        
        // Lê o arquivo atual
        $config_file = __FILE__;
        $config_content = file_get_contents($config_file);
        
        // Atualiza a linha do ambiente padrão
        $old_pattern = "/\\\$environment = '[A-Z]+'; \/\/ Ambiente padrão persistente/";
        $new_line = "\$environment = '$requested_env'; // Ambiente padrão persistente";
        $config_content = preg_replace($old_pattern, $new_line, $config_content);
        
        // Reescreve o arquivo
        file_put_contents($config_file, $config_content);
        
        // Feedback visual da mudança PERSISTENTE
        echo "<div style='background: #FF9800; color: white; padding: 15px; text-align: center; font-family: Arial; border-left: 5px solid #F57C00;'>";
        echo "💾 <strong>AMBIENTE ALTERADO PERMANENTEMENTE PARA: " . $requested_env . "</strong>";
        echo "<br>📊 Credenciais: " . ($requested_env === 'PROD' ? 'Cloudways (juwvrjmpxq)' : 'Local (root@localhost)');
        echo "<br>✅ Configuração salva! Agora todos os links usarão este ambiente.";
        echo "</div>";
        
        // Define o ambiente para esta execução
        $environment = $requested_env;
    }
} else {
    // Ambiente padrão persistente - ESTA LINHA SERÁ REESCRITA AUTOMATICAMENTE
    $environment = 'STAGE'; // Ambiente padrão persistente
}

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
    ini_set('session.gc_maxlifetime', 86400);
    session_set_cookie_params(86400, '/', '', (ENVIRONMENT === 'production'), true);
    session_start();
}

// Conexão com o banco de dados
require_once __DIR__ . '/database.php';
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