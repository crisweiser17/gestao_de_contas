<?php
require_once 'config/config.php';

// Se não estiver logado, redireciona para login
if (!isLoggedIn()) {
    redirect('login.php');
}

require_once 'models/Account.php';
require_once 'models/Category.php';
require_once 'models/RecurringSetting.php';

$accountModel = new Account($pdo);
$categoryModel = new Category($pdo);
$recurringModel = new RecurringSetting();

$userId = $_SESSION['user_id'];
// Validar se o usuário da sessão existe; ajustar se necessário
try {
    if (!$categoryModel->userExists($userId)) {
        $userId = $categoryModel->getValidUserId();
        $_SESSION['user_id'] = $userId;
    }
} catch (Exception $e) {
    // Se ocorrer algum erro, tentar usar um user_id válido
    try {
        $userId = $categoryModel->getValidUserId();
        $_SESSION['user_id'] = $userId;
    } catch (Exception $e2) {
        // Como último recurso, redirecionar para login
        redirect('login.php');
    }
}
$action = $_GET['action'] ?? 'list';
$accountId = $_GET['id'] ?? null;

$errors = [];
$success = '';

// Processar ações
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Override action and accountId from POST if available
    $action = $_POST['action'] ?? $action;
    $accountId = $_POST['id'] ?? $accountId;
    if ($action == 'add' || $action == 'edit') {
        $data = [
            'user_id' => $userId,
            'category_id' => $_POST['category_id'] ?? '',
            'description' => trim($_POST['description'] ?? ''),
            'amount' => str_replace(',', '.', $_POST['amount'] ?? ''),
            'due_date' => $_POST['due_date'] ?? '',
            'type' => $_POST['type'] ?? '',
            'status' => $_POST['status'] ?? 'pendente',
            'url' => trim($_POST['url'] ?? ''),
            'is_recurring' => isset($_POST['is_recurring']),
            'notes' => trim($_POST['notes'] ?? '')
        ];

        // Validação
        if (empty($data['description'])) {
            $errors[] = 'Descrição é obrigatória';
        }
        if (empty($data['amount']) || !is_numeric($data['amount']) || $data['amount'] <= 0) {
            $errors[] = 'Valor deve ser um número positivo';
        }
        if (empty($data['due_date'])) {
            $errors[] = 'Data de vencimento é obrigatória';
        }
        if (empty($data['category_id'])) {
            $errors[] = 'Categoria é obrigatória';
        }
        if (empty($data['type']) || !in_array($data['type'], ['receita', 'despesa'])) {
            $errors[] = 'Tipo deve ser receita ou despesa';
        }

        // Processar upload de arquivo
        $attachmentPath = null;
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/attachments/';
            $allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
            $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
            $maxFileSize = 5 * 1024 * 1024; // 5MB

            $fileInfo = $_FILES['attachment'];
            $fileName = $fileInfo['name'];
            $fileSize = $fileInfo['size'];
            $fileTmpName = $fileInfo['tmp_name'];
            $fileType = $fileInfo['type'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            // Validações do arquivo
            if (!in_array($fileExtension, $allowedExtensions)) {
                $errors[] = 'Tipo de arquivo não permitido. Use: PDF, JPG, PNG';
            }
            if ($fileSize > $maxFileSize) {
                $errors[] = 'Arquivo muito grande. Máximo 5MB';
            }
            if (!in_array($fileType, $allowedTypes)) {
                $errors[] = 'Tipo MIME não permitido';
            }

            if (empty($errors)) {
                // Gerar nome único para o arquivo
                $uniqueName = uniqid() . '_' . time() . '.' . $fileExtension;
                $targetPath = $uploadDir . $uniqueName;

                // Criar diretório se não existir
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                // Mover arquivo
                if (move_uploaded_file($fileTmpName, $targetPath)) {
                    $attachmentPath = $targetPath;
                } else {
                    $errors[] = 'Erro ao fazer upload do arquivo';
                }
            }
        }

        // Adicionar attachment aos dados se foi feito upload
        if ($attachmentPath) {
            $data['attachment'] = $attachmentPath;
        } elseif ($action == 'edit' && !empty($account['attachment'])) {
            // Manter attachment existente se não foi enviado novo arquivo
            $data['attachment'] = $account['attachment'];
        }

        if (empty($errors)) {
            if ($action == 'add') {
                $newAccountId = $accountModel->create($data);
                if ($newAccountId) {
                    // Se é recorrente, criar configuração
                    if ($data['is_recurring']) {
                        $recurringData = [
                            'account_id' => $newAccountId,
                            'frequency_type' => $_POST['frequency_type'] ?? 'mensal',
                            'frequency_interval' => $_POST['frequency_interval'] ?? 1,
                            'end_date' => !empty($_POST['end_date']) ? $_POST['end_date'] : null,
                            'max_occurrences' => !empty($_POST['max_occurrences']) ? $_POST['max_occurrences'] : null,
                            'next_generation_date' => $recurringModel->calculateNextDate($data['due_date'], $_POST['frequency_type'] ?? 'mensal', $_POST['frequency_interval'] ?? 1)
                        ];
                        $recurringModel->create($recurringData);
                    }
                    
                    $success = 'Conta criada com sucesso!';
                    $action = 'list';
                } else {
                    $errors[] = 'Erro ao criar conta';
                }
            } else if ($action == 'edit' && $accountId) {
                if ($accountModel->update($accountId, $data, $userId)) {
                    // Gerenciar configuração de recorrência ao editar
                    $existingSetting = $recurringModel->getByAccountId($accountId);
                    if ($data['is_recurring']) {
                        $freqType = $_POST['frequency_type'] ?? ($existingSetting['frequency_type'] ?? 'mensal');
                        $freqInterval = $_POST['frequency_interval'] ?? ($existingSetting['frequency_interval'] ?? 1);
                        $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : ($existingSetting['end_date'] ?? null);
                        $maxOcc = !empty($_POST['max_occurrences']) ? $_POST['max_occurrences'] : ($existingSetting['max_occurrences'] ?? null);
                        $nextGen = $recurringModel->calculateNextDate($data['due_date'], $freqType, $freqInterval);

                        $recData = [
                            'frequency_type' => $freqType,
                            'frequency_interval' => $freqInterval,
                            'end_date' => $endDate,
                            'max_occurrences' => $maxOcc,
                            'next_generation_date' => $nextGen,
                            'is_active' => true
                        ];

                        if ($existingSetting) {
                            $recurringModel->update($accountId, $recData);
                        } else {
                            $recurringModel->create(array_merge($recData, ['account_id' => $accountId]));
                            $recurringModel->activate($accountId);
                        }
                    } else if ($existingSetting) {
                        // Desativar recorrência quando desmarcada
                        $recurringModel->deactivate($accountId);
                    }

                    $success = 'Conta atualizada com sucesso!';
                    $action = 'list';
                } else {
                    $errors[] = 'Erro ao atualizar conta';
                }
            }
        }
    } else if ($action == 'update_status' && $accountId) {
        $status = $_POST['status'] ?? '';
        
        // Debug: Log all data
        $debugInfo = "DEBUG - Action: {$action} | ";
        $debugInfo .= "Account ID: {$accountId} | ";
        $debugInfo .= "User ID: {$userId} | ";
        $debugInfo .= "New Status: {$status} | ";
        $debugInfo .= "POST data: " . json_encode($_POST) . " | ";
        $debugInfo .= "GET data: " . json_encode($_GET) . " | ";
        
        if (empty($accountId)) {
            $errors[] = "ERRO: Account ID está vazio!";
        } elseif (empty($status)) {
            $errors[] = "ERRO: Status está vazio!";
        } elseif (!in_array($status, ['pendente', 'paga', 'recebida'])) {
            $errors[] = "ERRO: Status inválido: '{$status}'. Valores aceitos: pendente, paga, recebida";
        } else {
            // Get current status before update
            $currentAccount = $accountModel->getById($accountId, $userId);
            if (!$currentAccount) {
                $errors[] = "ERRO: Conta não encontrada (ID: {$accountId}, User: {$userId})";
            } else {
                $oldStatus = $currentAccount['status'];
                $debugInfo .= "Old Status: {$oldStatus} | ";
                
                $updateResult = $accountModel->updateStatus($accountId, $status, $userId);
                $debugInfo .= "Update Result: " . ($updateResult ? 'SUCCESS' : 'FAILED') . " | ";
                
                if ($updateResult) {
                    // Verify the update immediately
                    $verifyAccount = $accountModel->getById($accountId, $userId);
                    $actualStatus = $verifyAccount ? $verifyAccount['status'] : 'unknown';
                    $debugInfo .= "Verified Status: {$actualStatus} | ";
                    
                    if ($actualStatus === $status) {
                        $success = "✅ Status atualizado com sucesso: {$oldStatus} → {$actualStatus}";
                    } else {
                        $errors[] = "❌ PROBLEMA: Status não foi salvo! Esperado: {$status}, Atual: {$actualStatus}";
                    }
                } else {
                    $errors[] = '❌ Erro ao executar UPDATE no banco de dados';
                }
            }
        }
        
        // Log debug info to error log only
        error_log("STATUS UPDATE DEBUG: {$debugInfo}");
        
        $action = 'list';
    } else if ($action == 'delete' && $accountId) {
        if ($accountModel->delete($accountId, $userId)) {
            $success = 'Conta excluída com sucesso!';
        } else {
            $errors[] = 'Erro ao excluir conta';
        }
        $action = 'list';
    }
}

