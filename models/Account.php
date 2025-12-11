<?php
require_once 'config/database.php';

class Account {
    private $conn;
    private $table_name = "accounts";

    public function __construct($pdo = null) {
        if ($pdo !== null) {
            $this->conn = $pdo;
        } else {
            $database = new Database();
            $this->conn = $database->getConnection();
        }
    }

    // Buscar total mensal por tipo (receita/despesa)
    public function getMonthlyTotal($userId, $month, $type) {
        $query = "SELECT COALESCE(SUM(amount), 0) as total 
                  FROM " . $this->table_name . " 
                  WHERE user_id = :user_id 
                  AND type = :type 
                  AND DATE_FORMAT(due_date, '%Y-%m') = :month";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':month', $month);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['total'] ?? 0;
    }

    // Buscar total mensal realizado por tipo (receita/despesa)
    public function getMonthlyRealizedTotal($userId, $month, $type) {
        $status = ($type === 'receita') ? 'recebida' : 'paga';
        $query = "SELECT COALESCE(SUM(amount), 0) as total 
                  FROM " . $this->table_name . " 
                  WHERE user_id = :user_id 
                  AND type = :type 
                  AND DATE_FORMAT(due_date, '%Y-%m') = :month
                  AND status = :status";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':month', $month);
        $stmt->bindParam(':status', $status);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['total'] ?? 0;
    }

    // Buscar contas vencidas
    public function getOverdueAccounts($userId) {
        $query = "SELECT a.*, c.name as category_name, a.is_recurring
                  FROM " . $this->table_name . " a
                  LEFT JOIN categories c ON a.category_id = c.id
                  WHERE a.user_id = :user_id 
                  AND a.due_date < CURDATE() 
                  AND a.status = 'pendente'
                  ORDER BY a.due_date ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Buscar contas vencendo nos próximos X dias
    public function getUpcomingAccounts($userId, $days) {
        $query = "SELECT a.*, c.name as category_name, a.is_recurring
                  FROM " . $this->table_name . " a
                  LEFT JOIN categories c ON a.category_id = c.id
                  WHERE a.user_id = :user_id 
                  AND a.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY)
                  AND a.status = 'pendente'
                  ORDER BY a.due_date ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':days', $days);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Buscar contas vencendo do 8º dia até o fim do mês atual
    public function getUpcomingAccountsRestOfMonth($userId) {
        $query = "SELECT a.*, c.name as category_name, a.is_recurring
                  FROM " . $this->table_name . " a
                  LEFT JOIN categories c ON a.category_id = c.id
                  WHERE a.user_id = :user_id 
                  AND a.due_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 8 DAY) AND LAST_DAY(CURDATE())
                  AND a.status = 'pendente'
                  ORDER BY a.due_date ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Calcular saldo atual (receitas pagas - despesas pagas)
    public function getCurrentBalance($userId) {
        $query = "SELECT 
                    COALESCE(SUM(CASE WHEN type = 'receita' AND status = 'recebida' THEN amount ELSE 0 END), 0) -
                    COALESCE(SUM(CASE WHEN type = 'despesa' AND status = 'paga' THEN amount ELSE 0 END), 0) as balance
                  FROM " . $this->table_name . " 
                  WHERE user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['balance'] ?? 0;
    }

    // Buscar transações recentes
    public function getRecentTransactions($userId, $limit = 10) {
        $query = "SELECT a.*, c.name as category_name, a.is_recurring
                  FROM " . $this->table_name . " a
                  LEFT JOIN categories c ON a.category_id = c.id
                  WHERE a.user_id = :user_id 
                  ORDER BY a.updated_at DESC, a.created_at DESC
                  LIMIT :limit";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Criar nova conta
    public function create($data) {
        $query = "INSERT INTO " . $this->table_name . " 
                  (user_id, category_id, description, name, amount, due_date, type, status, url, is_recurring, notes, attachment) 
                  VALUES (:user_id, :category_id, :description, :name, :amount, :due_date, :type, :status, :url, :is_recurring, :notes, :attachment)";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':user_id', $data['user_id']);
        $stmt->bindParam(':category_id', $data['category_id']);
        $stmt->bindParam(':description', $data['description']);
        // adicionar nome (pode ser null)
        $name = $data['name'] ?? null;
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':amount', $data['amount']);
        $stmt->bindParam(':due_date', $data['due_date']);
        $stmt->bindParam(':type', $data['type']);
        $stmt->bindParam(':status', $data['status']);
        $stmt->bindParam(':url', $data['url']);
        $stmt->bindParam(':is_recurring', $data['is_recurring'], PDO::PARAM_BOOL);
        $stmt->bindParam(':notes', $data['notes']);
        
        $attachment = $data['attachment'] ?? null;
        $stmt->bindParam(':attachment', $attachment);
        
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        return false;
    }

