<?php
require_once 'config/config.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>🔧 Corrigindo TODAS as Instâncias de Contas Recorrentes</h2>";
    
    // Lista de palavras-chave que indicam contas recorrentes
    $recurring_keywords = [
        'Contabilidade', 'AMX', 'Consórcios', 'Condomínio', 'Aluguel', 
        'Seguro', 'Internet', 'Telefone', 'Energia', 'Água', 'Gás',
        'Netflix', 'Spotify', 'Amazon', 'Mensalidade', 'Assinatura',
        'Plano', 'Saúde', 'Odontológico', 'Sulamerica'
    ];
    
    echo "<h3>📋 Palavras-chave utilizadas:</h3>";
    echo "<p>" . implode(', ', $recurring_keywords) . "</p>";
    
    // Construir condições WHERE para cada palavra-chave
    $keyword_conditions = [];
    foreach ($recurring_keywords as $keyword) {
        $keyword_conditions[] = "description LIKE '%$keyword%'";
    }
    $where_clause = implode(' OR ', $keyword_conditions);
    
    // Primeiro, vamos ver quantas contas serão afetadas
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM accounts 
        WHERE user_id = 1 AND ($where_clause) AND is_recurring = 0
    ");
    $stmt->execute();
    $count_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_to_update = $count_result['total'];
    
    echo "<h3>📊 Análise antes da correção:</h3>";
    echo "<p><strong>Contas que serão marcadas como recorrentes:</strong> $total_to_update</p>";
    
    if ($total_to_update > 0) {
        // Mostrar quais contas serão afetadas
        $stmt = $pdo->prepare("
            SELECT id, description, created_at
            FROM accounts 
            WHERE user_id = 1 AND ($where_clause) AND is_recurring = 0
            ORDER BY description, created_at
        ");
        $stmt->execute();
        $accounts_to_update = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h4>🔍 Contas que serão corrigidas:</h4>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr style='background-color: #f0f0f0;'>";
        echo "<th>ID</th><th>Descrição</th><th>Data Criação</th>";
        echo "</tr>";
        
        foreach ($accounts_to_update as $account) {
            echo "<tr>";
            echo "<td>{$account['id']}</td>";
            echo "<td>{$account['description']}</td>";
            echo "<td>{$account['created_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Agora fazer a atualização
        echo "<h3>🚀 Executando correção...</h3>";
        
        $update_stmt = $pdo->prepare("
            UPDATE accounts 
            SET is_recurring = 1 
            WHERE user_id = 1 AND ($where_clause) AND is_recurring = 0
        ");
        
        $result = $update_stmt->execute();
        $updated_rows = $update_stmt->rowCount();
        
        if ($result) {
            echo "<p style='color: green; font-weight: bold;'>✅ Sucesso! $updated_rows contas foram marcadas como recorrentes.</p>";
        } else {
            echo "<p style='color: red; font-weight: bold;'>❌ Erro ao atualizar as contas.</p>";
        }
        
    } else {
        echo "<p style='color: blue;'>ℹ️ Todas as contas já estão corretamente marcadas como recorrentes!</p>";
    }
    
    // Verificação final
    echo "<h3>🔍 Verificação final:</h3>";
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total_recurring
        FROM accounts 
        WHERE user_id = 1 AND ($where_clause) AND is_recurring = 1
    ");
    $stmt->execute();
    $final_count = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<p><strong>Total de contas recorrentes após correção:</strong> {$final_count['total_recurring']}</p>";
    
    // Mostrar algumas contas para confirmar
    $stmt = $pdo->prepare("
        SELECT id, description, is_recurring, created_at
        FROM accounts 
        WHERE user_id = 1 AND ($where_clause)
        ORDER BY description, created_at
        LIMIT 20
    ");
    $stmt->execute();
    $sample_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h4>📋 Amostra das contas (primeiras 20):</h4>";
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background-color: #f0f0f0;'>";
    echo "<th>ID</th><th>Descrição</th><th>is_recurring</th><th>Status</th>";
    echo "</tr>";
    
    foreach ($sample_accounts as $account) {
        $bg_color = $account['is_recurring'] == 1 ? '#d4edda' : '#f8d7da';
        $status = $account['is_recurring'] == 1 ? '✅ Recorrente' : '❌ Não Recorrente';
        
        echo "<tr style='background-color: $bg_color;'>";
        echo "<td>{$account['id']}</td>";
        echo "<td>{$account['description']}</td>";
        echo "<td style='text-align: center;'>{$account['is_recurring']}</td>";
        echo "<td>$status</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h3>🎯 Próximo passo:</h3>";
    echo "<p>Agora todas as instâncias das contas recorrentes devem exibir o ícone azul! 🔄</p>";
    echo "<p><a href='accounts.php' target='_blank'>➡️ Clique aqui para testar a página de contas</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Erro: " . $e->getMessage() . "</p>";
}
?>