// Buscar dados para exibição
$categories = $categoryModel->getByUserId($userId);

// Preservar filtros após POST (quando vêm dos campos hidden do formulário)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $filters = [
        'category_id' => $_POST['filter_category_id'] ?? $_GET['filter_category'] ?? '',
        'type' => $_POST['filter_type'] ?? $_GET['filter_type'] ?? '',
        'status' => $_POST['filter_status'] ?? $_GET['filter_status'] ?? '',
        'date_from' => $_POST['filter_date_from'] ?? $_GET['filter_date_from'] ?? '',
        'date_to' => $_POST['filter_date_to'] ?? $_GET['filter_date_to'] ?? ''
    ];
} else {
    $filters = [
        'category_id' => $_GET['filter_category'] ?? '',
        'type' => $_GET['filter_type'] ?? '',
        'status' => $_GET['filter_status'] ?? '',
        'date_from' => $_GET['filter_date_from'] ?? '',
        'date_to' => $_GET['filter_date_to'] ?? ''
    ];
}

// Parâmetros de ordenação (preservar após POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $sortBy = $_POST['sort_by'] ?? $_GET['sort_by'] ?? 'due_date';
    $sortOrder = $_POST['sort_order'] ?? $_GET['sort_order'] ?? 'DESC';
} else {
    $sortBy = $_GET['sort_by'] ?? 'due_date';
    $sortOrder = $_GET['sort_order'] ?? 'DESC';
}

