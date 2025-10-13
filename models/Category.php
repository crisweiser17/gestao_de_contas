<?php
/**
 * Category Model - Versão Corrigida para MoneyView
 * 
 * Este modelo resolve o erro de foreign key constraint
 * que ocorria na linha 66 do arquivo original.
 * 
 * Principais correções:
 * - Validação de user_id antes da inserção
 * - Verificação de existência do usuário
 * - Tratamento específico de PDOException
 * - Métodos auxiliares para user_id válido
 */

class Category {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Método create corrigido com validação de user_id
     * Este método resolve o erro da linha 66 do Category.php original
     */
    public function create($data) {
        try {
            // 1. VALIDAÇÃO: Verificar se o user_id foi fornecido
            if (!isset($data['user_id']) || empty($data['user_id'])) {
                throw new Exception('user_id é obrigatório para criar uma categoria');
            }
            
            // 2. VALIDAÇÃO: Verificar se o usuário existe no banco
            $stmt = $this->pdo->prepare('SELECT id FROM users WHERE id = ?');
            $stmt->execute([$data['user_id']]);
            
            if (!$stmt->fetch()) {
                throw new Exception('Usuário não encontrado com ID: ' . $data['user_id']);
            }
            
            // 3. VALIDAÇÃO: Verificar se o nome da categoria foi fornecido
            if (!isset($data['name']) || empty(trim($data['name']))) {
                throw new Exception('Nome da categoria é obrigatório');
            }
            
            // 4. VALIDAÇÃO: Verificar se o tipo foi fornecido
            if (!isset($data['type']) || !in_array($data['type'], ['receita', 'despesa'])) {
                throw new Exception('Tipo é obrigatório e deve ser "receita" ou "despesa"');
            }
            
            // 5. INSERÇÃO: Agora é seguro inserir a categoria
            $stmt = $this->pdo->prepare('
                INSERT INTO categories (name, user_id, type, color) 
                VALUES (?, ?, ?, ?)
            ');
            
            $result = $stmt->execute([
                trim($data['name']),
                $data['user_id'],
                $data['type'],
                $data['color'] ?? '#3B82F6'
            ]);
            
            if ($result) {
                return $this->pdo->lastInsertId();
            } else {
                throw new Exception('Erro ao criar categoria');
            }
            
        } catch (PDOException $e) {
            // Capturar especificamente erros de foreign key constraint
            if ($e->getCode() == '23000') {
                throw new Exception('Erro: O usuário especificado não existe. Verifique o user_id.');
            }
            throw new Exception('Erro de banco de dados: ' . $e->getMessage());
        }
    }
    
    /**
     * Método auxiliar para obter um user_id válido
     * Use este método quando não souber qual user_id usar
     */
    public function getValidUserId() {
        $stmt = $this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1');
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            throw new Exception('Nenhum usuário encontrado no sistema');
        }
        
        return $user['id'];
    }
    
