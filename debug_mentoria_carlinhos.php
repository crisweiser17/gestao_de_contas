<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/models/RecurringSetting.php';

$pdo = (new Database())->getConnection();
$recModel = new RecurringSetting();

$query = "SELECT * FROM accounts WHERE description LIKE :desc ORDER BY id DESC";
$stmt = $pdo->prepare($query);
$like = '%Mentoria%Carlinhos%';
$stmt->execute([':desc' => $like]);
$accounts = $stmt->fetchAll();

header('Content-Type: text/html; charset=utf-8');
echo '<html><head><meta charset="utf-8"><title>Diagnóstico Mentoria - Carlinhos</title>';
echo '<style>body{font-family: system-ui, sans-serif;padding:16px;} table{border-collapse:collapse;width:100%;} th,td{border:1px solid #ddd;padding:8px;font-size:12px;} th{background:#f5f5f5;text-align:left} code{background:#f2f2f2;padding:2px 4px;border-radius:4px}</style></head><body>';

echo '<h2>Diagnóstico: Mentoria - parte Carlinhos</h2>';

echo '<p>Encontrados ' . count($accounts) . ' registros com descrição semelhante.</p>';

echo '<table><thead><tr>';
echo '<th>ID</th><th>User</th><th>Type</th><th>Status</th><th>is_recurring</th><th>recurring_parent_id</th><th>ParentId</th><th>max_occurrences</th><th>end_date</th><th>generated_children</th><th>remaining_calc</th>';
echo '</tr></thead><tbody>';

foreach ($accounts as $acc) {
    $parentId = !empty($acc['recurring_parent_id']) ? $acc['recurring_parent_id'] : ((int)$acc['is_recurring'] === 1 ? $acc['id'] : null);
    $maxOcc = null; $endDate = null; $generated = null; $remaining = null;
    if ($parentId) {
        $recSet = $recModel->getByAccountId($parentId);
        if ($recSet) {
            $maxOcc = (int)($recSet['max_occurrences'] ?? 0);
            $endDate = $recSet['end_date'] ?? null;
        }
        $stmtC = $pdo->prepare('SELECT COUNT(*) FROM accounts WHERE recurring_parent_id = :pid');
        $stmtC->execute([':pid' => $parentId]);
        $generated = (int)$stmtC->fetchColumn();
        if (($maxOcc <= 0) && empty($endDate)) {
            $remaining = 'indefinido';
        } elseif ($maxOcc > 0) {
            $remaining = max($maxOcc - $generated, 0);
        } else {
            $remaining = 'indefinido';
        }
    }
    echo '<tr>';
    echo '<td>' . htmlspecialchars($acc['id']) . '</td>';
    echo '<td>' . htmlspecialchars($acc['user_id']) . '</td>';
    echo '<td>' . htmlspecialchars($acc['type']) . '</td>';
    echo '<td>' . htmlspecialchars($acc['status']) . '</td>';
    echo '<td>' . htmlspecialchars($acc['is_recurring']) . '</td>';
    echo '<td>' . htmlspecialchars($acc['recurring_parent_id']) . '</td>';
    echo '<td>' . htmlspecialchars($parentId ?? '') . '</td>';
    echo '<td>' . htmlspecialchars($maxOcc ?? '') . '</td>';
    echo '<td>' . htmlspecialchars($endDate ?? '') . '</td>';
    echo '<td>' . htmlspecialchars($generated ?? '') . '</td>';
    echo '<td>' . htmlspecialchars($remaining ?? '') . '</td>';
    echo '</tr>';
}

echo '</tbody></table>';

echo '<p>Dica: se estiver mostrando 0 mas deveria ser 10, verifique se já existem 10 instâncias geradas para este pai (coluna generated_children) ou se max_occurrences está correto.</p>';

echo '</body></html>';