// Validar parâmetros de ordenação
$validSortColumns = ['due_date', 'status', 'description', 'amount'];
if (!in_array($sortBy, $validSortColumns)) {
    $sortBy = 'due_date';
}

$validSortOrders = ['ASC', 'DESC'];
if (!in_array(strtoupper($sortOrder), $validSortOrders)) {
    $sortOrder = 'DESC';
}

if ($action == 'list') {
    // Buscar contas a pagar (despesas)
    $filtersExpenses = array_merge(array_filter($filters), ['type' => 'despesa']);
    $accountsExpenses = $accountModel->getWithFilters($userId, $filtersExpenses, $sortBy, $sortOrder);
    
    // Buscar contas a receber (receitas)
    $filtersRevenues = array_merge(array_filter($filters), ['type' => 'receita']);
    $accountsRevenues = $accountModel->getWithFilters($userId, $filtersRevenues, $sortBy, $sortOrder);
} else if ($action == 'edit' && $accountId) {
    $account = $accountModel->getById($accountId, $userId);
    $recurringSetting = $recurringModel->getByAccountId($accountId);
    if (!$account) {
        $action = 'list';
        $errors[] = 'Conta não encontrada';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Contas</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#1e40af',
                        secondary: '#64748b'
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50">
    <?php require_once 'partials/header.php'; render_header('accounts'); ?>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Mensagens -->
        <?php if (!empty($errors)): ?>
        <div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded">
            <ul class="list-disc list-inside">
                <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded">
            <i class="fas fa-check-circle mr-2"></i>
            <?= htmlspecialchars($success) ?>
        </div>
        <?php endif; ?>

        <?php if ($action == 'list'): ?>
        <!-- Botão Nova Conta -->
        <div class="mb-6 flex justify-end">
            <a href="?action=add" class="bg-primary hover:bg-blue-700 text-white px-4 py-2 rounded-md transition-colors">
                <i class="fas fa-plus mr-2"></i>
                Nova Conta
            </a>
        </div>

        <!-- Filtros Globais -->
        <div class="bg-white rounded-lg shadow mb-6">
            <div class="p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">
                    <i class="fas fa-filter mr-2"></i>
                    Filtros
                </h3>
                <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <input type="hidden" name="action" value="list">
                    
                    <select name="filter_category" class="border border-gray-300 rounded-md px-3 h-10 w-full">
                        <option value="">Todas as categorias</option>
                        <?php foreach ($categories as $category): ?>
                        <option value="<?= $category['id'] ?>" <?= $filters['category_id'] == $category['id'] ? 'selected' : '' ?>>
                            <?= (($category['type'] ?? '') === 'receita' ? '+' : '-') . ' ' . htmlspecialchars($category['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="filter_status" class="border border-gray-300 rounded-md px-3 h-10 w-full">
                        <option value="">Todos os status</option>
                        <option value="pendente" <?= $filters['status'] == 'pendente' ? 'selected' : '' ?>>Pendente</option>
                        <option value="paga" <?= $filters['status'] == 'paga' ? 'selected' : '' ?>>Paga</option>
                        <option value="recebida" <?= $filters['status'] == 'recebida' ? 'selected' : '' ?>>Recebida</option>
                    </select>

                    <input type="date" name="filter_date_from" value="<?= $filters['date_from'] ?>" 
                           placeholder="Data inicial" class="border border-gray-300 rounded-md px-3 h-10 w-full">

                    <input type="date" name="filter_date_to" value="<?= $filters['date_to'] ?>" 
                           placeholder="Data final" class="border border-gray-300 rounded-md px-2 h-10 w-full">

                    <div class="flex items-center space-x-2 w-full justify-end">
                        <button type="submit" class="bg-gray-600 hover:bg-gray-700 text-white px-4 h-10 rounded-md shrink-0 flex items-center justify-center">
                            <i class="fas fa-search"></i>
                        </button>
                        <a href="?action=list" class="bg-gray-400 hover:bg-gray-500 text-white px-4 h-10 rounded-md shrink-0 flex items-center justify-center">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Contas a Pagar -->
        <div class="bg-white rounded-lg shadow mb-6">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-red-700">
                    <i class="fas fa-credit-card mr-2"></i>
                    Contas a Pagar
                    <span class="text-sm font-normal text-gray-500 ml-2">(<?= count($accountsExpenses) ?> contas)</span>
                </h2>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-red-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <a href="?action=list&<?= http_build_query(array_merge($_GET, ['sort_by' => 'description', 'sort_order' => ($sortBy == 'description' && $sortOrder == 'ASC') ? 'DESC' : 'ASC'])) ?>" class="flex items-center hover:text-gray-700">
                                    Descrição
                                    <?php if ($sortBy == 'description'): ?>
                                        <i class="fas fa-sort-<?= $sortOrder == 'ASC' ? 'up' : 'down' ?> ml-1"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Categoria</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <a href="?action=list&<?= http_build_query(array_merge($_GET, ['sort_by' => 'amount', 'sort_order' => ($sortBy == 'amount' && $sortOrder == 'ASC') ? 'DESC' : 'ASC'])) ?>" class="flex items-center hover:text-gray-700">
                                    Valor
                                    <?php if ($sortBy == 'amount'): ?>
                                        <i class="fas fa-sort-<?= $sortOrder == 'ASC' ? 'up' : 'down' ?> ml-1"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <a href="?action=list&<?= http_build_query(array_merge($_GET, ['sort_by' => 'due_date', 'sort_order' => ($sortBy == 'due_date' && $sortOrder == 'ASC') ? 'DESC' : 'ASC'])) ?>" class="flex items-center hover:text-gray-700">
                                    Vencimento
                                    <?php if ($sortBy == 'due_date'): ?>
                                        <i class="fas fa-sort-<?= $sortOrder == 'ASC' ? 'up' : 'down' ?> ml-1"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <a href="?action=list&<?= http_build_query(array_merge($_GET, ['sort_by' => 'status', 'sort_order' => ($sortBy == 'status' && $sortOrder == 'ASC') ? 'DESC' : 'ASC'])) ?>" class="flex items-center hover:text-gray-700">
                                    Status
                                    <?php if ($sortBy == 'status'): ?>
                                        <i class="fas fa-sort-<?= $sortOrder == 'ASC' ? 'up' : 'down' ?> ml-1"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Comprovante</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($accountsExpenses)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-inbox text-4xl mb-4"></i>
                                <p>Nenhuma conta a pagar encontrada</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($accountsExpenses as $account): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <div class="flex items-center">
                                    <?php if ($account['is_recurring']): ?>
                                    <i class="fas fa-sync-alt text-blue-500 mr-2" title="Conta recorrente"></i>
                                    <?php endif; ?>
                                    <div>
                                        <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($account['description']) ?></div>
                                        <?php if ($account['url']): ?>
                                        <a href="<?= htmlspecialchars($account['url']) ?>" target="_blank" 
                                           class="text-xs text-blue-600 hover:underline">
                                            <i class="fas fa-external-link-alt mr-1"></i>Link
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?= htmlspecialchars($account['category_name']) ?></td>
                            <td class="px-6 py-4 text-sm font-medium text-red-600">
                                <?= formatCurrency($account['amount']) ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?= formatDate($account['due_date']) ?></td>
                            <td class="px-6 py-4">
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="id" value="<?= $account['id'] ?>">
                                    <?php foreach ($filters as $key => $value): ?>
                                        <?php if (!empty($value)): ?>
                                        <input type="hidden" name="filter_<?= $key ?>" value="<?= htmlspecialchars($value) ?>">
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php if (!empty($sortBy)): ?>
                                    <input type="hidden" name="sort_by" value="<?= htmlspecialchars($sortBy) ?>">
                                    <?php endif; ?>
                                    <?php if (!empty($sortOrder)): ?>
                                    <input type="hidden" name="sort_order" value="<?= htmlspecialchars($sortOrder) ?>">
                                    <?php endif; ?>
                                    <div class="flex items-center gap-1">
                                        <select name="status" id="status_<?= $account['id'] ?>"
                                                class="text-xs px-2 py-1 rounded border-0 <?= 
                                                    $account['status'] == 'paga' ? 'bg-green-100 text-green-800' : 
                                                    ($account['status'] == 'pendente' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800') 
                                                ?>">
                                            <option value="pendente" <?= $account['status'] == 'pendente' ? 'selected' : '' ?>>Pendente</option>
                                            <option value="paga" <?= $account['status'] == 'paga' ? 'selected' : '' ?>>Paga</option>
                                        </select>
                                        <button type="submit" class="text-xs px-2 py-1 bg-blue-500 text-white rounded hover:bg-blue-600">
                                            ✓
                                        </button>
                                    </div>
                                </form>
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <?php if (!empty($account['attachment'])): ?>
                                    <?php 
                                    $fileExtension = strtolower(pathinfo($account['attachment'], PATHINFO_EXTENSION));
                                    $fileName = basename($account['attachment']);
                                    ?>
                                    <a href="<?= htmlspecialchars($account['attachment']) ?>" target="_blank" 
                                       class="inline-flex items-center text-blue-600 hover:text-blue-800 text-xs">
                                        <?php if ($fileExtension === 'pdf'): ?>
                                            <i class="fas fa-file-pdf mr-1 text-red-500"></i>
                                        <?php elseif (in_array($fileExtension, ['jpg', 'jpeg', 'png'])): ?>
                                            <i class="fas fa-file-image mr-1 text-green-500"></i>
                                        <?php else: ?>
                                            <i class="fas fa-file mr-1 text-gray-500"></i>
                                        <?php endif; ?>
                                        Ver
                                    </a>
                                <?php else: ?>
                                    <span class="text-gray-400 text-xs">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-sm space-x-2">
                                <a href="?action=edit&id=<?= $account['id'] ?>" 
                                   class="text-blue-600 hover:text-blue-800">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" class="inline" onsubmit="return confirm('Tem certeza que deseja excluir esta conta?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $account['id'] ?>">
                                    <button type="submit" class="text-red-600 hover:text-red-800">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Contas a Receber -->
        <div class="bg-white rounded-lg shadow">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-green-700">
                    <i class="fas fa-money-bill-wave mr-2"></i>
                    Contas a Receber
                    <span class="text-sm font-normal text-gray-500 ml-2">(<?= count($accountsRevenues) ?> contas)</span>
                </h2>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-green-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <a href="?action=list&<?= http_build_query(array_merge($_GET, ['sort_by' => 'description', 'sort_order' => ($sortBy == 'description' && $sortOrder == 'ASC') ? 'DESC' : 'ASC'])) ?>" class="flex items-center hover:text-gray-700">
                                    Descrição
                                    <?php if ($sortBy == 'description'): ?>
                                        <i class="fas fa-sort-<?= $sortOrder == 'ASC' ? 'up' : 'down' ?> ml-1"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Categoria</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <a href="?action=list&<?= http_build_query(array_merge($_GET, ['sort_by' => 'amount', 'sort_order' => ($sortBy == 'amount' && $sortOrder == 'ASC') ? 'DESC' : 'ASC'])) ?>" class="flex items-center hover:text-gray-700">
                                    Valor
                                    <?php if ($sortBy == 'amount'): ?>
                                        <i class="fas fa-sort-<?= $sortOrder == 'ASC' ? 'up' : 'down' ?> ml-1"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <a href="?action=list&<?= http_build_query(array_merge($_GET, ['sort_by' => 'due_date', 'sort_order' => ($sortBy == 'due_date' && $sortOrder == 'ASC') ? 'DESC' : 'ASC'])) ?>" class="flex items-center hover:text-gray-700">
                                    Vencimento
                                    <?php if ($sortBy == 'due_date'): ?>
                                        <i class="fas fa-sort-<?= $sortOrder == 'ASC' ? 'up' : 'down' ?> ml-1"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <a href="?action=list&<?= http_build_query(array_merge($_GET, ['sort_by' => 'status', 'sort_order' => ($sortBy == 'status' && $sortOrder == 'ASC') ? 'DESC' : 'ASC'])) ?>" class="flex items-center hover:text-gray-700">
                                    Status
                                    <?php if ($sortBy == 'status'): ?>
                                        <i class="fas fa-sort-<?= $sortOrder == 'ASC' ? 'up' : 'down' ?> ml-1"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Comprovante</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($accountsRevenues)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-inbox text-4xl mb-4"></i>
                                <p>Nenhuma conta a receber encontrada</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($accountsRevenues as $account): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <div class="flex items-center">
                                    <?php if ($account['is_recurring']): ?>
                                    <i class="fas fa-sync-alt text-blue-500 mr-2" title="Conta recorrente"></i>
                                    <?php endif; ?>
                                    <div>
                                        <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($account['description']) ?></div>
                                        <?php if ($account['url']): ?>
                                        <a href="<?= htmlspecialchars($account['url']) ?>" target="_blank" 
                                           class="text-xs text-blue-600 hover:underline">
                                            <i class="fas fa-external-link-alt mr-1"></i>Link
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?= htmlspecialchars($account['category_name']) ?></td>
                            <td class="px-6 py-4 text-sm font-medium text-green-600">
                                <?= formatCurrency($account['amount']) ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?= formatDate($account['due_date']) ?></td>
                            <td class="px-6 py-4">
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="id" value="<?= $account['id'] ?>">
                                    <?php foreach ($filters as $key => $value): ?>
                                        <?php if (!empty($value)): ?>
                                        <input type="hidden" name="filter_<?= $key ?>" value="<?= htmlspecialchars($value) ?>">
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php if (!empty($sortBy)): ?>
                                    <input type="hidden" name="sort_by" value="<?= htmlspecialchars($sortBy) ?>">
                                    <?php endif; ?>
                                    <?php if (!empty($sortOrder)): ?>
                                    <input type="hidden" name="sort_order" value="<?= htmlspecialchars($sortOrder) ?>">
                                    <?php endif; ?>
                                    <div class="flex items-center gap-1">
                                        <select name="status" id="status_<?= $account['id'] ?>"
                                                class="text-xs px-2 py-1 rounded border-0 <?= 
                                                    $account['status'] == 'recebida' ? 'bg-green-100 text-green-800' : 
                                                    ($account['status'] == 'pendente' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800') 
                                                ?>">
                                            <option value="pendente" <?= $account['status'] == 'pendente' ? 'selected' : '' ?>>Pendente</option>
                                            <option value="recebida" <?= $account['status'] == 'recebida' ? 'selected' : '' ?>>Recebida</option>
                                        </select>
                                        <button type="submit" class="text-xs px-2 py-1 bg-blue-500 text-white rounded hover:bg-blue-600">
                                            ✓
                                        </button>
                                    </div>
                                </form>
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <?php if (!empty($account['attachment'])): ?>
                                    <?php 
                                    $fileExtension = strtolower(pathinfo($account['attachment'], PATHINFO_EXTENSION));
                                    $fileName = basename($account['attachment']);
                                    ?>
                                    <a href="<?= htmlspecialchars($account['attachment']) ?>" target="_blank" 
                                       class="inline-flex items-center text-blue-600 hover:text-blue-800 text-xs">
                                        <?php if ($fileExtension === 'pdf'): ?>
                                            <i class="fas fa-file-pdf mr-1 text-red-500"></i>
                                        <?php elseif (in_array($fileExtension, ['jpg', 'jpeg', 'png'])): ?>
                                            <i class="fas fa-file-image mr-1 text-green-500"></i>
                                        <?php else: ?>
                                            <i class="fas fa-file mr-1 text-gray-500"></i>
                                        <?php endif; ?>
                                        Ver
                                    </a>
                                <?php else: ?>
                                    <span class="text-gray-400 text-xs">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-sm space-x-2">
                                <a href="?action=edit&id=<?= $account['id'] ?>" 
                                   class="text-blue-600 hover:text-blue-800">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" class="inline" onsubmit="return confirm('Tem certeza que deseja excluir esta conta?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $account['id'] ?>">
                                    <button type="submit" class="text-red-600 hover:text-red-800">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php else: ?>
        <!-- Formulário de Adicionar/Editar -->
        <div class="bg-white rounded-lg shadow">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-900">
                    <i class="fas <?= $action == 'add' ? 'fa-plus' : 'fa-edit' ?> mr-2"></i>
                    <?= $action == 'add' ? 'Nova Conta' : 'Editar Conta' ?>
                </h2>
            </div>

            <form method="POST" enctype="multipart/form-data" class="p-6 space-y-6">
                <input type="hidden" name="action" value="<?= $action ?>">
                <?php if ($action == 'edit'): ?>
                <input type="hidden" name="id" value="<?= $accountId ?>">
                <?php endif; ?>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Descrição -->
                    <div class="md:col-span-2">
                        <label for="description" class="block text-sm font-medium text-gray-700 mb-2">
                            Descrição *
                        </label>
                        <input type="text" id="description" name="description" required
                               value="<?= htmlspecialchars($account['description'] ?? '') ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    </div>

                    <!-- Tipo -->
                    <div>
                        <label for="type" class="block text-sm font-medium text-gray-700 mb-2">
                            Tipo *
                        </label>
                        <select id="type" name="type" required onchange="updateCategories()"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                            <option value="">Selecione...</option>
                            <option value="receita" <?= ($account['type'] ?? '') == 'receita' ? 'selected' : '' ?>>Receita</option>
                            <option value="despesa" <?= ($account['type'] ?? '') == 'despesa' ? 'selected' : '' ?>>Despesa</option>
                        </select>
                    </div>

                    <!-- Categoria -->
                    <div>
                        <label for="category_id" class="block text-sm font-medium text-gray-700 mb-2">
                            Categoria *
                        </label>
                        <select id="category_id" name="category_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                            <option value="">Selecione...</option>
                            <?php foreach ($categories as $category): ?>
                            <option value="<?= $category['id'] ?>" 
                                    data-type="<?= $category['type'] ?>"
                                    <?= ($account['category_id'] ?? '') == $category['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($category['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Valor -->
                    <div>
                        <label for="amount" class="block text-sm font-medium text-gray-700 mb-2">
                            Valor *
                        </label>
                        <input type="number" id="amount" name="amount" step="0.01" min="0" required
                               value="<?= $account['amount'] ?? '' ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    </div>

                    <!-- Data de Vencimento -->
                    <div>
                        <label for="due_date" class="block text-sm font-medium text-gray-700 mb-2">
                            Data de Vencimento *
                        </label>
                        <input type="date" id="due_date" name="due_date" required
                               value="<?= $account['due_date'] ?? '' ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    </div>

                    <!-- Status -->
                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700 mb-2">
                            Status
                        </label>
                        <select id="status" name="status"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                            <option value="pendente" <?= ($account['status'] ?? 'pendente') == 'pendente' ? 'selected' : '' ?>>Pendente</option>
                            <?php if (($account['type'] ?? $type ?? 'despesa') == 'despesa'): ?>
                            <option value="paga" <?= ($account['status'] ?? '') == 'paga' ? 'selected' : '' ?>>Paga</option>
                            <?php elseif (($account['type'] ?? $type ?? 'despesa') == 'receita'): ?>
                            <option value="recebida" <?= ($account['status'] ?? '') == 'recebida' ? 'selected' : '' ?>>Recebida</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <!-- URL -->
                    <div>
                        <label for="url" class="block text-sm font-medium text-gray-700 mb-2">
                            URL (opcional)
                        </label>
                        <input type="url" id="url" name="url"
                               value="<?= htmlspecialchars($account['url'] ?? '') ?>"
                               placeholder="https://..."
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    </div>

                    <!-- Observações -->
                    <div class="md:col-span-2">
                        <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">
                            Observações
                        </label>
                        <textarea id="notes" name="notes" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"><?= htmlspecialchars($account['notes'] ?? '') ?></textarea>
                    </div>

                    <!-- Comprovante de Pagamento -->
                    <div class="md:col-span-2">
                        <label for="attachment" class="block text-sm font-medium text-gray-700 mb-2">
                            Comprovante de Pagamento
                        </label>
                        <?php if (!empty($account['attachment'])): ?>
                        <div class="mb-3 p-3 bg-blue-50 border border-blue-200 rounded-md">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center">
                                    <i class="fas fa-file-alt text-blue-600 mr-2"></i>
                                    <span class="text-sm text-blue-800">Comprovante atual:</span>
                                    <a href="<?= htmlspecialchars($account['attachment']) ?>" target="_blank" 
                                       class="ml-2 text-blue-600 hover:underline text-sm">
                                        Ver arquivo
                                    </a>
                                </div>
                                <span class="text-xs text-blue-600">
                                    <?= pathinfo($account['attachment'], PATHINFO_EXTENSION) ?>
                                </span>
                            </div>
                        </div>
                        <?php endif; ?>
                        <input type="file" id="attachment" name="attachment" 
                               accept=".pdf,.jpg,.jpeg,.png"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                        <p class="mt-1 text-xs text-gray-500">
                            Formatos aceitos: PDF, JPG, PNG (máximo 5MB)
                        </p>
                    </div>

                    <!-- Recorrência -->
                    <div class="md:col-span-2">
                        <div class="flex items-center mb-4">
                            <input type="checkbox" id="is_recurring" name="is_recurring" 
                                   class="h-4 w-4 text-primary focus:ring-primary border-gray-300 rounded"
                                   onchange="toggleRecurringOptions()" <?= ($account['is_recurring'] ?? false) ? 'checked' : '' ?> >
                            <label for="is_recurring" class="ml-2 block text-sm text-gray-900">
                                Esta é uma conta recorrente
                            </label>
                        </div>

                        <?php 
                          $freqTypeVal = $recurringSetting['frequency_type'] ?? 'mensal';
                          $freqIntervalVal = $recurringSetting['frequency_interval'] ?? 1;
                          $endDateVal = $recurringSetting['end_date'] ?? '';
                          $maxOccVal = $recurringSetting['max_occurrences'] ?? '';
                          $showOptions = ($account['is_recurring'] ?? false);
                          $showCustom = ($freqTypeVal === 'personalizado');
                        ?>

                        <div id="recurring_options" class="<?= $showOptions ? '' : 'hidden' ?> grid grid-cols-1 md:grid-cols-3 gap-4 p-4 bg-gray-50 rounded-lg">
                            <div>
                                <label for="frequency_type" class="block text-sm font-medium text-gray-700 mb-2">
                                    Frequência
                                </label>
                                <select id="frequency_type" name="frequency_type" onchange="toggleCustomInterval()"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md">
                                    <?php 
                                        $freqOptions = ['semanal','mensal','bimestral','trimestral','semestral','anual','personalizado'];
                                        $labelMap = [
                                            'semanal' => 'Semanal',
                                            'mensal' => 'Mensal',
                                            'bimestral' => 'Bimestral',
                                            'trimestral' => 'Trimestral',
                                            'semestral' => 'Semestral',
                                            'anual' => 'Anual',
                                            'personalizado' => 'Personalizado'
                                        ];
                                        foreach ($freqOptions as $opt) {
                                            $sel = ($freqTypeVal === $opt) ? 'selected' : '';
                                            echo "<option value=\"$opt\" $sel>{$labelMap[$opt]}</option>";
                                        }
                                    ?>
                                </select>
                            </div>

                            <div id="custom_interval" class="<?= $showCustom ? '' : 'hidden' ?>">
                                <label for="frequency_interval" class="block text-sm font-medium text-gray-700 mb-2">
                                    Intervalo (dias)
                                </label>
                                <input type="number" id="frequency_interval" name="frequency_interval" min="1" value="<?= htmlspecialchars($freqIntervalVal) ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md">
                            </div>

                            <div>
                                <label for="end_date" class="block text-sm font-medium text-gray-700 mb-2">
                                    Data Final (opcional)
                                </label>
                                <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($endDateVal) ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md">
                            </div>

                            <div>
                                <label for="max_occurrences" class="block text-sm font-medium text-gray-700 mb-2">
                                    Máximo de Ocorrências (opcional)
                                </label>
                                <input type="number" id="max_occurrences" name="max_occurrences" min="1" value="<?= htmlspecialchars($maxOccVal) ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end space-x-4">
                    <a href="?action=list" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-6 py-2 rounded-md transition-colors">
                        Cancelar
                    </a>
                    <button type="submit" class="bg-primary hover:bg-blue-700 text-white px-6 py-2 rounded-md transition-colors">
                        <?= $action == 'add' ? 'Criar Conta' : 'Atualizar Conta' ?>
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </main>

    <script>
        function updateCategories() {
            const typeSelect = document.getElementById('type');
            const categorySelect = document.getElementById('category_id');
            if (!typeSelect || !categorySelect) return;
            const selectedType = typeSelect.value;
            
            // Mostrar apenas categorias do tipo selecionado
            Array.from(categorySelect.options).forEach(option => {
                if (option.value === '') {
                    option.style.display = 'block';
                } else {
                    const optionType = option.getAttribute('data-type');
                    option.style.display = optionType === selectedType ? 'block' : 'none';
                }
            });
            
            // Reset selection if current category doesn't match type
            const currentCategory = categorySelect.options[categorySelect.selectedIndex];
            if (currentCategory && currentCategory.getAttribute('data-type') !== selectedType) {
                categorySelect.value = '';
            }
        }

        function toggleRecurringOptions() {
            const checkbox = document.getElementById('is_recurring');
            const options = document.getElementById('recurring_options');
            
            if (checkbox.checked) {
                options.classList.remove('hidden');
            } else {
                options.classList.add('hidden');
            }
        }

        function toggleCustomInterval() {
            const frequencyType = document.getElementById('frequency_type');
            const customInterval = document.getElementById('custom_interval');
            
            if (frequencyType.value === 'personalizado') {
                customInterval.classList.remove('hidden');
            } else {
                customInterval.classList.add('hidden');
            }
        }

        function preventMultipleSubmissions(selectElement) {
            selectElement.disabled = true;
            selectElement.style.opacity = '0.5';
            selectElement.form.submit();
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateCategories();
            if (document.getElementById('is_recurring')) {
                toggleRecurringOptions();
                toggleCustomInterval();
            }
        });
    </script>
</body>
</html>