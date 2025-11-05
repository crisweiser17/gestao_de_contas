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

    // Gerar instâncias recorrentes para os próximos meses (versão antiga - mantida para compatibilidade)
    public function generateRecurringInstances($accountId, $userId, $monthsAhead = 12) {
        return $this->generateRecurringInstancesAdvanced($accountId, $userId, $monthsAhead);
    }

    // Nova função para gerar instâncias com lógica avançada (até 120 meses)
    public function generateRecurringInstancesAdvanced($accountId, $userId, $monthsAhead = null) {
        $accountModel = new Account();
        
        // Buscar conta original e configuração
        $account = $accountModel->getById($accountId, $userId);
        $setting = $this->getByAccountId($accountId);
        
        if (!$account || !$setting || !$setting['is_active']) {
            return false;
        }

        // Determinar quantos meses gerar baseado na estratégia proposta
        if ($monthsAhead === null) {
            $monthsAhead = $this->calculateOptimalMonthsAhead($setting);
        }
        
        $generated = 0;
        $currentDate = $setting['next_generation_date'];
        $endDate = new DateTime();
        $endDate->add(new DateInterval('P' . $monthsAhead . 'M'));
        
        // Contador de ocorrências já geradas
        $existingCountQuery = "SELECT COUNT(*) as count FROM accounts 
                              WHERE recurring_parent_id = :parent_id";
        $existingCountStmt = $this->conn->prepare($existingCountQuery);
        $existingCountStmt->bindParam(':parent_id', $accountId);
        $existingCountStmt->execute();
        $existingCount = $existingCountStmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        while (new DateTime($currentDate) <= $endDate) {
            // Verificar se deve parar por data final
            if ($setting['end_date'] && new DateTime($currentDate) > new DateTime($setting['end_date'])) {
                break;
            }
            
            // Verificar se deve parar por máximo de ocorrências (incluindo já existentes)
            if ($setting['max_occurrences'] && ($existingCount + $generated) >= $setting['max_occurrences']) {
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

    // Calcular quantos meses gerar baseado na estratégia proposta
    private function calculateOptimalMonthsAhead($setting) {
        // Se tem data final, calcular quantos meses até lá
        if ($setting['end_date']) {
            $now = new DateTime();
            $endDate = new DateTime($setting['end_date']);
            $diff = $now->diff($endDate);
            $monthsUntilEnd = ($diff->y * 12) + $diff->m;
            
            // Se tem menos de 120 meses até o fim, gerar tudo
            if ($monthsUntilEnd <= 120) {
                return $monthsUntilEnd + 1; // +1 para garantir
            }
        }
        
        // Se tem máximo de ocorrências definido
        if ($setting['max_occurrences']) {
            // Calcular quantos meses seriam necessários baseado na frequência
            $monthsPerOccurrence = $this->getMonthsPerOccurrence($setting['frequency_type']);
            $totalMonths = $setting['max_occurrences'] * $monthsPerOccurrence;
            
            // Se tem menos de 120 meses de duração total, gerar tudo
            if ($totalMonths <= 120) {
                return $totalMonths + 12; // +12 para margem
            }
        }
        
        // Caso padrão: gerar 120 meses (10 anos)
        return 120;
    }

    // Obter quantos meses representa cada ocorrência baseado na frequência
    private function getMonthsPerOccurrence($frequencyType) {
        switch ($frequencyType) {
            case 'semanal':
                return 0.25; // 1 semana = ~0.25 mês
            case 'mensal':
                return 1;
            case 'bimestral':
                return 2;
            case 'trimestral':
                return 3;
            case 'semestral':
                return 6;
            case 'anual':
                return 12;
            default:
                return 1; // padrão mensal
        }
    }

    // Rotina principal para verificar e manter horizonte de geração
    public function maintainRecurringHorizon($userId = null) {
        $generated = 0;
        $processed = 0;
        
        // Buscar todas as contas recorrentes ativas
        $query = "SELECT a.id, a.user_id, rs.next_generation_date, rs.frequency_type, rs.end_date, rs.max_occurrences
                  FROM accounts a 
                  INNER JOIN recurring_settings rs ON a.id = rs.account_id 
                  WHERE a.is_recurring = 1 AND rs.is_active = 1";
        
        if ($userId) {
            $query .= " AND a.user_id = :user_id";
        }
        
        $stmt = $this->conn->prepare($query);
        if ($userId) {
            $stmt->bindParam(':user_id', $userId);
        }
        $stmt->execute();
        $recurringAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($recurringAccounts as $account) {
            $processed++;
            
            // Verificar se precisa gerar mais instâncias
            if ($this->needsMoreInstances($account['id'], $account)) {
                $generatedForAccount = $this->generateRecurringInstancesAdvanced(
                    $account['id'], 
                    $account['user_id']
                );
                
                if ($generatedForAccount !== false) {
                    $generated += $generatedForAccount;
                }
            }
        }
        
        return [
            'processed' => $processed,
            'generated' => $generated
        ];
    }

    // Verificar se uma conta precisa de mais instâncias geradas
    private function needsMoreInstances($accountId, $accountData) {
        // Verificar qual é a data mais distante já gerada
        $query = "SELECT MAX(due_date) as max_date FROM accounts 
                  WHERE recurring_parent_id = :parent_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':parent_id', $accountId);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $maxGeneratedDate = $result['max_date'];
        
        // Se não tem nenhuma instância gerada, precisa gerar
        if (!$maxGeneratedDate) {
            return true;
        }
        
        // Calcular quantos meses à frente temos gerado
        $now = new DateTime();
        $maxDate = new DateTime($maxGeneratedDate);
        $diff = $now->diff($maxDate);
        $monthsAhead = ($diff->y * 12) + $diff->m;
        
        // Se tem menos de 60 meses à frente, precisa gerar mais
        if ($monthsAhead < 60) {
            return true;
        }
        
        // Verificar se a conta tem limitações que podem ter sido atingidas
        if ($accountData['end_date']) {
            $endDate = new DateTime($accountData['end_date']);
            // Se a data final é antes do nosso horizonte atual, não precisa gerar
            if ($endDate <= $maxDate) {
                return false;
            }
        }
        
        if ($accountData['max_occurrences']) {
            // Contar quantas instâncias já foram geradas
            $countQuery = "SELECT COUNT(*) as count FROM accounts 
                          WHERE recurring_parent_id = :parent_id";
            $countStmt = $this->conn->prepare($countQuery);
            $countStmt->bindParam(':parent_id', $accountId);
            $countStmt->execute();
            $count = $countStmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            // Se já atingiu o máximo, não precisa gerar
            if ($count >= $accountData['max_occurrences']) {
                return false;
            }
        }
        
        return false;
    }

    // Função para executar manutenção leve (chamada em page loads)
    public function lightMaintenance($userId) {
        // Verificar apenas algumas contas por vez para não sobrecarregar
        $query = "SELECT a.id, a.user_id, rs.next_generation_date, rs.frequency_type, rs.end_date, rs.max_occurrences
                  FROM accounts a 
                  INNER JOIN recurring_settings rs ON a.id = rs.account_id 
                  WHERE a.is_recurring = 1 AND rs.is_active = 1 AND a.user_id = :user_id
                  ORDER BY rs.last_generated_date ASC 
                  LIMIT 3"; // Processar apenas 3 contas por vez
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $generated = 0;
        foreach ($accounts as $account) {
            if ($this->needsMoreInstances($account['id'], $account)) {
                $generatedForAccount = $this->generateRecurringInstancesAdvanced(
                    $account['id'], 
                    $account['user_id']
                );
                
                if ($generatedForAccount !== false) {
                    $generated += $generatedForAccount;
                }
            }
        }
        
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

                // Deduplicar: pular projeção se já existe ocorrência real para esta data
                $existsQuery = "SELECT COUNT(*) as count FROM accounts 
                                WHERE user_id = :user_id AND recurring_parent_id = :parent_id AND due_date = :due_date";
                $existsStmt = $this->conn->prepare($existsQuery);
                $existsStmt->bindParam(':user_id', $userId);
                $existsStmt->bindParam(':parent_id', $account['id']);
                $existsStmt->bindParam(':due_date', $currentDate);
                $existsStmt->execute();
                $exists = $existsStmt->fetch(PDO::FETCH_ASSOC);

                if (($exists['count'] ?? 0) == 0) {
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
                }

                // Próxima data e incremento
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
                    AND (rs.next_generation_date IS NULL OR rs.next_generation_date <= ?)
                    AND (rs.end_date IS NULL OR rs.end_date >= ?)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$today, $today]);
        
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