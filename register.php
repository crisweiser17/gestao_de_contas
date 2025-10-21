<?php
require_once 'config/config.php';

// Se já estiver logado, redireciona para dashboard
if (isLoggedIn()) {
    redirect('index.php');
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require_once 'models/User.php';
    $userModel = new User();
    
    $data = [
        'name' => trim($_POST['name'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'password' => $_POST['password'] ?? '',
        'confirm_password' => $_POST['confirm_password'] ?? ''
    ];
    
    // Validar dados
    $errors = $userModel->validateUserData($data);
    
    if (empty($errors)) {
        $result = $userModel->create($data);
        
        if ($result['success']) {
            $success = $result['message'];
            // Limpar dados do formulário
            $data = ['name' => '', 'email' => '', 'password' => '', 'confirm_password' => ''];
        } else {
            $errors[] = $result['message'];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Cadastro</title>
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
            <p class="text-gray-600">Crie sua conta para começar</p>
        </div>

        <div class="bg-white rounded-lg shadow-md p-8">
            <?php if (!empty($errors)): ?>
            <div class="mb-4 p-4 bg-red-100 border border-red-400 text-red-700 rounded">
                <i class="fas fa-exclamation-circle mr-2"></i>
                <ul class="list-disc list-inside">
                    <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="mb-4 p-4 bg-green-100 border border-green-400 text-green-700 rounded">
                <i class="fas fa-check-circle mr-2"></i>
                <?= htmlspecialchars($success) ?>
                <p class="mt-2">
                    <a href="login.php" class="text-green-800 font-medium hover:underline">
                        Clique aqui para fazer login
                    </a>
                </p>
            </div>
            <?php endif; ?>

            <form method="POST" class="space-y-6">
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-2">
                        Nome Completo
                    </label>
                    <input type="text" id="name" name="name" required
                           value="<?= htmlspecialchars($data['name'] ?? '') ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                        Email
                    </label>
                    <input type="email" id="email" name="email" required
                           value="<?= htmlspecialchars($data['email'] ?? '') ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
                        Senha
                    </label>
                    <div class="relative">
                        <input type="password" id="password" name="password" required
                               class="w-full px-3 py-2 pr-10 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <button type="button" id="togglePassword" class="absolute inset-y-0 right-0 pr-3 flex items-center">
                            <i class="fas fa-eye text-gray-400 hover:text-gray-600" id="eyeIcon"></i>
                        </button>
                    </div>
                    <div class="mt-2">
                        <div class="flex space-x-1">
                            <div class="h-1 flex-1 bg-gray-200 rounded" id="strength1"></div>
                            <div class="h-1 flex-1 bg-gray-200 rounded" id="strength2"></div>
                            <div class="h-1 flex-1 bg-gray-200 rounded" id="strength3"></div>
                            <div class="h-1 flex-1 bg-gray-200 rounded" id="strength4"></div>
                        </div>
                        <p class="text-xs mt-1" id="strengthText">Mínimo de 6 caracteres</p>
                    </div>
                </div>

                <div>
                    <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-2">
                        Confirmar Senha
                    </label>
                    <input type="password" id="confirm_password" name="confirm_password" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <p class="text-xs mt-1 hidden" id="passwordMatch"></p>
                </div>

                <button type="submit" 
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition-colors">
                    <i class="fas fa-user-plus mr-2"></i>
                    Criar Conta
                </button>
            </form>

            <div class="mt-6 text-center">
                <p class="text-sm text-gray-600">
                    Já tem uma conta? 
                    <a href="login.php" class="text-blue-600 hover:text-blue-800 font-medium">
                        Faça login aqui
                    </a>
                </p>
            </div>
        </div>
    </div>

    <script>
        // Toggle password visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const password = document.getElementById('password');
            const eyeIcon = document.getElementById('eyeIcon');
            
            if (password.type === 'password') {
                password.type = 'text';
                eyeIcon.classList.remove('fa-eye');
                eyeIcon.classList.add('fa-eye-slash');
            } else {
                password.type = 'password';
                eyeIcon.classList.remove('fa-eye-slash');
                eyeIcon.classList.add('fa-eye');
            }
        });

        // Password strength indicator
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            const strength = calculatePasswordStrength(password);
            updateStrengthIndicator(strength);
        });

        // Password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            const matchElement = document.getElementById('passwordMatch');
            
            if (confirmPassword.length > 0) {
                matchElement.classList.remove('hidden');
                if (password === confirmPassword) {
                    matchElement.textContent = '✓ Senhas coincidem';
                    matchElement.className = 'text-xs mt-1 text-green-600';
                } else {
                    matchElement.textContent = '✗ Senhas não coincidem';
                    matchElement.className = 'text-xs mt-1 text-red-600';
                }
            } else {
                matchElement.classList.add('hidden');
            }
        });

        function calculatePasswordStrength(password) {
            let strength = 0;
            
            if (password.length >= 6) strength++;
            if (password.length >= 8) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            return Math.min(strength, 4);
        }

        function updateStrengthIndicator(strength) {
            const strengthBars = ['strength1', 'strength2', 'strength3', 'strength4'];
            const strengthText = document.getElementById('strengthText');
            const colors = ['bg-red-500', 'bg-orange-500', 'bg-yellow-500', 'bg-green-500'];
            const texts = [
                'Muito fraca',
                'Fraca', 
                'Média',
                'Boa',
                'Muito boa'
            ];

            // Reset all bars
            strengthBars.forEach(bar => {
                const element = document.getElementById(bar);
                element.className = 'h-1 flex-1 bg-gray-200 rounded';
            });

            // Fill bars based on strength
            for (let i = 0; i < strength; i++) {
                const element = document.getElementById(strengthBars[i]);
                element.className = `h-1 flex-1 rounded ${colors[Math.min(strength - 1, 3)]}`;
            }

            // Update text
            if (strength === 0) {
                strengthText.textContent = 'Mínimo de 6 caracteres';
                strengthText.className = 'text-xs mt-1 text-gray-500';
            } else {
                strengthText.textContent = texts[strength];
                strengthText.className = `text-xs mt-1 text-${strength <= 2 ? 'red' : 'green'}-600`;
            }
        }

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('As senhas não coincidem!');
                return false;
            }
            
            if (password.length < 6) {
                e.preventDefault();
                alert('A senha deve ter pelo menos 6 caracteres!');
                return false;
            }
        });
    </script>
</body>
</html>