    // Atualizar conta
    public function update($id, $data, $userId) {
        $query = "UPDATE " . $this->table_name . " 
                  SET category_id = :category_id, description = :description, name = :name, amount = :amount, 
                      due_date = :due_date, type = :type, status = :status, url = :url, notes = :notes, 
                      attachment = :attachment, is_recurring = :is_recurring
                  WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':category_id', $data['category_id']);
        $stmt->bindParam(':description', $data['description']);
        $name = $data['name'] ?? null;
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':amount', $data['amount']);
        $stmt->bindParam(':due_date', $data['due_date']);
        $stmt->bindParam(':type', $data['type']);
        $stmt->bindParam(':status', $data['status']);
        $stmt->bindParam(':url', $data['url']);
        $stmt->bindParam(':notes', $data['notes']);
        $stmt->bindParam(':is_recurring', $data['is_recurring'], PDO::PARAM_BOOL);
        
        $attachment = $data['attachment'] ?? null;
        $stmt->bindParam(':attachment', $attachment);
        
        return $stmt->execute();
    }

    // Buscar conta por ID
    public function getById($id, $userId) {
        $query = "SELECT a.*, c.name as category_name, a.is_recurring
                  FROM " . $this->table_name . " a
                  LEFT JOIN categories c ON a.category_id = c.id
                  WHERE a.id = :id AND a.user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Atualizar apenas status
    public function updateStatus($id, $status, $userId) {
        // Buscar tipo da conta para validar status compatível
        $account = $this->getById($id, $userId);
        if (!$account) {
            return false;
        }
        $type = $account['type'] ?? null;
        if ($type === 'despesa' && !in_array($status, ['pendente', 'paga'])) {
            $status = 'paga';
        } else if ($type === 'receita' && !in_array($status, ['pendente', 'recebida'])) {
            $status = 'recebida';
        } else if (!in_array($status, ['pendente', 'paga', 'recebida'])) {
            $status = 'pendente';
        }

        $query = "UPDATE " . $this->table_name . " 
                  SET status = :status, updated_at = CURRENT_TIMESTAMP 
                  WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':user_id', $userId);
        
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    // Deletar conta
    public function delete($id, $userId) {
        $query = "DELETE FROM " . $this->table_name . " 
                  WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        
        return $stmt->execute();
    }

    // Contar registros com filtros
    public function countWithFilters($userId, $filters = []) {
        $query = "SELECT COUNT(*) as total 
                  FROM " . $this->table_name . " a
                  WHERE a.user_id = :user_id";
        
        $params = [':user_id' => $userId];
        
        if (!empty($filters['category_id'])) {
            $query .= " AND a.category_id = :category_id";
            $params[':category_id'] = $filters['category_id'];
        }
        
        if (!empty($filters['type'])) {
            $query .= " AND a.type = :type";
            $params[':type'] = $filters['type'];
        }
        
        if (!empty($filters['status'])) {
            $query .= " AND a.status = :status";
            $params[':status'] = $filters['status'];
        }
        
        if (!empty($filters['date_from'])) {
            $query .= " AND a.due_date >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $query .= " AND a.due_date <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['total'] ?? 0;
    }

    // Buscar contas com filtros e paginação
    public function getWithFilters($userId, $filters = [], $sortBy = 'due_date', $sortOrder = 'DESC', $limit = null, $offset = 0) {
        $query = "SELECT a.*, c.name as category_name, a.is_recurring
                  FROM " . $this->table_name . " a
                  LEFT JOIN categories c ON a.category_id = c.id
                  WHERE a.user_id = :user_id";
        
        $params = [':user_id' => $userId];
        
        if (!empty($filters['category_id'])) {
            $query .= " AND a.category_id = :category_id";
            $params[':category_id'] = $filters['category_id'];
        }
        
        if (!empty($filters['type'])) {
            $query .= " AND a.type = :type";
            $params[':type'] = $filters['type'];
        }
        
        if (!empty($filters['status'])) {
            $query .= " AND a.status = :status";
            $params[':status'] = $filters['status'];
        }
        
        if (!empty($filters['date_from'])) {
            $query .= " AND a.due_date >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $query .= " AND a.due_date <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }
        
        // Ordenação dinâmica
        $query .= " ORDER BY ";
        
        switch ($sortBy) {
            case 'status':
                $query .= "a.status";
                break;
            case 'description':
                $query .= "a.description";
                break;
            case 'amount':
                $query .= "a.amount";
                break;
            case 'due_date':
            default:
                $query .= "a.due_date";
                break;
        }
        
        $query .= " " . $sortOrder;
        
        // Adicionar paginação se especificada
        if ($limit !== null) {
            $query .= " LIMIT :limit OFFSET :offset";
            $params[':limit'] = $limit;
            $params[':offset'] = $offset;
        }
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            if ($key === ':limit' || $key === ':offset') {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value);
            }
        }
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Calcular soma total dos valores com filtros aplicados
    public function sumWithFilters($userId, $filters = []) {
        $query = "SELECT SUM(a.amount) as total
                  FROM accounts a 
                  LEFT JOIN categories c ON a.category_id = c.id 
                  WHERE a.user_id = :user_id";
        
        $params = [':user_id' => $userId];
        
        if (!empty($filters['type'])) {
            $query .= " AND a.type = :type";
            $params[':type'] = $filters['type'];
        }
        
        if (!empty($filters['category_id'])) {
            $query .= " AND a.category_id = :category_id";
            $params[':category_id'] = $filters['category_id'];
        }
        
        if (!empty($filters['search'])) {
            $query .= " AND (a.description LIKE :search OR c.name LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        if (!empty($filters['status'])) {
            $query .= " AND a.status = :status";
            $params[':status'] = $filters['status'];
        }
        
        if (!empty($filters['date_from'])) {
            $query .= " AND a.due_date >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $query .= " AND a.due_date <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['total'] ?? 0;
    }

    // Limpar contas antigas (para manutenção do banco)
    public function cleanupOldAccounts($cutoffDate) {
        $query = "DELETE FROM accounts 
                  WHERE due_date < :cutoff_date 
                    AND status IN ('paga', 'recebida') 
                    AND parent_id IS NOT NULL";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':cutoff_date', $cutoffDate);
        $stmt->execute();
        
        return $stmt->rowCount();
    }
}
?>
