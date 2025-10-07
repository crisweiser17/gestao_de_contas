<?php
require_once 'config/database.php';

class User {
    private $conn;
    private $table_name = "users";

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    // Autenticar usuário
    public function authenticate($email, $password) {
        $query = "SELECT id, name, email, password FROM " . $this->table_name . " 
                  WHERE email = :email";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            // Remove a senha do retorno por segurança
            unset($user['password']);
            return $user;
        }
        
        return false;
    }

    // Criar novo usuário
    public function create($data) {
        // Verificar se email já existe
        if ($this->emailExists($data['email'])) {
            return ['success' => false, 'message' => 'Este email já está em uso'];
        }

        $query = "INSERT INTO " . $this->table_name . " 
                  (name, email, password) 
                  VALUES (:name, :email, :password)";
        
        $stmt = $this->conn->prepare($query);
        
        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
        
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':email', $data['email']);
        $stmt->bindParam(':password', $hashedPassword);
        
        if ($stmt->execute()) {
            $userId = $this->conn->lastInsertId();
            
            // Criar categorias padrão para o novo usuário
            require_once 'models/Category.php';
            $categoryModel = new Category($this->conn);
            $categoryModel->createDefaultCategories($userId);
            
            return ['success' => true, 'user_id' => $userId, 'message' => 'Usuário criado com sucesso'];
        }
        
        return ['success' => false, 'message' => 'Erro ao criar usuário'];
    }

    // Verificar se email já existe
    public function emailExists($email, $excludeId = null) {
        $query = "SELECT COUNT(*) as count FROM " . $this->table_name . " 
                  WHERE email = :email";
        
        if ($excludeId) {
            $query .= " AND id != :exclude_id";
        }
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        
        if ($excludeId) {
            $stmt->bindParam(':exclude_id', $excludeId);
        }
        
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['count'] > 0;
    }

    // Buscar usuário por ID
    public function getById($id) {
        $query = "SELECT id, name, email, created_at FROM " . $this->table_name . " 
                  WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Atualizar perfil do usuário
    public function updateProfile($id, $data) {
        // Verificar se email já existe (excluindo o próprio usuário)
        if ($this->emailExists($data['email'], $id)) {
            return ['success' => false, 'message' => 'Este email já está em uso'];
        }

        $query = "UPDATE " . $this->table_name . " 
                  SET name = :name, email = :email 
                  WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':email', $data['email']);
        
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'Perfil atualizado com sucesso'];
        }
        
        return ['success' => false, 'message' => 'Erro ao atualizar perfil'];
    }

    // Alterar senha
    public function changePassword($id, $currentPassword, $newPassword) {
        // Verificar senha atual
        $query = "SELECT password FROM " . $this->table_name . " WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || !password_verify($currentPassword, $user['password'])) {
            return ['success' => false, 'message' => 'Senha atual incorreta'];
        }

        // Atualizar senha
        $updateQuery = "UPDATE " . $this->table_name . " 
                        SET password = :password 
                        WHERE id = :id";
        
        $updateStmt = $this->conn->prepare($updateQuery);
        
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $updateStmt->bindParam(':password', $hashedPassword);
        $updateStmt->bindParam(':id', $id);
        
        if ($updateStmt->execute()) {
            return ['success' => true, 'message' => 'Senha alterada com sucesso'];
        }
        
        return ['success' => false, 'message' => 'Erro ao alterar senha'];
    }

    // Validar dados do usuário
    public function validateUserData($data, $isUpdate = false) {
        $errors = [];

        // Validar nome
        if (empty(trim($data['name']))) {
            $errors[] = 'Nome é obrigatório';
        } elseif (strlen(trim($data['name'])) < 2) {
            $errors[] = 'Nome deve ter pelo menos 2 caracteres';
        }

        // Validar email
        if (empty(trim($data['email']))) {
            $errors[] = 'Email é obrigatório';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email inválido';
        }

        // Validar senha (apenas para criação ou se fornecida na atualização)
        if (!$isUpdate || !empty($data['password'])) {
            if (empty($data['password'])) {
                $errors[] = 'Senha é obrigatória';
            } elseif (strlen($data['password']) < 6) {
                $errors[] = 'Senha deve ter pelo menos 6 caracteres';
            }

            // Confirmar senha
            if (!empty($data['confirm_password']) && $data['password'] !== $data['confirm_password']) {
                $errors[] = 'Senhas não coincidem';
            }
        }

        return $errors;
    }
}
?>