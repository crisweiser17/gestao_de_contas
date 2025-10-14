<?php
require_once 'config/database.php';

class RecurringSetting {
    private $conn;
    private $table_name = "recurring_settings";

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    // Criar configuração de recorrência
    public function create($data) {
        $query = "INSERT INTO " . $this->table_name . " 
                  (account_id, frequency_type, frequency_interval, end_date, max_occurrences, next_generation_date) 
                  VALUES (:account_id, :frequency_type, :frequency_interval, :end_date, :max_occurrences, :next_generation_date)";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':account_id', $data['account_id']);
        $stmt->bindParam(':frequency_type', $data['frequency_type']);
        $stmt->bindParam(':frequency_interval', $data['frequency_interval']);
        $stmt->bindParam(':end_date', $data['end_date']);
        $stmt->bindParam(':max_occurrences', $data['max_occurrences']);
        $stmt->bindParam(':next_generation_date', $data['next_generation_date']);
        
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        return false;
    }

    // Buscar configuração por conta
    public function getByAccountId($accountId) {
        $query = "SELECT * FROM " . $this->table_name . " 
                  WHERE account_id = :account_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':account_id', $accountId);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Atualizar configuração
    public function update($accountId, $data) {
        $query = "UPDATE " . $this->table_name . " 
                  SET frequency_type = :frequency_type, frequency_interval = :frequency_interval, 
                      end_date = :end_date, max_occurrences = :max_occurrences, 
                      next_generation_date = :next_generation_date, is_active = :is_active
                  WHERE account_id = :account_id";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':account_id', $accountId);
        $stmt->bindParam(':frequency_type', $data['frequency_type']);
        $stmt->bindParam(':frequency_interval', $data['frequency_interval']);
        $stmt->bindParam(':end_date', $data['end_date']);
        $stmt->bindParam(':max_occurrences', $data['max_occurrences']);
        $stmt->bindParam(':next_generation_date', $data['next_generation_date']);
        $stmt->bindParam(':is_active', $data['is_active'], PDO::PARAM_BOOL);
        
        return $stmt->execute();
    }

    // Calcular próxima data baseada na frequência
    public function calculateNextDate($currentDate, $frequencyType, $frequencyInterval = 1, $originalDate = null) {
        $date = new DateTime($currentDate);
        
        // Se não foi fornecida a data original, usa a data atual como referência
        $originalDay = $originalDate ? (int)(new DateTime($originalDate))->format('d') : (int)$date->format('d');
        
        switch ($frequencyType) {
            case 'semanal':
                $date->add(new DateInterval('P7D'));
                break;
            case 'mensal':
                $date = $this->addMonthsWithDayAdjustment($date, 1, $originalDay);
                break;
            case 'bimestral':
                $date = $this->addMonthsWithDayAdjustment($date, 2, $originalDay);
                break;
            case 'trimestral':
                $date = $this->addMonthsWithDayAdjustment($date, 3, $originalDay);
                break;
            case 'semestral':
                $date = $this->addMonthsWithDayAdjustment($date, 6, $originalDay);
                break;
            case 'anual':
                $date = $this->addMonthsWithDayAdjustment($date, 12, $originalDay);
                break;
            case 'personalizado':
                $date->add(new DateInterval('P' . $frequencyInterval . 'D'));
                break;
        }
        
        return $date->format('Y-m-d');
    }

    // Adicionar meses com ajuste de dia (antecipa quando o dia não existe no mês)
    private function addMonthsWithDayAdjustment($date, $months, $originalDay) {
        // Pega ano e mês atuais
        $year = (int)$date->format('Y');
        $month = (int)$date->format('m');
        
        // Adiciona os meses
        $month += $months;
        
        // Ajusta ano se necessário
        while ($month > 12) {
            $month -= 12;
            $year++;
        }
        
        // Verifica quantos dias tem o mês de destino
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        
        // Se o dia original não existe no mês de destino, usa o último dia do mês
        $targetDay = min($originalDay, $daysInMonth);
        
        // Cria a nova data
        $newDate = new DateTime();
        $newDate->setDate($year, $month, $targetDay);
        
        return $newDate;
    }

