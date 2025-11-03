<?php
require_once 'config/config.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>🔍 Debug: Todas as Instâncias de Contas Recorrentes</h2>";
    
    // Buscar todas as contas com descrições que deveriam ser recorrentes
    $recurring_keywords = [
        'Contabilidade', 'AMX', 'Consórcios', 'Condomínio', 'Aluguel', 
        'Seguro', 'Internet', 'Telefone', 'Energia', 'Água', 'Gás',
        'Netflix', 'Spotify', 'Amazon', 'Mensalidade', 'Assinatura'
    ];
    
    $keyword_conditions = [];
    foreach ($recurring_keywords as $keyword) {
        $keyword_conditions[] = "description LIKE '%$keyword%'";
    }
    $where_clause = implode(' OR ', $keyword_conditions);
    
    $stmt = $pdo->prepare("
        SELECT id, description, is_recurring, created_at, user_id
        FROM accounts 
        WHERE user_id = 1 AND ($where_clause)
        ORDER BY description, created_at
    ");
    $stmt->execute();
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>📊 Contas que deveriam ser recorrentes:</h3>";
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background-color: #f0f0f0;'>";
    echo "<th>ID</th><th>Descrição</th><th>is_recurring</th><th>Data Criação</th><th>Status</th>";
    echo "</tr>";
    
    $grouped_accounts = [];
    foreach ($accounts as $account) {
        $base_description = $account['description'];
        if (!isset($grouped_accounts[$base_description])) {
            $grouped_accounts[$base_description] = [];
        }
        $grouped_accounts[$base_description][] = $account;
    }
    
    $total_should_be_recurring = 0;
    $total_marked_as_recurring = 0;
    
    foreach ($grouped_accounts as $description => $instances) {
        $first_row = true;
        foreach ($instances as $account) {
            $total_should_be_recurring++;
            if ($account['is_recurring'] == 1) {
                $total_marked_as_recurring++;
            }
            
            $bg_color = $account['is_recurring'] == 1 ? '#d4edda' : '#f8d7da';
            $status = $account['is_recurring'] == 1 ? '✅ Recorrente' : '❌ Não Recorrente';
            
            echo "<tr style='background-color: $bg_color;'>";
            echo "<td>{$account['id']}</td>";
            echo "<td>" . ($first_row ? "<strong>$description</strong>" : "↳ $description") . "</td>";
            echo "<td style='text-align: center;'>{$account['is_recurring']}</td>";
            echo "<td>{$account['created_at']}</td>";
            echo "<td>$status</td>";
            echo "</tr>";
            
            $first_row = false;
        }
        echo "<tr><td colspan='5' style='height: 5px; background-color: #fff;'></td></tr>";
    }
    
    echo "</table>";
    
    echo "<h3>📈 Resumo:</h3>";
    echo "<p><strong>Total de contas que deveriam ser recorrentes:</strong> $total_should_be_recurring</p>";
    echo "<p><strong>Total marcadas como recorrentes:</strong> $total_marked_as_recurring</p>";
    echo "<p><strong>Contas não marcadas:</strong> " . ($total_should_be_recurring - $total_marked_as_recurring) . "</p>";
    
    // Mostrar contas agrupadas por descrição
    echo "<h3>🔍 Análise por Grupo:</h3>";
    foreach ($grouped_accounts as $description => $instances) {
        $recurring_count = 0;
        foreach ($instances as $instance) {
            if ($instance['is_recurring'] == 1) {
                $recurring_count++;
            }
        }
        
        $total_instances = count($instances);
        $status_icon = ($recurring_count == $total_instances) ? '✅' : 
                      (($recurring_count > 0) ? '⚠️' : '❌');
        
        echo "<p>$status_icon <strong>$description:</strong> $recurring_count/$total_instances instâncias marcadas como recorrentes</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Erro: " . $e->getMessage() . "</p>";
}
?>