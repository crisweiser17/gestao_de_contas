<?php
// Configurações para PRODUÇÃO - Hosting Online
// IMPORTANTE: Ajuste as configurações abaixo para seu hosting

// Configurações gerais da aplicação
define('APP_NAME', 'Gestão de Contas');
define('APP_VERSION', '1.0.0');
define('BASE_URL', 'https://seudominio.com/'); // ALTERE PARA SEU DOMÍNIO

// Configurações de timezone
date_default_timezone_set('America/Sao_Paulo');

// Configurações de sessão
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Configurações de erro (HABILITADO para produção - remover em produção final)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Desabilitado em produção
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');

// Configurações de segurança para produção
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1); // Apenas HTTPS
ini_set('session.use_strict_mode', 1);

// Conexão com o banco de dados será configurada em database_production.php
// require_once 'config/database_production.php';
// $database = new Database();
// $pdo = $database->getConnection();
?>