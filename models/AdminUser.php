<?php
require_once 'config/database.php';

class AdminUser {
    private $conn;
    private $table_name = "users";

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    // Verificar se usuário é admin
    public function isAdmin($email) {
        // Por enquanto, apenas hello@crisweiser.com é admin
        return $email === 'hello@crisweiser.com';
    }

    // Obter todos os usuários com estatísticas
    public function getAllUsersWithStats() {
        $query = "SELECT 
                    u.id,
                    u.name,
                    u.email,
                    u.created_at,
                    u.last_login,
                    COUNT(CASE WHEN a.recurring_parent_id IS NULL THEN 1 END) as total_accounts,
                    COUNT(CASE WHEN a.recurring_parent_id IS NULL AND a.type = 'receita' THEN 1 END) as total_revenues,
                    COUNT(CASE WHEN a.recurring_parent_id IS NULL AND a.type = 'despesa' THEN 1 END) as total_expenses,
                    SUM(CASE WHEN a.type = 'receita' THEN a.amount ELSE 0 END) as total_revenue_amount,
                    SUM(CASE WHEN a.type = 'despesa' THEN a.amount ELSE 0 END) as total_expense_amount,
                    COUNT(DISTINCT c.id) as categories_count,
                    MAX(a.created_at) as last_account_created
                  FROM " . $this->table_name . " u
                  LEFT JOIN accounts a ON u.id = a.user_id
                  LEFT JOIN categories c ON u.id = c.user_id
                  GROUP BY u.id, u.name, u.email, u.created_at, u.last_login
                  ORDER BY u.created_at DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Obter estatísticas gerais do sistema
    public function getSystemStats() {
        $stats = [];
        
        // Total de usuários
        $query = "SELECT COUNT(*) as total_users FROM " . $this->table_name;
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $stats['total_users'] = $stmt->fetch(PDO::FETCH_ASSOC)['total_users'];
        
        // Usuários ativos (fizeram login nos últimos 30 dias)
        $query = "SELECT COUNT(*) as active_users FROM " . $this->table_name . " 
                  WHERE last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $stats['active_users'] = $stmt->fetch(PDO::FETCH_ASSOC)['active_users'];
        
        // Usuários que nunca fizeram login
        $query = "SELECT COUNT(*) as never_logged_in FROM " . $this->table_name . " 
                  WHERE last_login IS NULL";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $stats['never_logged_in'] = $stmt->fetch(PDO::FETCH_ASSOC)['never_logged_in'];
        
        // Total de contas no sistema
        $query = "SELECT COUNT(*) as total_accounts FROM accounts";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $stats['total_accounts'] = $stmt->fetch(PDO::FETCH_ASSOC)['total_accounts'];
        
        // Total de receitas e despesas
        $query = "SELECT 
                    COUNT(CASE WHEN type = 'receita' THEN 1 END) as total_revenues,
                    COUNT(CASE WHEN type = 'despesa' THEN 1 END) as total_expenses,
                    SUM(CASE WHEN type = 'receita' THEN amount ELSE 0 END) as total_revenue_amount,
                    SUM(CASE WHEN type = 'despesa' THEN amount ELSE 0 END) as total_expense_amount
                  FROM accounts";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats = array_merge($stats, $result);
        
        // Contas criadas nos últimos 30 dias (originais)
        $query = "SELECT COUNT(*) as accounts_last_30_days FROM accounts 
                  WHERE recurring_parent_id IS NULL
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $stats['accounts_last_30_days'] = $stmt->fetch(PDO::FETCH_ASSOC)['accounts_last_30_days'];
        
        // Usuários mais ativos (por número de contas originais)
        $query = "SELECT u.name, u.email, COUNT(CASE WHEN a.recurring_parent_id IS NULL THEN 1 END) as account_count
                  FROM users u
                  LEFT JOIN accounts a ON u.id = a.user_id
                  GROUP BY u.id, u.name, u.email
                  HAVING account_count > 0
                  ORDER BY account_count DESC
                  LIMIT 5";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $stats['most_active_users'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $stats;
    }

    // Obter atividade recente do sistema
    public function getRecentActivity($limit = 20) {
        $query = "SELECT 
                    'account' as type,
                    a.id,
                    a.description,
                    a.amount,
                    a.type as account_type,
                    a.created_at,
                    u.name as user_name,
                    u.email as user_email
                  FROM accounts a
                  JOIN users u ON a.user_id = u.id
                  ORDER BY a.created_at DESC
                  LIMIT :limit";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Obter dados para gráficos de uso
    public function getUsageChartData() {
        $data = [];
        
        // Contas criadas por mês nos últimos 12 meses (originais)
        $query = "SELECT 
                    DATE_FORMAT(created_at, '%Y-%m') as month,
                    COUNT(*) as count
                  FROM accounts
                  WHERE recurring_parent_id IS NULL
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                  GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                  ORDER BY month";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $data['accounts_by_month'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Novos usuários por mês nos últimos 12 meses
        $query = "SELECT 
                    DATE_FORMAT(created_at, '%Y-%m') as month,
                    COUNT(*) as count
                  FROM users
                  WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                  GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                  ORDER BY month";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $data['users_by_month'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Logins por dia nos últimos 30 dias
        $query = "SELECT 
                    DATE(last_login) as date,
                    COUNT(DISTINCT id) as unique_logins
                  FROM users
                  WHERE last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  AND last_login IS NOT NULL
                  GROUP BY DATE(last_login)
                  ORDER BY date";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $data['logins_by_day'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $data;
    }
}
?>