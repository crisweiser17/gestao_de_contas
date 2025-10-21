<?php
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'models/User.php';

// Verificar se o usuário está logado
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user = new User();
$message = '';
$error = '';

// Processar formulário de atualização de dados
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        
        if (empty($name) || empty($email)) {
            $error = 'Nome e email são obrigatórios.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email inválido.';
        } else {
            // Verificar se o email já existe para outro usuário
            if ($user->emailExists($email) && $email !== $_SESSION['user_email']) {
                $error = 'Este email já está sendo usado por outro usuário.';
            } else {
                try {
                    $database = new Database();
                    $pdo = $database->getConnection();
                    
                    $stmt = $pdo->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?');
                    $result = $stmt->execute([$name, $email, $_SESSION['user_id']]);
                    
                    if ($result) {
                        $_SESSION['user_name'] = $name;
                        $_SESSION['user_email'] = $email;
                        $message = 'Dados atualizados com sucesso!';
                    } else {
                        $error = 'Erro ao atualizar dados.';
                    }
                } catch (Exception $e) {
                    $error = 'Erro interno: ' . $e->getMessage();
                }
            }
        }
    }
    
    // Processar formulário de mudança de senha
    if (isset($_POST['change_password'])) {
        $currentPassword = $_POST['current_password'];
        $newPassword = $_POST['new_password'];
        $confirmPassword = $_POST['confirm_password'];
        
        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $error = 'Todos os campos de senha são obrigatórios.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'A nova senha e confirmação não coincidem.';
        } elseif (strlen($newPassword) < 6) {
            $error = 'A nova senha deve ter pelo menos 6 caracteres.';
        } else {
            try {
                $database = new Database();
                $pdo = $database->getConnection();
                
                // Verificar senha atual
                $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
                $stmt->execute([$_SESSION['user_id']]);
                $userData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($userData && password_verify($currentPassword, $userData['password'])) {
                    // Atualizar senha
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
                    $result = $stmt->execute([$hashedPassword, $_SESSION['user_id']]);
                    
                    if ($result) {
                        $message = 'Senha alterada com sucesso!';
                    } else {
                        $error = 'Erro ao alterar senha.';
                    }
                } else {
                    $error = 'Senha atual incorreta.';
                }
            } catch (Exception $e) {
                $error = 'Erro interno: ' . $e->getMessage();
            }
        }
    }
}

// Buscar dados atuais do usuário
try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    $stmt = $pdo->prepare('SELECT name, email FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = 'Erro ao carregar dados do usuário.';
    $currentUser = ['name' => '', 'email' => ''];
}

require_once 'partials/header.php';
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perfil - <?= APP_NAME ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#3B82F6',
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50">
    <?php render_header('profile'); ?>
    
    <div class="max-w-4xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">Perfil do Usuário</h1>
            <p class="mt-2 text-gray-600">Gerencie suas informações pessoais e configurações de segurança.</p>
        </div>

        <?php if ($message): ?>
            <div class="mb-6 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">
                <i class="fas fa-check-circle mr-2"></i>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
                <i class="fas fa-exclamation-circle mr-2"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Dados Pessoais -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">
                    <i class="fas fa-user mr-2 text-primary"></i>
                    Dados Pessoais
                </h2>
                
                <form method="POST" class="space-y-4">
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nome</label>
                        <input type="text" id="name" name="name" 
                               value="<?= htmlspecialchars($currentUser['name']) ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                               required>
                    </div>
                    
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                        <input type="email" id="email" name="email" 
                               value="<?= htmlspecialchars($currentUser['email']) ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                               required>
                    </div>
                    
                    <button type="submit" name="update_profile" 
                            class="w-full bg-primary text-white py-2 px-4 rounded-lg hover:bg-blue-600 transition-colors">
                        <i class="fas fa-save mr-2"></i>
                        Atualizar Dados
                    </button>
                </form>
            </div>

            <!-- Alterar Senha -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">
                    <i class="fas fa-lock mr-2 text-primary"></i>
                    Alterar Senha
                </h2>
                
                <form method="POST" class="space-y-4">
                    <div>
                        <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">Senha Atual</label>
                        <div class="relative">
                            <input type="password" id="current_password" name="current_password" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent pr-10"
                                   required>
                            <button type="button" onclick="togglePassword('current_password')" 
                                    class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                <i class="fas fa-eye" id="current_password_icon"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div>
                        <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1">Nova Senha</label>
                        <div class="relative">
                            <input type="password" id="new_password" name="new_password" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent pr-10"
                                   required minlength="6">
                            <button type="button" onclick="togglePassword('new_password')" 
                                    class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                <i class="fas fa-eye" id="new_password_icon"></i>
                            </button>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Mínimo de 6 caracteres</p>
                    </div>
                    
                    <div>
                        <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirmar Nova Senha</label>
                        <div class="relative">
                            <input type="password" id="confirm_password" name="confirm_password" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent pr-10"
                                   required minlength="6">
                            <button type="button" onclick="togglePassword('confirm_password')" 
                                    class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                <i class="fas fa-eye" id="confirm_password_icon"></i>
                            </button>
                        </div>
                    </div>
                    
                    <button type="submit" name="change_password" 
                            class="w-full bg-red-600 text-white py-2 px-4 rounded-lg hover:bg-red-700 transition-colors">
                        <i class="fas fa-key mr-2"></i>
                        Alterar Senha
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const icon = document.getElementById(fieldId + '_icon');
            
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Validação em tempo real da confirmação de senha
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (confirmPassword && newPassword !== confirmPassword) {
                this.setCustomValidity('As senhas não coincidem');
            } else {
                this.setCustomValidity('');
            }
        });

        document.getElementById('new_password').addEventListener('input', function() {
            const confirmPassword = document.getElementById('confirm_password');
            if (confirmPassword.value) {
                confirmPassword.dispatchEvent(new Event('input'));
            }
        });
    </script>
</body>
</html>