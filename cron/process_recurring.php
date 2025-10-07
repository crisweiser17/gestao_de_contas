<?php
/**
 * Script para processar contas recorrentes
 * Deve ser executado via cron job diariamente
 * 
 * Exemplo de cron job (executar todo dia às 6h):
 * 0 6 * * * /usr/bin/php /caminho/para/o/projeto/cron/process_recurring.php
 */

// Incluir configurações
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/models/RecurringSetting.php';
require_once dirname(__DIR__) . '/models/Account.php';
require_once dirname(__DIR__) . '/models/User.php';

// Log de execução
function logMessage($message) {
    $logFile = dirname(__DIR__) . '/logs/recurring_' . date('Y-m-d') . '.log';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND | LOCK_EX);
    echo "[$timestamp] $message\n";
}

try {
    logMessage("Iniciando processamento de contas recorrentes...");
    
    $recurringModel = new RecurringSetting();
    $accountModel = new Account();
    
    // Buscar todas as contas que precisam gerar recorrência
    $accountsToProcess = $recurringModel->getAccountsNeedingGeneration();
    
    logMessage("Encontradas " . count($accountsToProcess) . " contas para processar");
    
    $totalGenerated = 0;
    $errors = 0;
    
    foreach ($accountsToProcess as $account) {
        try {
            logMessage("Processando conta ID {$account['account_id']} - {$account['description']}");
            
            // Gerar próximas ocorrências
            $generated = $recurringModel->generateNextOccurrences($account['account_id'], $account['user_id']);
            
            if ($generated > 0) {
                $totalGenerated += $generated;
                logMessage("Geradas $generated ocorrências para a conta {$account['account_id']}");
            } else {
                logMessage("Nenhuma ocorrência gerada para a conta {$account['account_id']}");
            }
            
        } catch (Exception $e) {
            $errors++;
            logMessage("ERRO ao processar conta {$account['account_id']}: " . $e->getMessage());
        }
    }
    
    // Limpar contas antigas (opcional - manter apenas últimos 2 anos)
    $cutoffDate = date('Y-m-d', strtotime('-2 years'));
    $cleanupResult = $accountModel->cleanupOldAccounts($cutoffDate);
    
    if ($cleanupResult > 0) {
        logMessage("Removidas $cleanupResult contas antigas (anteriores a $cutoffDate)");
    }
    
    logMessage("Processamento concluído:");
    logMessage("- Total de ocorrências geradas: $totalGenerated");
    logMessage("- Erros encontrados: $errors");
    logMessage("- Contas antigas removidas: " . ($cleanupResult ?? 0));
    
} catch (Exception $e) {
    logMessage("ERRO CRÍTICO: " . $e->getMessage());
    logMessage("Stack trace: " . $e->getTraceAsString());
    exit(1);
}

logMessage("Script finalizado com sucesso");
exit(0);
?>