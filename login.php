<?php
require_once 'config/config.php';

// Se já estiver logado, redireciona para dashboard
if (isLoggedIn()) {
    redirect('index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = 'Por favor, preencha todos os campos.';
    } else {
        require_once 'models/User.php';
        $userModel = new User();
        
        $user = $userModel->authenticate($email, $password);
        
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            
            redirect('index.php');
        } else {
            $error = 'Email ou senha incorretos.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Login</title>
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
            <p class="text-gray-600">Faça login para acessar sua conta</p>
        </div>

        <div class="bg-white rounded-lg shadow-md p-8">
            <?php if ($error): ?>
            <div class="mb-4 p-4 bg-red-100 border border-red-400 text-red-700 rounded">
                <i class="fas fa-exclamation-circle mr-2"></i>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" class="space-y-6">
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                        Email
                    </label>
                    <input type="email" id="email" name="email" required
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
                        Senha
                    </label>
                    <input type="password" id="password" name="password" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>

                <button type="submit" 
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition-colors">
                    <i class="fas fa-sign-in-alt mr-2"></i>
                    Entrar
                </button>
            </form>

            <div class="mt-6 text-center">
                <p class="text-sm text-gray-600">
                    Não tem uma conta? 
                    <a href="register.php" class="text-blue-600 hover:text-blue-800 font-medium">
                        Cadastre-se aqui
                    </a>
                </p>
            </div>

            <!-- Dados de teste -->
            <div class="mt-6 p-4 bg-gray-100 rounded-md">
                <h3 class="text-sm font-medium text-gray-700 mb-2">Dados para teste:</h3>
                <p class="text-sm text-gray-600">
                    <strong>Email:</strong> teste@moneyview.com<br>
                    <strong>Senha:</strong> password
                </p>
            </div>
        </div>
    </div>
</body>
</html>