    /**
     * Método para verificar se um user_id existe
     */
    public function userExists($userId) {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Verificar se já existe categoria com o mesmo nome para o usuário
     */
    public function nameExists($name, $userId, $excludeId = null) {
        $query = 'SELECT COUNT(*) FROM categories WHERE user_id = ? AND LOWER(name) = LOWER(?)';
        $params = [$userId, trim($name)];
        if ($excludeId) {
            $query .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchColumn() > 0;
    }
    
    /**
     * Método create com user_id automático
     * Use este método quando quiser criar uma categoria sem especificar user_id
     */
    public function createWithAutoUser($data) {
        if (!isset($data['user_id'])) {
            $data['user_id'] = $this->getValidUserId();
        }
        
        // Definir tipo padrão se não fornecido
        if (!isset($data['type'])) {
            $data['type'] = 'despesa'; // Tipo padrão
        }
        
        return $this->create($data);
    }
    
    /**
     * Método para listar todas as categorias de um usuário
     */
    public function getByUserId($userId) {
        if (!$this->userExists($userId)) {
            throw new Exception('Usuário não encontrado');
        }
        
        $stmt = $this->pdo->prepare('
            SELECT * FROM categories 
            WHERE user_id = ? 
            ORDER BY created_at DESC
        ');
        $stmt->execute([$userId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Método para atualizar uma categoria
     */
    public function update($id, $data, $userId = null) {
        // Se user_id está sendo alterado, validar
        if (isset($data['user_id']) && !$this->userExists($data['user_id'])) {
            throw new Exception('Usuário não encontrado com ID: ' . $data['user_id']);
        }
        
        // Se type está sendo alterado, validar
        if (isset($data['type']) && !in_array($data['type'], ['receita', 'despesa'])) {
            throw new Exception('Tipo deve ser "receita" ou "despesa"');
        }
        
        $fields = [];
        $values = [];
        
        if (isset($data['name'])) {
            $fields[] = 'name = ?';
            $values[] = trim($data['name']);
        }
        
        if (isset($data['type'])) {
            $fields[] = 'type = ?';
            $values[] = $data['type'];
        }
        
        if (isset($data['color'])) {
            $fields[] = 'color = ?';
            $values[] = $data['color'];
        }
        
        if (isset($data['user_id'])) {
            $fields[] = 'user_id = ?';
            $values[] = $data['user_id'];
        }
        
        if (empty($fields)) {
            throw new Exception('Nenhum campo para atualizar');
        }
        
        $values[] = $id;
        $where = 'id = ?';
        if ($userId !== null) {
            $where .= ' AND user_id = ?';
            $values[] = $userId;
        }
        $stmt = $this->pdo->prepare('UPDATE categories SET ' . implode(', ', $fields) . ' WHERE ' . $where);
        return $stmt->execute($values);
    }
    
    /**
     * Método para deletar uma categoria
     */
    public function delete($id, $userId = null, $migrateToCategoryId = null) {
        // Verificar se há contas associadas
        $queryCheck = 'SELECT COUNT(*) FROM accounts WHERE category_id = ?';
        $params = [$id];
        if ($userId !== null) {
            $queryCheck .= ' AND user_id = ?';
            $params[] = $userId;
        }
        $checkStmt = $this->pdo->prepare($queryCheck);
        $checkStmt->execute($params);
        $accountsCount = $checkStmt->fetchColumn();
        
        if ($accountsCount > 0) {
            // Se há contas associadas mas não foi fornecida categoria de destino
            if ($migrateToCategoryId === null) {
                return 'has_accounts';
            }
            
            // Migrar contas para a nova categoria
            $queryMigrate = 'UPDATE accounts SET category_id = ? WHERE category_id = ?';
            $paramsMigrate = [$migrateToCategoryId, $id];
            if ($userId !== null) {
                $queryMigrate .= ' AND user_id = ?';
                $paramsMigrate[] = $userId;
            }
            $migrateStmt = $this->pdo->prepare($queryMigrate);
            if (!$migrateStmt->execute($paramsMigrate)) {
                return false;
            }
        }
        
        // Excluir categoria (restrita ao usuário se fornecido)
        $queryDel = 'DELETE FROM categories WHERE id = ?';
        $params = [$id];
        if ($userId !== null) {
            $queryDel .= ' AND user_id = ?';
            $params[] = $userId;
        }
        $delStmt = $this->pdo->prepare($queryDel);
        return $delStmt->execute($params);
    }
    
    /**
     * Método para listar todas as categorias
     */
    public function getAll() {
        $stmt = $this->pdo->query('
            SELECT c.*, u.name as user_name 
            FROM categories c 
            LEFT JOIN users u ON c.user_id = u.id 
            ORDER BY c.created_at DESC
        ');
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Método para buscar categoria por ID
     */
    public function getById($id, $userId = null) {
        $query = 'SELECT c.*, u.name as user_name FROM categories c LEFT JOIN users u ON c.user_id = u.id WHERE c.id = ?';
        $params = [$id];
        if ($userId !== null) {
            $query .= ' AND c.user_id = ?';
            $params[] = $userId;
        }
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Método para obter estatísticas de uso das categorias
     */
    public function getUsageStats($userId, $period = null) {
        if (!$this->userExists($userId)) {
            throw new Exception('Usuário não encontrado');
        }
        
        // Por enquanto, retornar array vazio
        // Este método pode ser implementado posteriormente com estatísticas reais
        return [];
    }
}

/*
EXEMPLO DE USO CORRETO:

try {
    // Conectar ao banco
    $pdo = new PDO("mysql:host=localhost;dbname=moneyview", $username, $password);
    $category = new Category($pdo);
    
    // Método 1: Criar categoria com user_id específico
    $categoryId = $category->create([
        'name' => 'Alimentação',
        'user_id' => 1, // Certifique-se de que este usuário existe
        'type' => 'despesa',
        'color' => '#FF6B6B'
    ]);
    
    // Método 2: Criar categoria com user_id automático
    $categoryId = $category->createWithAutoUser([
        'name' => 'Salário',
        'type' => 'receita',
        'color' => '#4ECDC4'
    ]);
    
    // Método 3: Verificar se usuário existe antes de criar
    if ($category->userExists(1)) {
        $categoryId = $category->create([
            'name' => 'Transporte',
            'user_id' => 1,
            'type' => 'despesa'
        ]);
    }
    
    echo "Categoria criada com sucesso! ID: " . $categoryId;
    
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage();
}
*/