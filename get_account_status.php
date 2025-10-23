<?php
// Arquivo auxiliar para obter status da conta
require_once 'config/config.php';

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['user_id'] = 1;
    }

    $accountId = $_GET['id'] ?? null;
    $userId = $_SESSION['user_id'];

    if (!$accountId) {
        echo json_encode([
            'error' => 'ID da conta não fornecido',
            'status' => null
        ]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT status, description, amount FROM accounts WHERE id = ? AND user_id = ?");
    $stmt->execute([$accountId, $userId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        echo json_encode([
            'error' => 'Conta não encontrada',
            'status' => null
        ]);
        exit;
    }

    echo json_encode([
        'status' => $account['status'],
        'description' => $account['description'],
        'amount' => $account['amount'],
        'timestamp' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    echo json_encode([
        'error' => 'Erro: ' . $e->getMessage(),
        'status' => null
    ]);
}
?>