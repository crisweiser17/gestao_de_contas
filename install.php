<?php
// Script de instalação do MoneyView
// Execute este arquivo uma vez para configurar o banco de dados

require_once 'config/config.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Conectar ao MySQL sem especificar banco
        $pdo = new PDO("mysql:host=localhost", 'root', '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Ler e executar o schema SQL
        $sql = file_get_contents('database/schema.sql');
        
        // Dividir em comandos individuais
        $commands = array_filter(array_map('trim', explode(';', $sql)));
        
        foreach ($commands as $command) {
            if (!empty($command)) {
                $pdo->exec($command);
            }
        }
        
        $message = 'Banco de dados criado com sucesso! Você pode agora usar o sistema.';
        
    } catch (PDOException $e) {
        $error = 'Erro ao criar banco de dados: ' . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Instalação</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full space-y-8 p-8">
        <div class="text-center">
            <h1 class="text-3xl font-bold text-gray-900 mb-2">
                <i class="fas fa-chart-line text-blue-600 mr-2"></i>
                <?= APP_NAME ?>
            </h1>
            <p class="text-gray-600">Instalação do Sistema</p>
        </div>

        <div class="bg-white rounded-lg shadow-md p-8">
            <?php if ($error): ?>
            <div class="mb-4 p-4 bg-red-100 border border-red-400 text-red-700 rounded">
                <i class="fas fa-exclamation-circle mr-2"></i>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <?php if ($message): ?>
            <div class="mb-4 p-4 bg-green-100 border border-green-400 text-green-700 rounded">
                <i class="fas fa-check-circle mr-2"></i>
                <?= htmlspecialchars($message) ?>
                <div class="mt-4 space-y-2">
                    <a href="register.php" class="block w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md text-center transition-colors">
                        Criar Conta
                    </a>
                    <a href="login.php" class="block w-full bg-gray-600 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-md text-center transition-colors">
                        Fazer Login
                    </a>
                </div>
            </div>
            <?php else: ?>
            
            <div class="mb-6">
                <h2 class="text-lg font-medium text-gray-900 mb-4">Configuração Inicial</h2>
                <div class="space-y-3 text-sm text-gray-600">
                    <div class="flex items-center">
                        <i class="fas fa-check text-green-500 mr-2"></i>
                        Criar banco de dados 'moneyview'
                    </div>
                    <div class="flex items-center">
                        <i class="fas fa-check text-green-500 mr-2"></i>
                        Criar tabelas necessárias
                    </div>
                    <div class="flex items-center">
                        <i class="fas fa-check text-green-500 mr-2"></i>
                        Inserir dados iniciais
                    </div>
                    <div class="flex items-center">
                        <i class="fas fa-check text-green-500 mr-2"></i>
                        Criar usuário de teste
                    </div>
                </div>
            </div>

            <div class="mb-6 p-4 bg-yellow-100 border border-yellow-400 text-yellow-700 rounded">
                <h3 class="font-medium mb-2">Requisitos:</h3>
                <ul class="text-sm space-y-1">
                    <li>• MySQL/MariaDB rodando</li>
                    <li>• Usuário 'root' sem senha (ou ajuste em config/database.php)</li>
                    <li>• PHP com extensão PDO MySQL</li>
                </ul>
            </div>

            <form method="POST">
                <button type="submit" 
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition-colors">
                    <i class="fas fa-database mr-2"></i>
                    Instalar Banco de Dados
                </button>
            </form>

            <div class="mt-6 p-4 bg-gray-100 rounded-md">
                <h3 class="text-sm font-medium text-gray-700 mb-2">Usuário de teste criado:</h3>
                <p class="text-sm text-gray-600">
                    <strong>Email:</strong> teste@moneyview.com<br>
                    <strong>Senha:</strong> password
                </p>
            </div>
            
            <?php endif; ?>
        </div>
    </div>
</body>
</html>