    // Buscar contas que precisam gerar recorrências
    public function getAccountsToGenerate($userId) {
        $query = "SELECT a.*, rs.*, c.name as category_name
                  FROM accounts a
                  INNER JOIN " . $this->table_name . " rs ON a.id = rs.account_id
                  LEFT JOIN categories c ON a.category_id = c.id
                  WHERE a.user_id = :user_id 
                  AND rs.is_active = 1
                  AND rs.next_generation_date <= CURDATE()
                  AND (rs.end_date IS NULL OR rs.next_generation_date <= rs.end_date)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Gerar próximas ocorrências de uma conta recorrente
    public function generateNextOccurrences($accountId, $userId, $monthsAhead = 12) {
        require_once 'models/Account.php';
        $accountModel = new Account();
        
        // Buscar conta original e configuração
        $account = $accountModel->getById($accountId, $userId);
        $setting = $this->getByAccountId($accountId);
        
        if (!$account || !$setting || !$setting['is_active']) {
            return false;
        }
        
        $generated = 0;
        $currentDate = $setting['next_generation_date'];
        $endDate = new DateTime();
        $endDate->add(new DateInterval('P' . $monthsAhead . 'M'));
        
        while (new DateTime($currentDate) <= $endDate) {
            // Verificar se deve parar por data final ou máximo de ocorrências
            if ($setting['end_date'] && new DateTime($currentDate) > new DateTime($setting['end_date'])) {
                break;
            }
            
            if ($setting['max_occurrences'] && $generated >= $setting['max_occurrences']) {
                break;
            }
            
            // Verificar se já existe uma conta para esta data
            $existingQuery = "SELECT COUNT(*) as count FROM accounts 
                             WHERE recurring_parent_id = :parent_id AND due_date = :due_date";
            $existingStmt = $this->conn->prepare($existingQuery);
            $existingStmt->bindParam(':parent_id', $accountId);
            $existingStmt->bindParam(':due_date', $currentDate);
            $existingStmt->execute();
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing['count'] == 0) {
                // Criar nova ocorrência
                $newAccountData = [
                    'user_id' => $account['user_id'],
                    'category_id' => $account['category_id'],
                    'description' => $account['description'],
                    'amount' => $account['amount'],
                    'due_date' => $currentDate,
                    'type' => $account['type'],
                    'status' => 'pendente',
                    'url' => $account['url'],
                    'is_recurring' => false,
                    'notes' => $account['notes']
                ];
                
                $newAccountId = $accountModel->create($newAccountData);
                
                if ($newAccountId) {
                    // Atualizar com parent_id
                    $updateQuery = "UPDATE accounts SET recurring_parent_id = :parent_id WHERE id = :id";
                    $updateStmt = $this->conn->prepare($updateQuery);
                    $updateStmt->bindParam(':parent_id', $accountId);
                    $updateStmt->bindParam(':id', $newAccountId);
                    $updateStmt->execute();
                    
                    $generated++;
                }
            }
            
            // Calcular próxima data (usando a data original da conta como referência)
            $currentDate = $this->calculateNextDate($currentDate, $setting['frequency_type'], $setting['frequency_interval'], $account['due_date']);
        }
        
        // Atualizar next_generation_date
        $this->updateNextGenerationDate($accountId, $currentDate);
        
        return $generated;
    }

    // Atualizar próxima data de geração
    public function updateNextGenerationDate($accountId, $nextDate) {
        $query = "UPDATE " . $this->table_name . " 
                  SET next_generation_date = :next_date, last_generated_date = CURDATE()
                  WHERE account_id = :account_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':account_id', $accountId);
        $stmt->bindParam(':next_date', $nextDate);
        
