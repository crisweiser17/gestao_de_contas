<?php
require_once 'config/config.php';
require_once 'models/Account.php';

// Se não estiver logado, redireciona para login
if (!isLoggedIn()) {
    redirect('login.php');
}

$accountModel = new Account();
$userId = $_SESSION['user_id'];

echo "<h1>Status Update Test</h1>";

// Get all accounts for this user
$accounts = $accountModel->getWithFilters($userId);

echo "<h2>Current Accounts:</h2>";
echo "<table border='1'>";
echo "<tr><th>ID</th><th>Description</th><th>Current Status</th><th>Actions</th></tr>";

foreach ($accounts as $account) {
    echo "<tr>";
    echo "<td>{$account['id']}</td>";
    echo "<td>{$account['description']}</td>";
    echo "<td>{$account['status']}</td>";
    echo "<td>";
    echo "<a href='?test_update={$account['id']}&new_status=pendente'>Set Pendente</a> | ";
    echo "<a href='?test_update={$account['id']}&new_status=paga'>Set Paga</a> | ";
    echo "<a href='?test_update={$account['id']}&new_status=recebida'>Set Recebida</a>";
    echo "</td>";
    echo "</tr>";
}
echo "</table>";

// Handle test update
if (isset($_GET['test_update']) && isset($_GET['new_status'])) {
    $accountId = $_GET['test_update'];
    $newStatus = $_GET['new_status'];
    
    echo "<h2>Test Update Results:</h2>";
    
    // Get current status
    $currentAccount = $accountModel->getById($accountId, $userId);
    $oldStatus = $currentAccount ? $currentAccount['status'] : 'unknown';
    
    echo "<p>Account ID: {$accountId}</p>";
    echo "<p>Old Status: {$oldStatus}</p>";
    echo "<p>Attempting to set status to: {$newStatus}</p>";
    
    // Update status
    $result = $accountModel->updateStatus($accountId, $newStatus, $userId);
    
    if ($result) {
        echo "<p style='color: green;'>✓ Update successful</p>";
        
        // Verify the update
        $updatedAccount = $accountModel->getById($accountId, $userId);
        $actualStatus = $updatedAccount ? $updatedAccount['status'] : 'unknown';
        
        echo "<p>Actual status after update: {$actualStatus}</p>";
        
        if ($actualStatus === $newStatus) {
            echo "<p style='color: green;'>✓ Status correctly updated</p>";
        } else {
            echo "<p style='color: red;'>✗ Status was not updated correctly!</p>";
        }
    } else {
        echo "<p style='color: red;'>✗ Update failed</p>";
    }
    
    echo "<p><a href='test_status_update.php'>Refresh to see current status</a></p>";
}
?>