        return $stmt->execute();
    }

    // Desativar recorrência
    public function deactivate($accountId) {
        $query = "UPDATE " . $this->table_name . " 
                  SET is_active = 0 
                  WHERE account_id = :account_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':account_id', $accountId);
        
        return $stmt->execute();
    }

    // Deletar configuração
    public function delete($accountId) {
        $query = "DELETE FROM " . $this->table_name . " 
                  WHERE account_id = :account_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':account_id', $accountId);
        
        return $stmt->execute();
    }

    // Buscar todas as contas filhas de uma recorrência
    public function getChildAccounts($parentId, $userId) {
        $query = "SELECT a.*, c.name as category_name 
                  FROM accounts a
                  LEFT JOIN categories c ON a.category_id = c.id
                  WHERE a.recurring_parent_id = :parent_id AND a.user_id = :user_id
                  ORDER BY a.due_date";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':parent_id', $parentId);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Processar todas as recorrências pendentes para um usuário
    public function processUserRecurrences($userId) {
        $accountsToGenerate = $this->getAccountsToGenerate($userId);
        $totalGenerated = 0;
        
        foreach ($accountsToGenerate as $account) {
            $generated = $this->generateNextOccurrences($account['id'], $userId);
            $totalGenerated += $generated;
        }
        
        return $totalGenerated;
    }

    // Gerar projeções de contas recorrentes para um período específico
    public function generateProjections($userId, $days) {
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime("+{$days} days"));
        
        // Buscar contas recorrentes ativas
        $query = "SELECT a.*, c.name as category_name, rs.frequency_type, rs.frequency_interval, 
                         rs.end_date, rs.max_occurrences, rs.next_generation_date
                  FROM accounts a
                  INNER JOIN recurring_settings rs ON a.id = rs.account_id
                  LEFT JOIN categories c ON a.category_id = c.id
                  WHERE a.user_id = :user_id 
                    AND a.is_recurring = 1 
                    AND rs.is_active = 1
                    AND (rs.end_date IS NULL OR rs.end_date >= :start_date)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':start_date', $startDate);
        $stmt->execute();
        
        $recurringAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $projections = [];
        
        foreach ($recurringAccounts as $account) {
            $currentDate = max($startDate, $account['next_generation_date'] ?? $account['due_date']);
            $occurrenceCount = 0;
            
            while ($currentDate <= $endDate) {
                // Verificar se ainda pode gerar (limite de ocorrências)
                if ($account['max_occurrences'] && $occurrenceCount >= $account['max_occurrences']) {
                    break;
                }
                
                // Verificar se não passou da data final
                if ($account['end_date'] && $currentDate > $account['end_date']) {
                    break;
                }
                
                // Adicionar projeção
                $projections[] = [
                    'id' => 'proj_' . $account['id'] . '_' . $currentDate,
                    'user_id' => $userId,
                    'category_id' => $account['category_id'],
                    'category_name' => $account['category_name'],
                    'description' => $account['description'] . ' (Projetado)',
                    'amount' => $account['amount'],
                    'due_date' => $currentDate,
                    'type' => $account['type'],
                    'status' => 'pendente',
                    'url' => $account['url'],
                    'is_recurring' => true,
                    'is_projection' => true,
                    'parent_id' => $account['id']
                ];
                
                // Calcular próxima data (usando a data original da conta como referência)
                $currentDate = $this->calculateNextDate($currentDate, $account['frequency_type'], $account['frequency_interval'], $account['due_date']);
                $occurrenceCount++;
            }
        }
        
        return $projections;
    }

    // Buscar contas que precisam gerar recorrência
    public function getAccountsNeedingGeneration() {
        $today = date('Y-m-d');
        
        $query = "SELECT a.id as account_id, a.description, a.user_id, rs.next_generation_date
                  FROM accounts a
                  INNER JOIN recurring_settings rs ON a.id = rs.account_id
                  WHERE a.is_recurring = 1 
                    AND rs.is_active = 1
                    AND (rs.next_generation_date IS NULL OR rs.next_generation_date <= :today)
                    AND (rs.end_date IS NULL OR rs.end_date >= :today)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':today', $today);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Ativar recorrência
    public function activate($accountId) {
        $query = "UPDATE recurring_settings SET is_active = 1 WHERE account_id = :account_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':account_id', $accountId);
        return $stmt->execute();
    }

    // Método público para acessar a conexão (se necessário)
    public function getConnection() {
        return $this->conn;
    }
}
?>