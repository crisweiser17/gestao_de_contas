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

// Executar rotina diária de recorrências (global, uma vez por dia)
try {
    $recurringModel->runDailyGlobalProcessingIfNeeded();
} catch (Exception $e) {
    error_log("Erro na rotina diária de recorrências: " . $e->getMessage());
}

// Executar manutenção leve das contas recorrentes (em background)
try {
    $recurringModel->lightMaintenance($userId);
} catch (Exception $e) {
    // Falha silenciosa - não deve interromper o carregamento da página
    error_log("Erro na manutenção de recorrências: " . $e->getMessage());
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
            'name' => trim($_POST['name'] ?? ''),
            'amount' => str_replace(',', '.', $_POST['amount'] ?? ''),
            'due_date' => $_POST['due_date'] ?? '',
            'type' => $_POST['type'] ?? '',
            'status' => $_POST['status'] ?? 'pendente',
            'url' => trim($_POST['url'] ?? ''),
            'is_recurring' => isset($_POST['is_recurring']),
            'notes' => trim($_POST['notes'] ?? '')
        ];

        // Ler flags do modal (se presentes)
        $editContextPost = $_POST['edit_context'] ?? null;
        $propagateUrl = isset($_POST['propagate_url']) && $_POST['propagate_url'] === '1';
        $propagateDescription = isset($_POST['propagate_description']) && $_POST['propagate_description'] === '1';
        $propagateValue = isset($_POST['propagate_value']) && $_POST['propagate_value'] === '1';
        $realignFuture = isset($_POST['realign_future_due_dates']) && $_POST['realign_future_due_dates'] === '1';
        $applySharedToParent = isset($_POST['apply_shared_to_parent']) && $_POST['apply_shared_to_parent'] === '1';

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
                // Capturar estado atual antes de atualizar (para detectar pai/filho e comparar URL)
                $existingAccount = $accountModel->getById($accountId, $userId);

                // Bloquear edição de instâncias passadas
                $isInstance = ($existingAccount && !empty($existingAccount['recurring_parent_id']));
                $dueDate = $existingAccount['due_date'] ?? null;
                if ($isInstance && $dueDate && strtotime($dueDate) < strtotime(date('Y-m-d'))) {
                    $errors[] = 'Edição bloqueada: apenas instâncias futuras podem ser editadas.';
                }

                if (empty($errors) && $accountModel->update($accountId, $data, $userId)) {
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

                    // Propagação controlada por contexto e flags
                    $isParent = ($existingAccount && intval($existingAccount['is_recurring']) === 1 && empty($existingAccount['recurring_parent_id']));
                    $isInstance = ($existingAccount && !empty($existingAccount['recurring_parent_id']));

                    if ($isParent) {
                        // URL
                        $oldUrl = $existingAccount['url'] ?? null;
                        $newUrl = $data['url'] ?? null;
                        if ($propagateUrl && $newUrl !== $oldUrl) {
                            $stmt = $pdo->prepare("UPDATE accounts SET url = :url WHERE recurring_parent_id = :parent_id AND user_id = :user_id");
                            $stmt->execute([
                                ':url' => $newUrl,
                                ':parent_id' => $accountId,
                                ':user_id' => $userId
                            ]);
                        }
                        // Descrição
                        $oldDesc = $existingAccount['description'] ?? null;
                        $newDesc = $data['description'] ?? null;
                        if ($propagateDescription && $newDesc !== $oldDesc) {
                            $stmt = $pdo->prepare("UPDATE accounts SET description = :description WHERE recurring_parent_id = :parent_id AND user_id = :user_id");
                            $stmt->execute([
                                ':description' => $newDesc,
                                ':parent_id' => $accountId,
                                ':user_id' => $userId
                            ]);
                        }
                        // Valor
                        $oldAmount = $existingAccount['amount'] ?? null;
                        $newAmount = $data['amount'] ?? null;
                        if ($propagateValue && $newAmount !== $oldAmount) {
                            $stmt = $pdo->prepare("UPDATE accounts SET amount = :amount WHERE recurring_parent_id = :parent_id AND user_id = :user_id AND status = 'pendente' AND due_date >= CURDATE()");
                            $stmt->execute([
                                ':amount' => $newAmount,
                                ':parent_id' => $accountId,
                                ':user_id' => $userId
                            ]);
                        }
                        // Vencimento: re-alinhar futuras
                        $oldDue = $existingAccount['due_date'] ?? null;
                        $newDue = $data['due_date'] ?? null;
                        if ($realignFuture && $newDue && $oldDue && $newDue !== $oldDue) {
                            $freqType = $_POST['frequency_type'] ?? ($existingSetting['frequency_type'] ?? 'mensal');
                            $freqInterval = $_POST['frequency_interval'] ?? ($existingSetting['frequency_interval'] ?? 1);
                            $startDate = $recurringModel->calculateNextDate($newDue, $freqType, $freqInterval, $newDue);
                            $fetchStmt = $pdo->prepare("SELECT id, due_date FROM accounts WHERE recurring_parent_id = :parent_id AND user_id = :user_id AND status = 'pendente' AND due_date >= CURDATE() ORDER BY due_date ASC");
                            $fetchStmt->execute([':parent_id' => $accountId, ':user_id' => $userId]);
                            $children = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);
                            $nextDate = $startDate;
                            $updateStmt = $pdo->prepare("UPDATE accounts SET due_date = :due_date WHERE id = :id AND user_id = :user_id");
                            foreach ($children as $child) {
                                $updateStmt->execute([':due_date' => $nextDate, ':id' => $child['id'], ':user_id' => $userId]);
                                $nextDate = $recurringModel->calculateNextDate($nextDate, $freqType, $freqInterval, $newDue);
                            }
                        }

                        // Recalcular futuras instâncias conforme end_date e max_occurrences
                        try {
                            $targetEnd = isset($endDate) ? $endDate : ($existingSetting['end_date'] ?? null);
                            $targetMax = isset($maxOcc) ? $maxOcc : ($existingSetting['max_occurrences'] ?? null);

                            // 1) Remover pendentes fora da nova data final
                            if (!empty($targetEnd)) {
                                $delStmt = $pdo->prepare("DELETE FROM accounts WHERE recurring_parent_id = :parent_id AND user_id = :user_id AND status = 'pendente' AND due_date > :end_date");
                                $delStmt->execute([':parent_id' => $accountId, ':user_id' => $userId, ':end_date' => $targetEnd]);
                            }

                            // 2) Ajustar quantidade máxima de parcelas futuras
                            $fetchFuture = $pdo->prepare("SELECT id, due_date FROM accounts WHERE recurring_parent_id = :parent_id AND user_id = :user_id AND status = 'pendente' AND due_date >= CURDATE() ORDER BY due_date ASC");
                            $fetchFuture->execute([':parent_id' => $accountId, ':user_id' => $userId]);
                            $futureChildren = $fetchFuture->fetchAll(PDO::FETCH_ASSOC);
                            $currentCount = count($futureChildren);

                            // Remover excedentes se necessário
                            if (!empty($targetMax) && $currentCount > intval($targetMax)) {
                                $excess = array_slice($futureChildren, intval($targetMax));
                                $deleteById = $pdo->prepare("DELETE FROM accounts WHERE id = :id AND user_id = :user_id AND status = 'pendente'");
                                foreach ($excess as $child) {
                                    $deleteById->execute([':id' => $child['id'], ':user_id' => $userId]);
                                }
                                // Atualizar a lista após remoção
                                $fetchFuture->execute([':parent_id' => $accountId, ':user_id' => $userId]);
                                $futureChildren = $fetchFuture->fetchAll(PDO::FETCH_ASSOC);
                                $currentCount = count($futureChildren);
                            }

                            // Gerar faltantes até alcançar targetMax respeitando targetEnd
                            if (!empty($targetMax) && $currentCount < intval($targetMax)) {
                                $needed = intval($targetMax) - $currentCount;
                                $lastDate = $currentCount > 0 ? $futureChildren[$currentCount - 1]['due_date'] : ($data['due_date'] ?? null);
                                if (!$lastDate) { $lastDate = $existingAccount['due_date'] ?? date('Y-m-d'); }

                                $insertStmt = $pdo->prepare("INSERT INTO accounts (user_id, category_id, description, amount, due_date, type, status, url, is_recurring, recurring_parent_id) VALUES (:user_id, :category_id, :description, :amount, :due_date, :type, 'pendente', :url, 0, :parent_id)");

                                for ($i = 0; $i < $needed; $i++) {
                                    $nextDue = $recurringModel->calculateNextDate($lastDate, $freqType, $freqInterval, $data['due_date'] ?? $existingAccount['due_date']);
                                    if (!empty($targetEnd) && $nextDue > $targetEnd) { break; }

                                    $insertStmt->execute([
                                        ':user_id' => $userId,
                                        ':category_id' => $existingAccount['category_id'] ?? $data['category_id'] ?? null,
                                        ':description' => $existingAccount['description'] ?? $data['description'] ?? '',
                                        ':amount' => $existingAccount['amount'] ?? $data['amount'] ?? 0,
                                        ':due_date' => $nextDue,
                                        ':type' => $existingAccount['type'] ?? $data['type'] ?? 'despesa',
                                        ':url' => $existingAccount['url'] ?? $data['url'] ?? null,
                                        ':parent_id' => $accountId,
                                    ]);

                                    $lastDate = $nextDue;
                                    $currentCount++;
                                }
                            }

                            // 3) Atualizar próxima data de geração com base no último futuro
                            $referenceDate = null;
                            if (!empty($futureChildren)) {
                                $referenceDate = $futureChildren[count($futureChildren) - 1]['due_date'];
                            }
                            if (!$referenceDate) { $referenceDate = $data['due_date'] ?? $existingAccount['due_date'] ?? date('Y-m-d'); }
                            $nextGenDate = $recurringModel->calculateNextDate($referenceDate, $freqType, $freqInterval, $data['due_date'] ?? $existingAccount['due_date']);
                            $recurringModel->updateNextGenerationDate($accountId, $nextGenDate);
                        } catch (Exception $e) {
                            // Silenciar falhas de manutenção leve na edição
                        }

                    } else if ($isInstance && $applySharedToParent) {
                        // Aplicar campos compartilhados ao pai
                        $parentId = $existingAccount['recurring_parent_id'];
                        $fields = [];
                        $params = [':id' => $parentId, ':user_id' => $userId];
                        if (!empty($data['url'])) { $fields[] = 'url = :url'; $params[':url'] = $data['url']; }
                        if (!empty($data['description'])) { $fields[] = 'description = :description'; $params[':description'] = $data['description']; }
                        if (!empty($data['amount'])) { $fields[] = 'amount = :amount'; $params[':amount'] = $data['amount']; }
                        if (!empty($fields)) {
                            $sql = 'UPDATE accounts SET ' . implode(', ', $fields) . ' WHERE id = :id AND user_id = :user_id';
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute($params);
                        }
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
        'date_filter_type' => $_POST['filter_date_filter_type'] ?? $_GET['filter_date_filter_type'] ?? '',
        'date_month' => $_POST['filter_date_month'] ?? $_GET['filter_date_month'] ?? '',
        'date_year' => $_POST['filter_date_year'] ?? $_POST['filter_date_year_only'] ?? $_GET['filter_date_year'] ?? $_GET['filter_date_year_only'] ?? '',
        'date_from' => $_POST['filter_date_from'] ?? $_GET['filter_date_from'] ?? '',
        'date_to' => $_POST['filter_date_to'] ?? $_GET['filter_date_to'] ?? ''
    ];
} else {
    $filters = [
        'category_id' => $_GET['filter_category'] ?? '',
        'type' => $_GET['filter_type'] ?? '',
        'status' => $_GET['filter_status'] ?? '',
        'date_filter_type' => $_GET['filter_date_filter_type'] ?? '',
        'date_month' => $_GET['filter_date_month'] ?? '',
        'date_year' => $_GET['filter_date_year'] ?? $_GET['filter_date_year_only'] ?? '',
        'date_from' => $_GET['filter_date_from'] ?? '',
        'date_to' => $_GET['filter_date_to'] ?? ''
    ];
}

// Definir filtro padrão para Mês/Ano do mês/ano atual quando nenhum filtro for informado
if (empty($filters['date_filter_type'])) {
    $filters['date_filter_type'] = 'month_year';
    $filters['date_month'] = date('n');
    $filters['date_year'] = date('Y');
}

// Processar filtros de data baseado no tipo selecionado
if (!empty($filters['date_filter_type'])) {
    switch ($filters['date_filter_type']) {
        case 'month_year':
            if (!empty($filters['date_month']) && !empty($filters['date_year'])) {
                $filters['date_from'] = $filters['date_year'] . '-' . str_pad($filters['date_month'], 2, '0', STR_PAD_LEFT) . '-01';
                $filters['date_to'] = date('Y-m-t', strtotime($filters['date_from']));
            }
            break;
        case 'year':
            if (!empty($filters['date_year'])) {
                $filters['date_from'] = $filters['date_year'] . '-01-01';
                $filters['date_to'] = $filters['date_year'] . '-12-31';
            }
            break;
        case 'custom':
            // Manter os valores de date_from e date_to como estão
            break;
        case 'all':
            // Limpar intervalo para buscar todas as contas disponíveis
            $filters['date_from'] = '';
            $filters['date_to'] = '';
            break;
    }
}

// Calcular horizonte para rótulo da opção 'Todas' (pt-BR mês abreviado)
$horizonLabel = '';
try {
    $database = new Database();
    $pdo = $database->getConnection();
    $stmt = $pdo->prepare("SELECT MAX(due_date) as max_date FROM accounts WHERE user_id = :user_id");
    $stmt->bindParam(':user_id', $userId);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $monthsPt = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
    if (!empty($row['max_date'])) {
        $maxDate = new DateTime($row['max_date']);
        $monthIdx = (int)$maxDate->format('n');
        $horizonLabel = $monthsPt[$monthIdx - 1] . '/' . $maxDate->format('Y');
    } else {
        // Fallback: mostrar 10 anos à frente
        $fallback = new DateTime();
        $fallback->add(new DateInterval('P120M'));
        $monthIdx = (int)$fallback->format('n');
        $horizonLabel = $monthsPt[$monthIdx - 1] . '/' . $fallback->format('Y');
    }
} catch (Exception $e) {
    // Fallback seguro
    $fallback = new DateTime();
    $fallback->add(new DateInterval('P120M'));
    $monthsPt = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
    $monthIdx = (int)$fallback->format('n');
    $horizonLabel = $monthsPt[$monthIdx - 1] . '/' . $fallback->format('Y');
}

// Período selecionado para exibir nos títulos
$periodLabel = '';
switch ($filters['date_filter_type'] ?? '') {
    case 'month_year':
        if (!empty($filters['date_month']) && !empty($filters['date_year'])) {
            $monthIdx = (int)$filters['date_month'];
            $periodLabel = ($monthsPt[$monthIdx - 1] ?? '') . '/' . $filters['date_year'];
        }
        break;
    case 'year':
        if (!empty($filters['date_year'])) {
            $periodLabel = $filters['date_year'];
        }
        break;
    case 'custom':
        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $periodLabel = date('d/m/Y', strtotime($filters['date_from'])) . ' a ' . date('d/m/Y', strtotime($filters['date_to']));
        } else {
            $periodLabel = 'Período personalizado';
        }
        break;
    case 'all':
        $periodLabel = 'Todas até ' . $horizonLabel;
        break;
    default:
        if (!empty($filters['date_month']) && !empty($filters['date_year'])) {
            $monthIdx = (int)$filters['date_month'];
            $periodLabel = ($monthsPt[$monthIdx - 1] ?? '') . '/' . $filters['date_year'];
        }
        break;
}

// Parâmetros de ordenação (preservar após POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $sortBy = $_POST['sort_by'] ?? $_GET['sort_by'] ?? 'due_date';
    $sortOrder = $_POST['sort_order'] ?? $_GET['sort_order'] ?? 'ASC';
} else {
    $sortBy = $_GET['sort_by'] ?? 'due_date';
    $sortOrder = $_GET['sort_order'] ?? 'ASC';
}

// Validar parâmetros de ordenação
$validSortColumns = ['due_date', 'status', 'description', 'name', 'amount'];
if (!in_array($sortBy, $validSortColumns)) {
    $sortBy = 'due_date';
}

$validSortOrders = ['ASC', 'DESC'];
if (!in_array(strtoupper($sortOrder), $validSortOrders)) {
    $sortOrder = 'ASC';
}

// Estado de visualização (lista/calendário)
$viewMode = (isset($_GET['view']) && $_GET['view'] === 'calendar') ? 'calendar' : 'list';
// Estado de agrupamento (none/category/status) separado por tabela
$allowedGroupModes = ['none','category','status'];
$groupModeGlobal = (isset($_GET['group']) && in_array($_GET['group'], $allowedGroupModes)) ? $_GET['group'] : 'none';
$groupModeExpenses = (isset($_GET['group_expenses']) && in_array($_GET['group_expenses'], $allowedGroupModes)) ? $_GET['group_expenses'] : $groupModeGlobal;
$groupModeRevenues = (isset($_GET['group_revenues']) && in_array($_GET['group_revenues'], $allowedGroupModes)) ? $_GET['group_revenues'] : $groupModeGlobal;

// Parâmetros de paginação
$itemsPerPageExpenses = (int)($_GET['items_per_page_expenses'] ?? 25);
$itemsPerPageRevenues = (int)($_GET['items_per_page_revenues'] ?? 25);
$pageExpenses = max(1, (int)($_GET['page_expenses'] ?? 1));
$pageRevenues = max(1, (int)($_GET['page_revenues'] ?? 1));

// Validar itens por página
$validItemsPerPage = [25, 50, 100];
if (!in_array($itemsPerPageExpenses, $validItemsPerPage)) {
    $itemsPerPageExpenses = 25;
}
if (!in_array($itemsPerPageRevenues, $validItemsPerPage)) {
    $itemsPerPageRevenues = 25;
}

if ($action == 'list') {
    // Buscar contas a pagar (despesas)
    $filtersExpenses = array_merge(array_filter($filters), ['type' => 'despesa']);
    $totalExpenses = $accountModel->countWithFilters($userId, $filtersExpenses);
    $offsetExpenses = ($pageExpenses - 1) * $itemsPerPageExpenses;
    $accountsExpenses = $accountModel->getWithFilters($userId, $filtersExpenses, $sortBy, $sortOrder, $itemsPerPageExpenses, $offsetExpenses);
    
    // Buscar contas a receber (receitas)
    $filtersRevenues = array_merge(array_filter($filters), ['type' => 'receita']);
    $totalRevenues = $accountModel->countWithFilters($userId, $filtersRevenues);
    $offsetRevenues = ($pageRevenues - 1) * $itemsPerPageRevenues;
    $accountsRevenues = $accountModel->getWithFilters($userId, $filtersRevenues, $sortBy, $sortOrder, $itemsPerPageRevenues, $offsetRevenues);
    
    // Ordenação padrão por tabela (pendentes primeiro, depois vencimento), quando não há sort_by explícito
    if (!isset($_GET['sort_by'])) {
        // Despesas: pendente primeiro, depois paga; segundo critério due_date ASC
        usort($accountsExpenses, function($a, $b) {
            $prio = function($st){ return ($st === 'pendente') ? 0 : (($st === 'paga') ? 1 : 2); };
            $pa = $prio($a['status'] ?? '');
            $pb = $prio($b['status'] ?? '');
            if ($pa !== $pb) return $pa <=> $pb;
            $da = strtotime($a['due_date'] ?? '') ?: 0;
            $db = strtotime($b['due_date'] ?? '') ?: 0;
            return $da <=> $db;
        });
        // Receitas: pendente primeiro, depois recebida; segundo critério due_date ASC
        usort($accountsRevenues, function($a, $b) {
            $prio = function($st){ return ($st === 'pendente') ? 0 : (($st === 'recebida') ? 1 : 2); };
            $pa = $prio($a['status'] ?? '');
            $pb = $prio($b['status'] ?? '');
            if ($pa !== $pb) return $pa <=> $pb;
            $da = strtotime($a['due_date'] ?? '') ?: 0;
            $db = strtotime($b['due_date'] ?? '') ?: 0;
            return $da <=> $db;
        });
    }
    
    // Calcular total de páginas
    $totalPagesExpenses = ceil($totalExpenses / $itemsPerPageExpenses);
    $totalPagesRevenues = ceil($totalRevenues / $itemsPerPageRevenues);
    
    // Calcular soma total dos valores filtrados
    $totalAmountExpenses = $accountModel->sumWithFilters($userId, $filtersExpenses);
    $totalAmountRevenues = $accountModel->sumWithFilters($userId, $filtersRevenues);

    // Subtotais por status (ignora filtro de status atual para mostrar ambos)
    $filtersExpensesNoStatus = $filtersExpenses; unset($filtersExpensesNoStatus['status']);
    $filtersRevenuesNoStatus = $filtersRevenues; unset($filtersRevenuesNoStatus['status']);
    $totalAmountExpensesPending = $accountModel->sumWithFilters($userId, array_merge($filtersExpensesNoStatus, ['status' => 'pendente']));
    $totalAmountExpensesPaid = $accountModel->sumWithFilters($userId, array_merge($filtersExpensesNoStatus, ['status' => 'paga']));
    $totalAmountRevenuesPending = $accountModel->sumWithFilters($userId, array_merge($filtersRevenuesNoStatus, ['status' => 'pendente']));
    $totalAmountRevenuesReceived = $accountModel->sumWithFilters($userId, array_merge($filtersRevenuesNoStatus, ['status' => 'recebida']));
} else if ($action == 'edit' && $accountId) {
    $account = $accountModel->getById($accountId, $userId);
    $recurringSetting = $recurringModel->getByAccountId($accountId);
    if (!$account) {
        $action = 'list';
        $errors[] = 'Conta não encontrada';
    }
    // Definir contexto de edição (pai/instância)
    $editContext = $_GET['edit_context'] ?? ($_POST['edit_context'] ?? null);
    if ($editContext === null) {
        if ($account && intval($account['is_recurring']) === 1) {
            $editContext = empty($account['recurring_parent_id']) ? 'parent' : 'instance';
        } else {
            $editContext = null;
        }
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
    <script>
        function toggleGroupMenu(type) {
            var el = document.getElementById('group_menu_' + type);
            if (!el) return;
            el.classList.toggle('hidden');
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
        <!-- Botões: Toggle de Visualização + Nova Conta -->
        <div class="mb-6 flex justify-end items-center">
                <a href="?<?= http_build_query(array_merge($_GET, ['view' => $viewMode === 'calendar' ? 'list' : 'calendar'])) ?>" 
                   class="bg-secondary hover:bg-gray-700 text-white px-4 py-2 rounded-md transition-colors mr-4">
                    <i class="fas <?= $viewMode === 'calendar' ? 'fa-list' : 'fa-calendar-alt' ?> mr-2"></i>
                    <?= $viewMode === 'calendar' ? 'Lista' : 'Calendário' ?>
                </a>
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
                <form method="GET">
                    <input type="hidden" name="action" value="list">
<input type="hidden" name="view" value="<?= $viewMode ?>">
                    
                    <!-- Todos os filtros em uma linha -->
                    <div class="grid grid-cols-1 lg:grid-cols-6 gap-3 items-end">
                        <!-- Categoria -->
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Categoria</label>
                            <select name="filter_category" class="border border-gray-300 rounded-md px-3 h-10 w-full text-sm">
                                <option value="">Todas</option>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?= $category['id'] ?>" <?= $filters['category_id'] == $category['id'] ? 'selected' : '' ?>>
                                    <?= (($category['type'] ?? '') === 'receita' ? '+' : '-') . ' ' . htmlspecialchars($category['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Status -->
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Status</label>
                            <select name="filter_status" class="border border-gray-300 rounded-md px-3 h-10 w-full text-sm">
                                <option value="">Todos</option>
                                <option value="pendente" <?= $filters['status'] == 'pendente' ? 'selected' : '' ?>>Pendente</option>
                                <option value="paga" <?= $filters['status'] == 'paga' ? 'selected' : '' ?>>Paga</option>
                                <option value="recebida" <?= $filters['status'] == 'recebida' ? 'selected' : '' ?>>Recebida</option>
                            </select>
                        </div>

                        <!-- Tipo de Filtro de Data -->
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Filtro de Data</label>
                            <select name="filter_date_filter_type" id="dateFilterType" class="border border-gray-300 rounded-md px-3 h-10 w-full text-sm" onchange="toggleDateFilters()">
                                <option value="">Sem filtro</option>
                                <option value="all" <?= $filters['date_filter_type'] == 'all' ? 'selected' : '' ?>>Todas (até <?= htmlspecialchars($horizonLabel) ?>)</option>
                                <option value="month_year" <?= $filters['date_filter_type'] == 'month_year' ? 'selected' : '' ?>>Mês/Ano</option>
                                <option value="year" <?= $filters['date_filter_type'] == 'year' ? 'selected' : '' ?>>Ano</option>
                                <option value="custom" <?= $filters['date_filter_type'] == 'custom' ? 'selected' : '' ?>>Customizado</option>
                            </select>
                        </div>

                        <!-- Filtros de Data Dinâmicos -->
                        <div class="lg:col-span-2">
                            <!-- Filtro Mês/Ano -->
                            <div id="monthYearFilter" style="display: <?= $filters['date_filter_type'] == 'month_year' ? 'block' : 'none' ?>">
                                <label class="block text-xs font-medium text-gray-700 mb-1">Mês/Ano</label>
                                <div class="grid grid-cols-2 gap-2">
                                    <select name="filter_date_month" class="border border-gray-300 rounded-md px-3 h-10 w-full text-sm">
                                        <option value="">Mês</option>
                                        <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?= $m ?>" <?= $filters['date_month'] == $m ? 'selected' : '' ?>>
                                            <?= date('M', mktime(0, 0, 0, $m, 1)) ?>
                                        </option>
                                        <?php endfor; ?>
                                    </select>
                                    <select name="filter_date_year" class="border border-gray-300 rounded-md px-3 h-10 w-full text-sm">
                                         <option value="">Ano</option>
                                         <?php for ($y = 2025; $y <= date('Y') + 5; $y++): ?>
                                         <option value="<?= $y ?>" <?= $filters['date_year'] == $y ? 'selected' : '' ?>><?= $y ?></option>
                                         <?php endfor; ?>
                                     </select>
                                </div>
                            </div>

                            <!-- Filtro Ano -->
                            <div id="yearFilter" style="display: <?= $filters['date_filter_type'] == 'year' ? 'block' : 'none' ?>">
                                <label class="block text-xs font-medium text-gray-700 mb-1">Ano</label>
                                <select name="filter_date_year_only" class="border border-gray-300 rounded-md px-3 h-10 w-full text-sm">
                                     <option value="">Selecione</option>
                                     <?php for ($y = 2025; $y <= date('Y') + 5; $y++): ?>
                                     <option value="<?= $y ?>" <?= $filters['date_year'] == $y ? 'selected' : '' ?>><?= $y ?></option>
                                     <?php endfor; ?>
                                 </select>
                            </div>

                            <!-- Filtro Customizado -->
                            <div id="customFilter" style="display: <?= $filters['date_filter_type'] == 'custom' ? 'block' : 'none' ?>">
                                <label class="block text-xs font-medium text-gray-700 mb-1">Período</label>
                                <div class="grid grid-cols-2 gap-2">
                                    <input type="date" name="filter_date_from" value="<?= $filters['date_from'] ?>" 
                                           class="border border-gray-300 rounded-md px-3 h-10 w-full text-sm">
                                    <input type="date" name="filter_date_to" value="<?= $filters['date_to'] ?>" 
                                           class="border border-gray-300 rounded-md px-3 h-10 w-full text-sm">
                                </div>
                            </div>
                        </div>

                        <!-- Botões -->
                        <div class="flex items-end space-x-2">
                            <button type="submit" class="bg-gray-600 hover:bg-gray-700 text-white px-4 h-10 rounded-md flex items-center justify-center text-sm">
                                <i class="fas fa-search mr-1"></i>
                                Filtrar
                            </button>
                            <a href="?action=list" class="bg-gray-400 hover:bg-gray-500 text-white px-3 h-10 rounded-md flex items-center justify-center text-sm">
                                <i class="fas fa-times"></i>
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($viewMode === 'calendar'): ?>
        <?php
        // Parâmetros do calendário
        $calendarYear = (int)($filters['date_year'] ?? date('Y'));
        $calendarMonth = (int)($filters['date_month'] ?? date('n'));
        $filterType = $filters['date_filter_type'] ?? '';
        if ($filterType === 'custom' && !empty($filters['date_from'])) {
            $calendarYear = (int)date('Y', strtotime($filters['date_from']));
            $calendarMonth = (int)date('n', strtotime($filters['date_from']));
        }
        if ($filterType === 'all' || $filterType === 'year') {
            $calendarYear = (int)date('Y');
            $calendarMonth = (int)date('n');
        }
        $firstDayOfMonth = strtotime(sprintf('%04d-%02d-01', $calendarYear, $calendarMonth));
        $daysInMonth = (int)date('t', $firstDayOfMonth);
        $startWeekday = (int)date('w', $firstDayOfMonth); // 0=Domingo
        $monthLabelCal = ($monthsPt[$calendarMonth - 1] ?? date('M')) . '/' . $calendarYear;
        
        // Agrupar contas por data
        $accountsAll = array_merge($accountsExpenses ?? [], $accountsRevenues ?? []);
        $accountsByDate = [];
        foreach ($accountsAll as $acc) {
            $d = $acc['due_date'];
            if ((int)date('Y', strtotime($d)) === $calendarYear && (int)date('n', strtotime($d)) === $calendarMonth) {
                if (!isset($accountsByDate[$d])) $accountsByDate[$d] = [];
                $accountsByDate[$d][] = $acc;
            }
        }
        ?>
        <div class="bg-white rounded-lg shadow mb-6">
            <div class="p-6 border-b border-gray-200 flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-900">
                    <i class="fas fa-calendar-alt mr-2"></i> Calendário de Contas
                    <span class="text-sm font-normal text-gray-500 ml-2">(<?= htmlspecialchars($monthLabelCal) ?>)</span>
                </h2>
                <div class="text-sm text-gray-600">Legenda:
                    <span class="inline-flex items-center ml-2"><span class="w-2 h-2 bg-red-500 rounded-full mr-1"></span> Pagar</span>
                    <span class="inline-flex items-center ml-3"><span class="w-2 h-2 bg-green-500 rounded-full mr-1"></span> Receber</span>
                </div>
            </div>
            <div class="p-4">
                <div class="grid grid-cols-7 gap-2">
                    <?php
                    // Cabeçalhos dos dias da semana
                    $weekdays = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
                    foreach ($weekdays as $w) {
                        echo '<div class="text-xs font-semibold text-gray-500 text-center">'.$w.'</div>';
                    }
                    // Células vazias antes do primeiro dia
                    for ($i = 0; $i < $startWeekday; $i++) {
                        echo '<div class="min-h-[100px] bg-gray-50 rounded border border-gray-200"></div>';
                    }
                    for ($day = 1; $day <= $daysInMonth; $day++) {
                        $dateStr = sprintf('%04d-%02d-%02d', $calendarYear, $calendarMonth, $day);
                        $items = $accountsByDate[$dateStr] ?? [];
                        echo '<div class="min-h-[120px] bg-white rounded border border-gray-200 p-2">';
                        echo '<div class="text-xs text-gray-500 mb-1">'. $day .'</div>';
                        if (empty($items)) {
                            echo '<div class="text-xs text-gray-300">—</div>';
                        } else {
                            foreach ($items as $acc) {
                                $isReceita = (($acc['type'] ?? '') === 'receita');
                                $colorClass = $isReceita ? 'text-green-700' : 'text-red-700';
                                $bgClass = $isReceita ? 'bg-green-50' : 'bg-red-50';
                                $statusLabel = htmlspecialchars($acc['status'] ?? '');
                                $desc = htmlspecialchars($acc['description'] ?? '');
                                $cat = htmlspecialchars($acc['category_name'] ?? '');
                                $val = formatCurrency($acc['amount'] ?? 0);
                                $due = formatDate($acc['due_date'] ?? $dateStr);
                                $id = (int)($acc['id'] ?? 0);
                                $attachment = htmlspecialchars($acc['attachment'] ?? '');
                                $isRecurring = !empty($acc['is_recurring']) || !empty($acc['recurring_parent_id']);
                                echo '<button class="w-full text-left text-xs '.$colorClass.' '.$bgClass.' rounded px-2 py-1 mb-1 hover:opacity-80 calendar-item" ' .
                                     'data-id="'.$id.'" data-description="'.$desc.'" data-category="'.$cat.'" data-amount="'.$val.'" data-due="'.$due.'" ' .
                                     'data-status="'.$statusLabel.'" data-attachment="'.$attachment.'" data-type="'.($isReceita ? 'receber' : 'pagar').'">' .
                                     ($isRecurring ? '<i class=\'fas fa-sync-alt mr-1\' title=\'Conta recorrente\'></i>' : '') .
                                     $desc.' • '.$val .
                                     '</button>';
                            }
                        }
                        echo '</div>';
                    }
                    ?>
                </div>
            </div>
        </div>

        <!-- Modal de Detalhes do Calendário -->
        <div id="calendarItemModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
            <div class="bg-white rounded-lg shadow-lg w-full max-w-lg">
                <div class="p-4 border-b flex items-center justify-between">
                    <h3 class="text-lg font-semibold">Detalhes da Conta</h3>
                    <button class="text-gray-500 hover:text-gray-700" onclick="closeCalendarModal()"><i class="fas fa-times"></i></button>
                </div>
                <div class="p-4 space-y-3">
                    <div class="text-sm"><span class="font-semibold">Descrição:</span> <span id="cal_desc"></span></div>
                    <div class="text-sm"><span class="font-semibold">Categoria:</span> <span id="cal_cat"></span></div>
                    <div class="text-sm"><span class="font-semibold">Valor:</span> <span id="cal_val"></span></div>
                    <div class="text-sm"><span class="font-semibold">Vencimento:</span> <span id="cal_due"></span></div>
                    <div class="text-sm"><span class="font-semibold">Status:</span> <span id="cal_status"></span></div>
                    <div class="text-sm"><span class="font-semibold">Comprovante:</span> <span id="cal_att"></span></div>
                    <div class="text-sm"><span class="font-semibold">Ações:</span>
                        <div class="mt-2 flex items-center gap-3">
                            <a id="cal_edit" href="#" class="text-blue-600 hover:text-blue-800"><i class="fas fa-edit"></i> Editar</a>
                            <form id="cal_delete_form" method="POST" onsubmit="return confirm('Tem certeza que deseja excluir esta conta?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" id="cal_delete_id" value="">
                                <button type="submit" class="text-red-600 hover:text-red-800"><i class="fas fa-trash"></i> Excluir</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <script>
        document.addEventListener('click', function(e){
          const btn = e.target.closest('.calendar-item');
          if (!btn) return;
          const modal = document.getElementById('calendarItemModal');
          document.getElementById('cal_desc').textContent = btn.dataset.description || '';
          document.getElementById('cal_cat').textContent = btn.dataset.category || '';
          document.getElementById('cal_val').textContent = btn.dataset.amount || '';
          document.getElementById('cal_due').textContent = btn.dataset.due || '';
          document.getElementById('cal_status').textContent = btn.dataset.status || '';
          const att = btn.dataset.attachment || '';
          const attSpan = document.getElementById('cal_att');
          attSpan.innerHTML = att ? ('<a href="'+att+'" target="_blank" class="text-blue-600 hover:text-blue-800">Ver</a>') : '<span class="text-gray-400">-</span>';
          const id = btn.dataset.id || '';
          document.getElementById('cal_delete_id').value = id;
          document.getElementById('cal_edit').href = '?action=edit&id=' + id;
          modal.classList.remove('hidden');
          modal.classList.add('flex');
        });
        function closeCalendarModal(){
          const modal = document.getElementById('calendarItemModal');
          modal.classList.add('hidden');
          modal.classList.remove('flex');
        }
        </script>
        <?php else: ?>
        <!-- Contas a Pagar -->
        <div class="bg-white rounded-lg shadow mb-6">
            <div class="p-6 border-b border-gray-200">
                <div class="flex justify-between items-center">
                    <h2 class="text-xl font-semibold text-red-700">
                        <i class="fas fa-credit-card mr-2"></i>
                        Contas a Pagar
                        <span class="text-sm font-normal text-gray-500 ml-2">(<?= $totalExpenses ?> contas • <?= $periodLabel ?>)</span>
                    </h2>
                    <div class="flex items-center space-x-4">
                        <div class="flex items-center">
                            <div class="relative mr-3">
                                <button type="button" data-group-toggle="group_menu_expenses" onclick="toggleGroupMenu('expenses')" class="px-2 py-1 text-sm rounded bg-red-100 text-red-700 hover:bg-red-200">
                                    <i class="fas fa-layer-group mr-1"></i>Agrupar
                                </button>
                                <div id="group_menu_expenses" class="absolute mt-1 bg-white border border-gray-200 rounded shadow text-sm hidden z-10 min-w-[180px]">
                                    <a href="?<?= http_build_query(array_merge($_GET, ['group_expenses' => 'category'])) ?>" class="block px-3 py-2 hover:bg-gray-100">Por categoria</a>
                                    <a href="?<?= http_build_query(array_merge($_GET, ['group_expenses' => 'status'])) ?>" class="block px-3 py-2 hover:bg-gray-100">Por status</a>
                                    <a href="?<?= http_build_query(array_merge($_GET, ['group_expenses' => 'none'])) ?>" class="block px-3 py-2 hover:bg-gray-100">Desagrupar</a>
                                </div>
                            </div>
                            <label for="items_per_page_expenses" class="text-sm text-gray-600 mr-2">Itens por página:</label>
                            <select id="items_per_page_expenses" name="items_per_page_expenses" class="border border-gray-300 rounded px-2 py-1 text-sm" onchange="updatePagination('expenses')">
                                <option value="25" <?= $itemsPerPageExpenses == 25 ? 'selected' : '' ?>>25</option>
                                <option value="50" <?= $itemsPerPageExpenses == 50 ? 'selected' : '' ?>>50</option>
                                <option value="100" <?= $itemsPerPageExpenses == 100 ? 'selected' : '' ?>>100</option>
                            </select>
                        </div>
                        <?php if ($totalPagesExpenses > 1): ?>
                        <div class="flex items-center space-x-2">
                            <span class="text-sm text-gray-600">Página <?= $pageExpenses ?> de <?= $totalPagesExpenses ?></span>
                            <?php if ($pageExpenses > 1): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page_expenses' => $pageExpenses - 1])) ?>" class="px-2 py-1 bg-gray-200 text-gray-700 rounded hover:bg-gray-300 text-sm">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <?php endif; ?>
                            <?php if ($pageExpenses < $totalPagesExpenses): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page_expenses' => $pageExpenses + 1])) ?>" class="px-2 py-1 bg-gray-200 text-gray-700 rounded hover:bg-gray-300 text-sm">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
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
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Parcelas restantes</th>
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
                            <td colspan="8" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-inbox text-4xl mb-4"></i>
                                <p>Nenhuma conta a pagar encontrada</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php if ($groupModeExpenses === 'category'): ?>
                        <?php 
                            $groupedExpenses = [];
                            foreach ($accountsExpenses as $acc) {
                                $cat = trim($acc['category_name'] ?? '') !== '' ? $acc['category_name'] : 'Sem categoria';
                                if (!isset($groupedExpenses[$cat])) { $groupedExpenses[$cat] = []; }
                                $groupedExpenses[$cat][] = $acc;
                            }
                        ?>
                        <?php foreach ($groupedExpenses as $catName => $items): ?>
                            <?php $subtotal = 0.0; foreach ($items as $it) { $subtotal += floatval($it['amount']); } ?>
                            <tr class="bg-red-100">
                                <td class="px-6 py-2 font-semibold text-red-700" colspan="3">
                                    <i class="fas fa-folder-open mr-2"></i>
                                    <?= htmlspecialchars($catName) ?>
                                </td>
                                <td class="px-6 py-2 text-right font-semibold text-red-700" colspan="5">
                                    Subtotal: R$ <?= number_format($subtotal, 2, ',', '.') ?>
                                </td>
                            </tr>
                            <?php foreach ($items as $account): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4">
                                    <div class="flex items-center">
                                        <?php if (!empty($account['is_recurring']) || !empty($account['recurring_parent_id'])): ?>
                                        <i class="fas fa-sync-alt text-blue-500 mr-2" title="Conta recorrente"></i>
                                        <?php endif; ?>
                                        <div>
                                            <div class="text-sm font-medium text-gray-900 flex items-center">
                                                <?= htmlspecialchars($account['description']) ?>
                                                <?php if ($account['url']): ?>
                                                <a href="<?= htmlspecialchars($account['url']) ?>" target="_blank" class="ml-2 text-blue-600 hover:text-blue-800" title="Abrir URL associada">
                                                    <i class="fas fa-external-link-alt"></i>
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($account['name'])): ?>
                                                <div class="text-xs text-gray-500"><?= htmlspecialchars($account['name']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900"><?= htmlspecialchars($account['category_name']) ?></td>
                                <td class="px-6 py-4 text-sm font-medium text-red-600">
                                    <?= formatCurrency($account['amount']) ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900"><?= formatDate($account['due_date']) ?></td>
                                <td class="px-6 py-4 text-sm text-gray-900">
                                    <?php
                                        $parentId = !empty($account['recurring_parent_id']) ? $account['recurring_parent_id'] : (intval($account['is_recurring']) === 1 ? $account['id'] : null);
                                        $remainingInstallmentsDisplay = '<span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs">-</span>';
                                        if ($parentId) {
                                            $recSet = $recurringModel->getByAccountId($parentId);
                                      $maxOccurrences = intval($recSet['max_occurrences'] ?? 0);
                                      $endDate = $recSet['end_date'] ?? null;
                                      $noEnd = empty($endDate) || $endDate === '0000-00-00';
                                      if ((!$recSet) || (($maxOccurrences <= 0) && $noEnd)) {
                                          $remainingInstallmentsDisplay = '<span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs">sem data de término</span>';
                                      } else {
                                          $stmtPendingChildren = $recurringModel->getConnection()->prepare("SELECT COUNT(*) FROM accounts WHERE recurring_parent_id = :pid AND status = 'pendente'");
                                          $stmtPendingChildren->execute([':pid' => $parentId]);
                                          $pendingChildren = intval($stmtPendingChildren->fetchColumn());

                                          $stmtParentStatus = $recurringModel->getConnection()->prepare("SELECT status FROM accounts WHERE id = :pid");
                                          $stmtParentStatus->execute([':pid' => $parentId]);
                                          $parentStatus = $stmtParentStatus->fetchColumn();
                                          $pendingParent = ($parentStatus === 'pendente') ? 1 : 0;

                                          $remainingInstallmentsDisplay = $pendingChildren;
                                      }
                                        } else {
                                            $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE user_id = :uid AND type = :type AND description = :desc");
                                            $stmtTotal->execute([':uid' => $userId, ':type' => $account['type'], ':desc' => $account['description']]);
                                            $total = intval($stmtTotal->fetchColumn());
                                            if ($total <= 1) {
                                                $remainingInstallmentsDisplay = '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-blue-100 text-blue-800">parcela única</span>';
                                            } else {
                                                $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE user_id = :uid AND type = :type AND description = :desc AND status = 'pendente'");
                                                $stmtPending->execute([':uid' => $userId, ':type' => $account['type'], ':desc' => $account['description']]);
                                                $remainingInstallmentsDisplay = intval($stmtPending->fetchColumn());
                                            }
                                        }
                                        $label = is_numeric($remainingInstallmentsDisplay) ? (string)$remainingInstallmentsDisplay : strip_tags((string)$remainingInstallmentsDisplay);
                                         echo '<span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs">' . htmlspecialchars($label) . '</span>';
                                    ?>
                                </td>
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
                                        <a href="javascript:void(0)" onclick="openLightbox('<?= htmlspecialchars($account['attachment']) ?>')" 
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
                                    <a href="?action=edit&id=<?= $account['id'] ?><?= (intval($account['is_recurring']) === 1 ? (empty($account['recurring_parent_id']) ? '&edit_context=parent' : '&edit_context=instance') : '') ?>" 
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
                        <?php endforeach; ?>
                        <?php elseif ($groupModeExpenses === 'status'): ?>
                        <?php 
                            $groupedStatusExpenses = [];
                            foreach ($accountsExpenses as $acc) {
                                $st = $acc['status'] ?? '';
                                $key = ($st === 'pendente') ? 'pendente' : (($st === 'paga') ? 'paga' : $st);
                                if (!isset($groupedStatusExpenses[$key])) { $groupedStatusExpenses[$key] = []; }
                                $groupedStatusExpenses[$key][] = $acc;
                            }
                            $statusOrderExpenses = ['pendente', 'paga'];
                        ?>
                        <?php foreach ($statusOrderExpenses as $stName): ?>
                            <?php if (empty($groupedStatusExpenses[$stName])) continue; ?>
                            <?php 
                                $items = $groupedStatusExpenses[$stName]; 
                                usort($items, function($a,$b){ 
                                    $da = strtotime($a['due_date'] ?? '') ?: 0; 
                                    $db = strtotime($b['due_date'] ?? '') ?: 0; 
                                    return $da <=> $db; 
                                }); 
                                $subtotal = 0.0; foreach ($items as $it) { $subtotal += floatval($it['amount']); } 
                            ?>
                            <tr class="bg-red-100">
                                <td class="px-6 py-2 font-semibold text-red-700" colspan="3">
                                    <i class="fas fa-folder-open mr-2"></i>
                                    <?= htmlspecialchars(ucfirst($stName)) ?>
                                </td>
                                <td class="px-6 py-2 text-right font-semibold text-red-700" colspan="5">
                                    Subtotal: R$ <?= number_format($subtotal, 2, ',', '.') ?>
                                </td>
                            </tr>
                            <?php foreach ($items as $account): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4">
                                    <div class="flex items-center">
                                        <?php if (!empty($account['is_recurring']) || !empty($account['recurring_parent_id'])): ?>
                                        <i class="fas fa-sync-alt text-blue-500 mr-2" title="Conta recorrente"></i>
                                        <?php endif; ?>
                                        <div>
                                            <div class="text-sm font-medium text-gray-900 flex items-center">
                                                <?= htmlspecialchars($account['description']) ?>
                                                <?php if ($account['url']): ?>
                                                <a href="<?= htmlspecialchars($account['url']) ?>" target="_blank" class="ml-2 text-blue-600 hover:text-blue-800" title="Abrir URL associada">
                                                    <i class="fas fa-external-link-alt"></i>
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($account['name'])): ?>
                                                <div class="text-xs text-gray-500"><?= htmlspecialchars($account['name']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900"><?= htmlspecialchars($account['category_name']) ?></td>
                                <td class="px-6 py-4 text-sm font-medium text-red-600">
                                    <?= formatCurrency($account['amount']) ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900"><?= formatDate($account['due_date']) ?></td>
                                <td class="px-6 py-4 text-sm text-gray-900">
                                    <?php
                                        $parentId = !empty($account['recurring_parent_id']) ? $account['recurring_parent_id'] : (intval($account['is_recurring']) === 1 ? $account['id'] : null);
                                        $remainingInstallmentsDisplay = '<span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs">-</span>';
                                        if ($parentId) {
                                                $recSet = $recurringModel->getByAccountId($parentId);
                                                $maxOccurrences = intval($recSet['max_occurrences'] ?? 0);
                                                $endDate = $recSet['end_date'] ?? null;
                                                $noEnd = empty($endDate) || $endDate === '0000-00-00';
                                                if ((!$recSet) || (($maxOccurrences <= 0) && $noEnd)) {
                                                $remainingInstallmentsDisplay = '<span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs">sem data de término</span>';
                                            } else {
                                                $stmtPendingChildren = $recurringModel->getConnection()->prepare("SELECT COUNT(*) FROM accounts WHERE recurring_parent_id = :pid AND status = 'pendente'");
                                                $stmtPendingChildren->execute([':pid' => $parentId]);
                                                $pendingChildren = intval($stmtPendingChildren->fetchColumn());

                                                $stmtParentStatus = $recurringModel->getConnection()->prepare("SELECT status FROM accounts WHERE id = :pid");
                                                $stmtParentStatus->execute([':pid' => $parentId]);
                                                $parentStatus = $stmtParentStatus->fetchColumn();
                                                $pendingParent = ($parentStatus === 'pendente') ? 1 : 0;

                                                $remainingInstallmentsDisplay = $pendingChildren;
                                            }
                                        } else {
                                            $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE user_id = :uid AND type = :type AND description = :desc");
                                            $stmtTotal->execute([':uid' => $userId, ':type' => $account['type'], ':desc' => $account['description']]);
                                            $total = intval($stmtTotal->fetchColumn());
                                            if ($total <= 1) {
                                                $remainingInstallmentsDisplay = '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-blue-100 text-blue-800">parcela única</span>';
                                            } else {
                                                $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE user_id = :uid AND type = :type AND description = :desc AND status = 'pendente'");
                                                $stmtPending->execute([':uid' => $userId, ':type' => $account['type'], ':desc' => $account['description']]);
                                                $remainingInstallmentsDisplay = intval($stmtPending->fetchColumn());
                                            }
                                        }
                                        $label = is_numeric($remainingInstallmentsDisplay) ? (string)$remainingInstallmentsDisplay : strip_tags((string)$remainingInstallmentsDisplay);
                                          echo '<span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs">' . htmlspecialchars($label) . '</span>';
                                    ?>
                                </td>
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
                                        <a href="javascript:void(0)" onclick="openLightbox('<?= htmlspecialchars($account['attachment']) ?>')" 
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
                                    <a href="?action=edit&id=<?= $account['id'] ?><?= (intval($account['is_recurring']) === 1 ? (empty($account['recurring_parent_id']) ? '&edit_context=parent' : '&edit_context=instance') : '') ?>" 
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
                        <?php endforeach; ?>
                        <?php else: ?>
                        <?php foreach ($accountsExpenses as $account): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <div class="flex items-center">
                                    <?php if (!empty($account['is_recurring']) || !empty($account['recurring_parent_id'])): ?>
                                    <i class="fas fa-sync-alt text-blue-500 mr-2" title="Conta recorrente"></i>
                                    <?php endif; ?>
                                    <div>
                                        <div class="text-sm font-medium text-gray-900 flex items-center">
                                            <?= htmlspecialchars($account['description']) ?>
                                            <?php if ($account['url']): ?>
                                            <a href="<?= htmlspecialchars($account['url']) ?>" target="_blank" class="ml-2 text-blue-600 hover:text-blue-800" title="Abrir URL associada">
                                                <i class="fas fa-external-link-alt"></i>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($account['name'])): ?>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars($account['name']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?= htmlspecialchars($account['category_name']) ?></td>
                            <td class="px-6 py-4 text-sm font-medium text-red-600">
                                <?= formatCurrency($account['amount']) ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?= formatDate($account['due_date']) ?></td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php
                                    $parentId = !empty($account['recurring_parent_id']) ? $account['recurring_parent_id'] : (intval($account['is_recurring']) === 1 ? $account['id'] : null);
                                    $remainingInstallmentsDisplay = '<span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs">-</span>';
                                    if ($parentId) {
                                        $recSet = $recurringModel->getByAccountId($parentId);
                                        $maxOccurrences = intval($recSet['max_occurrences'] ?? 0);
                                        $endDate = $recSet['end_date'] ?? null;
                                        $noEnd = empty($endDate) || $endDate === '0000-00-00';
                                        if ((!$recSet) || (($maxOccurrences <= 0) && $noEnd)) {
                                            $remainingInstallmentsDisplay = '<span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs">sem data de término</span>';
                                        } else {
                                            $stmtPendingChildren = $recurringModel->getConnection()->prepare("SELECT COUNT(*) FROM accounts WHERE recurring_parent_id = :pid AND status = 'pendente'");
                                            $stmtPendingChildren->execute([':pid' => $parentId]);
                                            $pendingChildren = intval($stmtPendingChildren->fetchColumn());

                                            $stmtParentStatus = $recurringModel->getConnection()->prepare("SELECT status FROM accounts WHERE id = :pid");
                                            $stmtParentStatus->execute([':pid' => $parentId]);
                                            $parentStatus = $stmtParentStatus->fetchColumn();
                                            $pendingParent = ($parentStatus === 'pendente') ? 1 : 0;

                                            $remainingInstallmentsDisplay = $pendingChildren;
                                        }
                                    } else {
                                        $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE user_id = :uid AND type = :type AND description = :desc");
                                        $stmtTotal->execute([':uid' => $userId, ':type' => $account['type'], ':desc' => $account['description']]);
                                        $total = intval($stmtTotal->fetchColumn());
                                        if ($total <= 1) {
                                            $remainingInstallmentsDisplay = '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-blue-100 text-blue-800">parcela única</span>';
                                        } else {
                                            $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE user_id = :uid AND type = :type AND description = :desc AND status = 'pendente'");
                                            $stmtPending->execute([':uid' => $userId, ':type' => $account['type'], ':desc' => $account['description']]);
                                            $remainingInstallmentsDisplay = intval($stmtPending->fetchColumn());
                                        }
                                    }
                                    $label = is_numeric($remainingInstallmentsDisplay) ? (string)$remainingInstallmentsDisplay : strip_tags((string)$remainingInstallmentsDisplay);
                                      echo '<span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs">' . htmlspecialchars($label) . '</span>';
                                ?>
                            </td>
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
                                    <a href="javascript:void(0)" onclick="openLightbox('<?= htmlspecialchars($account['attachment']) ?>')" 
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
                                <a href="?action=edit&id=<?= $account['id'] ?><?= (intval($account['is_recurring']) === 1 ? (empty($account['recurring_parent_id']) ? '&edit_context=parent' : '&edit_context=instance') : '') ?>" 
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
                        <?php endif; ?>
                        
                        <!-- Linha de Totais (única linha) -->
                        <tr class="bg-red-50 border-t-2 border-red-200 font-semibold">
                            <td class="px-6 py-4 text-red-700" colspan="3">
                                <div class="flex items-center">
                                    <i class="fas fa-layer-group mr-2"></i>
                                    Totais
                                    <span class="ml-2 text-xs bg-red-100 text-red-600 px-2 py-1 rounded-full cursor-help" 
                                          title="Totais considerando todas as contas filtradas (categoria, data, etc.). O status é ignorado nos subtotais de Pendente e Pago.">
                                        <i class="fas fa-info-circle"></i>
                                    </span>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-right" colspan="5">
                                <div class="flex items-center justify-end gap-6">
                                    <div class="flex items-center text-red-700">
                                        <i class="fas fa-hourglass-half mr-2"></i>
                                        <span class="mr-2">Pendente</span>
                                        <span class="font-bold">R$ <?= number_format($totalAmountExpensesPending, 2, ',', '.') ?></span>
                                    </div>
                                    <div class="flex items-center text-red-700">
                                        <i class="fas fa-check-circle mr-2"></i>
                                        <span class="mr-2">Pago</span>
                                        <span class="font-bold">R$ <?= number_format($totalAmountExpensesPaid, 2, ',', '.') ?></span>
                                    </div>
                                    <div class="flex items-center text-red-700">
                                        <i class="fas fa-calculator mr-2"></i>
                                        <span class="mr-2">Total</span>
                                        <span class="font-bold text-lg">R$ <?= number_format($totalAmountExpenses, 2, ',', '.') ?></span>
                                        <?php if ($totalExpenses > $itemsPerPageExpenses): ?>
                                        <span class="ml-2 text-xs bg-red-100 text-red-600 px-2 py-1 rounded-full cursor-help" 
                                              title="Este total inclui todas as <?= $totalExpenses ?> contas filtradas, não apenas as <?= min($itemsPerPageExpenses, count($accountsExpenses)) ?> exibidas nesta página">
                                            <i class="fas fa-info-circle"></i>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Contas a Receber -->
        <div class="bg-white rounded-lg shadow">
            <div class="p-6 border-b border-gray-200">
                <div class="flex justify-between items-center">
                    <h2 class="text-xl font-semibold text-green-700">
                        <i class="fas fa-money-bill-wave mr-2"></i>
                        Contas a Receber
                        <span class="text-sm font-normal text-gray-500 ml-2">(<?= $totalRevenues ?> contas • <?= $periodLabel ?>)</span>
                    </h2>
                    <div class="flex items-center space-x-4">
                        <div class="flex items-center">
                            <div class="relative mr-3">
                                <button type="button" data-group-toggle="group_menu_revenues" onclick="toggleGroupMenu('revenues')" class="px-2 py-1 text-sm rounded bg-green-100 text-green-700 hover:bg-green-200">
                                    <i class="fas fa-layer-group mr-1"></i>Agrupar
                                </button>
                                <div id="group_menu_revenues" class="absolute mt-1 bg-white border border-gray-200 rounded shadow text-sm hidden z-10 min-w-[180px]">
                                    <a href="?<?= http_build_query(array_merge($_GET, ['group_revenues' => 'category'])) ?>" class="block px-3 py-2 hover:bg-gray-100">Por categoria</a>
                                    <a href="?<?= http_build_query(array_merge($_GET, ['group_revenues' => 'status'])) ?>" class="block px-3 py-2 hover:bg-gray-100">Por status</a>
                                    <a href="?<?= http_build_query(array_merge($_GET, ['group_revenues' => 'none'])) ?>" class="block px-3 py-2 hover:bg-gray-100">Desagrupar</a>
                                </div>
                            </div>
                            <label for="items_per_page_revenues" class="text-sm text-gray-600 mr-2">Itens por página:</label>
                            <select id="items_per_page_revenues" name="items_per_page_revenues" class="border border-gray-300 rounded px-2 py-1 text-sm" onchange="updatePagination('revenues')">
                                <option value="25" <?= $itemsPerPageRevenues == 25 ? 'selected' : '' ?>>25</option>
                                <option value="50" <?= $itemsPerPageRevenues == 50 ? 'selected' : '' ?>>50</option>
                                <option value="100" <?= $itemsPerPageRevenues == 100 ? 'selected' : '' ?>>100</option>
                            </select>
                        </div>
                        <?php if ($totalPagesRevenues > 1): ?>
                        <div class="flex items-center space-x-2">
                            <span class="text-sm text-gray-600">Página <?= $pageRevenues ?> de <?= $totalPagesRevenues ?></span>
                            <?php if ($pageRevenues > 1): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page_revenues' => $pageRevenues - 1])) ?>" class="px-2 py-1 bg-gray-200 text-gray-700 rounded hover:bg-gray-300 text-sm">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <?php endif; ?>
                            <?php if ($pageRevenues < $totalPagesRevenues): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page_revenues' => $pageRevenues + 1])) ?>" class="px-2 py-1 bg-gray-200 text-gray-700 rounded hover:bg-gray-300 text-sm">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
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
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Parcelas restantes</th>
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
                            <td colspan="8" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-inbox text-4xl mb-4"></i>
                                <p>Nenhuma conta a receber encontrada</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php if ($groupModeRevenues === 'category'): ?>
                        <?php 
                            $groupedRevenues = [];
                            foreach ($accountsRevenues as $acc) {
                                $cat = trim($acc['category_name'] ?? '') !== '' ? $acc['category_name'] : 'Sem categoria';
                                if (!isset($groupedRevenues[$cat])) { $groupedRevenues[$cat] = []; }
                                $groupedRevenues[$cat][] = $acc;
                            }
                        ?>
                        <?php foreach ($groupedRevenues as $catName => $items): ?>
                            <?php $subtotal = 0.0; foreach ($items as $it) { $subtotal += floatval($it['amount']); } ?>
                            <tr class="bg-green-100">
                                <td class="px-6 py-2 font-semibold text-green-700" colspan="3">
                                    <i class="fas fa-folder-open mr-2"></i>
                                    <?= htmlspecialchars($catName) ?>
                                </td>
                                <td class="px-6 py-2 text-right font-semibold text-green-700" colspan="5">
                                Subtotal: R$ <?= number_format($subtotal, 2, ',', '.') ?>
                            </td>
                            </tr>
                            <?php foreach ($items as $account): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4">
                                    <div class="flex items-center">
                                        <?php if (!empty($account['is_recurring']) || !empty($account['recurring_parent_id'])): ?>
                                        <i class="fas fa-sync-alt text-blue-500 mr-2" title="Conta recorrente"></i>
                                        <?php endif; ?>
                                        <div>
                                            <div class="text-sm font-medium text-gray-900 flex items-center">
                                                <?= htmlspecialchars($account['description']) ?>
                                                <?php if ($account['url']): ?>
                                                <a href="<?= htmlspecialchars($account['url']) ?>" target="_blank" class="ml-2 text-blue-600 hover:text-blue-800" title="Abrir URL associada">
                                                    <i class="fas fa-external-link-alt"></i>
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($account['name'])): ?>
                                                <div class="text-xs text-gray-500"><?= htmlspecialchars($account['name']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900"><?= htmlspecialchars($account['category_name']) ?></td>
                                <td class="px-6 py-4 text-sm font-medium text-green-600">
                                    <?= formatCurrency($account['amount']) ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900"><?= formatDate($account['due_date']) ?></td>
                                <td class="px-6 py-4 text-sm text-gray-900">
                                    <?php
                                        $parentId = !empty($account['recurring_parent_id']) ? $account['recurring_parent_id'] : (intval($account['is_recurring']) === 1 ? $account['id'] : null);
                                        $remainingInstallmentsDisplay = '<span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs">-</span>';
                                        if ($parentId) {
                                                $recSet = $recurringModel->getByAccountId($parentId);
                                                $maxOccurrences = intval($recSet['max_occurrences'] ?? 0);
                                                $endDate = $recSet['end_date'] ?? null;
                                                $noEnd = empty($endDate) || $endDate === '0000-00-00';
                                                if ((!$recSet) || (($maxOccurrences <= 0) && $noEnd)) {
                                                $remainingInstallmentsDisplay = '<span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs">sem data de término</span>';
                                            } else {
                                                $stmtPendingChildren = $recurringModel->getConnection()->prepare("SELECT COUNT(*) FROM accounts WHERE recurring_parent_id = :pid AND status = 'pendente'");
                                                $stmtPendingChildren->execute([':pid' => $parentId]);
                                                $pendingChildren = intval($stmtPendingChildren->fetchColumn());

                                                $stmtParentStatus = $recurringModel->getConnection()->prepare("SELECT status FROM accounts WHERE id = :pid");
                                                $stmtParentStatus->execute([':pid' => $parentId]);
                                                $parentStatus = $stmtParentStatus->fetchColumn();
                                                $pendingParent = ($parentStatus === 'pendente') ? 1 : 0;

                                                  $remainingInstallmentsDisplay = $pendingChildren;
                                            }
                                        } else {
                                            $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE user_id = :uid AND type = :type AND description = :desc");
                                            $stmtTotal->execute([':uid' => $userId, ':type' => $account['type'], ':desc' => $account['description']]);
                                            $total = intval($stmtTotal->fetchColumn());
                                            if ($total <= 1) {
                                                $remainingInstallmentsDisplay = '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-blue-100 text-blue-800">parcela única</span>';
                                            } else {
                                                $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE user_id = :uid AND type = :type AND description = :desc AND status = 'pendente'");
                                                $stmtPending->execute([':uid' => $userId, ':type' => $account['type'], ':desc' => $account['description']]);
                                                $remainingInstallmentsDisplay = intval($stmtPending->fetchColumn());
                                            }
                                        }
                                        $label = is_numeric($remainingInstallmentsDisplay) ? (string)$remainingInstallmentsDisplay : strip_tags((string)$remainingInstallmentsDisplay);
                                        echo '<span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs">' . htmlspecialchars($label) . '</span>';
                                    ?>
                                </td>
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
                                        <a href="javascript:void(0)" onclick="openLightbox('<?= htmlspecialchars($account['attachment']) ?>')" 
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
                                    <a href="?action=edit&id=<?= $account['id'] ?><?= (intval($account['is_recurring']) === 1 ? (empty($account['recurring_parent_id']) ? '&edit_context=parent' : '&edit_context=instance') : '') ?>" 
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
                        <?php endforeach; ?>
                        <?php elseif ($groupModeRevenues === 'status'): ?>
                        <?php 
                            $groupedStatusRevenues = [];
                            foreach ($accountsRevenues as $acc) {
                                $st = $acc['status'] ?? '';
                                $key = ($st === 'pendente') ? 'pendente' : (($st === 'recebida') ? 'recebida' : $st);
                                if (!isset($groupedStatusRevenues[$key])) { $groupedStatusRevenues[$key] = []; }
                                $groupedStatusRevenues[$key][] = $acc;
                            }
                            $statusOrderRevenues = ['pendente', 'recebida'];
                        ?>
                        <?php foreach ($statusOrderRevenues as $stName): ?>
                            <?php if (empty($groupedStatusRevenues[$stName])) continue; ?>
                            <?php 
                                $items = $groupedStatusRevenues[$stName]; 
                                usort($items, function($a,$b){ 
                                    $da = strtotime($a['due_date'] ?? '') ?: 0; 
                                    $db = strtotime($b['due_date'] ?? '') ?: 0; 
                                    return $da <=> $db; 
                                }); 
                                $subtotal = 0.0; foreach ($items as $it) { $subtotal += floatval($it['amount']); } 
                            ?>
                            <tr class="bg-green-100">
                                <td class="px-6 py-2 font-semibold text-green-700" colspan="3">
                                    <i class="fas fa-folder-open mr-2"></i>
                                    <?= htmlspecialchars(ucfirst($stName)) ?>
                                </td>
                                <td class="px-6 py-2 text-right font-semibold text-green-700" colspan="5">
                                    Subtotal: R$ <?= number_format($subtotal, 2, ',', '.') ?>
                                </td>
                            </tr>
                            <?php foreach ($items as $account): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4">
                                    <div class="flex items-center">
                                        <?php if (!empty($account['is_recurring']) || !empty($account['recurring_parent_id'])): ?>
                                        <i class="fas fa-sync-alt text-blue-500 mr-2" title="Conta recorrente"></i>
                                        <?php endif; ?>
                                        <div>
                                            <div class="text-sm font-medium text-gray-900 flex items-center">
                                                <?= htmlspecialchars($account['description']) ?>
                                                <?php if ($account['url']): ?>
                                                <a href="<?= htmlspecialchars($account['url']) ?>" target="_blank" class="ml-2 text-blue-600 hover:text-blue-800" title="Abrir URL associada">
                                                    <i class="fas fa-external-link-alt"></i>
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($account['name'])): ?>
                                                <div class="text-xs text-gray-500"><?= htmlspecialchars($account['name']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900"><?= htmlspecialchars($account['category_name']) ?></td>
                                <td class="px-6 py-4 text-sm font-medium text-green-600">
                                    <?= formatCurrency($account['amount']) ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900"><?= formatDate($account['due_date']) ?></td>
                                <td class="px-6 py-4 text-sm text-gray-900">
                                    <?php
                                        $parentId = !empty($account['recurring_parent_id']) ? $account['recurring_parent_id'] : (intval($account['is_recurring']) === 1 ? $account['id'] : null);
                                        $remainingInstallmentsDisplay = '<span class="text-gray-400 text-xs">-</span>';
                                        if ($parentId) {
                                      $recSet = $recurringModel->getByAccountId($parentId);
                                      $maxOccurrences = intval($recSet['max_occurrences'] ?? 0);
                                      $endDate = $recSet['end_date'] ?? null;
                                      $noEnd = empty($endDate) || $endDate === '0000-00-00';
                                      if ((!$recSet) || (($maxOccurrences <= 0) && $noEnd)) {
                                          $remainingInstallmentsDisplay = '<span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs">sem data de término</span>';
                                      } else {
                                          $stmtPendingChildren = $recurringModel->getConnection()->prepare("SELECT COUNT(*) FROM accounts WHERE recurring_parent_id = :pid AND status = 'pendente'");
                                          $stmtPendingChildren->execute([':pid' => $parentId]);
                                          $pendingChildren = intval($stmtPendingChildren->fetchColumn());

                                          $stmtParentStatus = $recurringModel->getConnection()->prepare("SELECT status FROM accounts WHERE id = :pid");
                                          $stmtParentStatus->execute([':pid' => $parentId]);
                                          $parentStatus = $stmtParentStatus->fetchColumn();
                                          $pendingParent = ($parentStatus === 'pendente') ? 1 : 0;

                                          $remainingInstallmentsDisplay = $pendingChildren;
                                      }
                                        } else {
                                            $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE user_id = :uid AND type = :type AND description = :desc");
                                            $stmtTotal->execute([':uid' => $userId, ':type' => $account['type'], ':desc' => $account['description']]);
                                            $total = intval($stmtTotal->fetchColumn());
                                            if ($total <= 1) {
                                                $remainingInstallmentsDisplay = '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-blue-100 text-blue-800">parcela única</span>';
                                            } else {
                                                $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE user_id = :uid AND type = :type AND description = :desc AND status = 'pendente'");
                                                $stmtPending->execute([':uid' => $userId, ':type' => $account['type'], ':desc' => $account['description']]);
                                                $remainingInstallmentsDisplay = intval($stmtPending->fetchColumn());
                                            }
                                        }
                                        $label = is_numeric($remainingInstallmentsDisplay) ? (string)$remainingInstallmentsDisplay : strip_tags((string)$remainingInstallmentsDisplay);
                                        echo '<span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs">' . htmlspecialchars($label) . '</span>';
                                    ?>
                                </td>
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
                                        <a href="javascript:void(0)" onclick="openLightbox('<?= htmlspecialchars($account['attachment']) ?>')" 
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
                                    <a href="?action=edit&id=<?= $account['id'] ?><?= (intval($account['is_recurring']) === 1 ? (empty($account['recurring_parent_id']) ? '&edit_context=parent' : '&edit_context=instance') : '') ?>" 
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
                        <?php endforeach; ?>
                        <?php else: ?>
                        <?php foreach ($accountsRevenues as $account): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <div class="flex items-center">
                                    <?php if (!empty($account['is_recurring']) || !empty($account['recurring_parent_id'])): ?>
                                    <i class="fas fa-sync-alt text-blue-500 mr-2" title="Conta recorrente"></i>
                                    <?php endif; ?>
                                    <div>
                                        <div class="text-sm font-medium text-gray-900 flex items-center">
                                            <?= htmlspecialchars($account['description']) ?>
                                            <?php if ($account['url']): ?>
                                            <a href="<?= htmlspecialchars($account['url']) ?>" target="_blank" class="ml-2 text-blue-600 hover:text-blue-800" title="Abrir URL associada">
                                                <i class="fas fa-external-link-alt"></i>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($account['name'])): ?>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars($account['name']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?= htmlspecialchars($account['category_name']) ?></td>
                            <td class="px-6 py-4 text-sm font-medium text-green-600">
                                <?= formatCurrency($account['amount']) ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?= formatDate($account['due_date']) ?></td>
                                <td class="px-6 py-4 text-sm text-gray-900">
                                    <?php
                                        $parentId = !empty($account['recurring_parent_id']) ? $account['recurring_parent_id'] : (intval($account['is_recurring']) === 1 ? $account['id'] : null);
                                        $remainingInstallmentsDisplay = '<span class="text-gray-400 text-xs">-</span>';
                                        if ($parentId) {
                                                $recSet = $recurringModel->getByAccountId($parentId);
                                                $maxOccurrences = intval($recSet['max_occurrences'] ?? 0);
                                                $endDate = $recSet['end_date'] ?? null;
                                                $noEnd = empty($endDate) || $endDate === '0000-00-00';
                                                if ((!$recSet) || (($maxOccurrences <= 0) && $noEnd)) {
                                                $remainingInstallmentsDisplay = '<span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs">sem data de término</span>';
                                            } else {
                                                $stmtPendingChildren = $recurringModel->getConnection()->prepare("SELECT COUNT(*) FROM accounts WHERE recurring_parent_id = :pid AND status = 'pendente'");
                                                $stmtPendingChildren->execute([':pid' => $parentId]);
                                                $pendingChildren = intval($stmtPendingChildren->fetchColumn());

                                                $stmtParentStatus = $recurringModel->getConnection()->prepare("SELECT status FROM accounts WHERE id = :pid");
                                                $stmtParentStatus->execute([':pid' => $parentId]);
                                                $parentStatus = $stmtParentStatus->fetchColumn();
                                                $pendingParent = ($parentStatus === 'pendente') ? 1 : 0;

                                                  $remainingInstallmentsDisplay = $pendingChildren;
                                            }
                                        } else {
                                            $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE user_id = :uid AND type = :type AND description = :desc");
                                            $stmtTotal->execute([':uid' => $userId, ':type' => $account['type'], ':desc' => $account['description']]);
                                            $total = intval($stmtTotal->fetchColumn());
                                            if ($total <= 1) {
                                                $remainingInstallmentsDisplay = '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-blue-100 text-blue-800">parcela única</span>';
                                            } else {
                                                $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE user_id = :uid AND type = :type AND description = :desc AND status = 'pendente'");
                                                $stmtPending->execute([':uid' => $userId, ':type' => $account['type'], ':desc' => $account['description']]);
                                                $remainingInstallmentsDisplay = intval($stmtPending->fetchColumn());
                                            }
                                        }
                                        $label = is_numeric($remainingInstallmentsDisplay) ? (string)$remainingInstallmentsDisplay : strip_tags((string)$remainingInstallmentsDisplay);
                                        echo '<span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs">' . htmlspecialchars($label) . '</span>';
                                    ?>
                                </td>
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
                                    <a href="javascript:void(0)" onclick="openLightbox('<?= htmlspecialchars($account['attachment']) ?>')" 
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
                                <a href="?action=edit&id=<?= $account['id'] ?><?= (intval($account['is_recurring']) === 1 ? (empty($account['recurring_parent_id']) ? '&edit_context=parent' : '&edit_context=instance') : '') ?>" 
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
                        <?php endif; ?>
                        
                        <!-- Linha de Totais (única linha) -->
                        <tr class="bg-green-50 border-t-2 border-green-200 font-semibold">
                            <td class="px-6 py-4 text-green-700" colspan="3">
                                <div class="flex items-center">
                                    <i class="fas fa-layer-group mr-2"></i>
                                    Totais
                                    <span class="ml-2 text-xs bg-green-100 text-green-600 px-2 py-1 rounded-full cursor-help" 
                                          title="Totais considerando todas as contas filtradas (categoria, data, etc.). O status é ignorado nos subtotais de Pendente e Recebida.">
                                        <i class="fas fa-info-circle"></i>
                                    </span>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-right" colspan="5">
                                <div class="flex items-center justify-end gap-6">
                                    <div class="flex items-center text-green-700">
                                        <i class="fas fa-hourglass-half mr-2"></i>
                                        <span class="mr-2">Pendente</span>
                                        <span class="font-bold">R$ <?= number_format($totalAmountRevenuesPending, 2, ',', '.') ?></span>
                                    </div>
                                    <div class="flex items-center text-green-700">
                                        <i class="fas fa-check-circle mr-2"></i>
                                        <span class="mr-2">Recebida</span>
                                        <span class="font-bold">R$ <?= number_format($totalAmountRevenuesReceived, 2, ',', '.') ?></span>
                                    </div>
                                    <div class="flex items-center text-green-700">
                                        <i class="fas fa-calculator mr-2"></i>
                                        <span class="mr-2">Total</span>
                                        <span class="font-bold text-lg">R$ <?= number_format($totalAmountRevenues, 2, ',', '.') ?></span>
                                        <?php if ($totalRevenues > $itemsPerPageRevenues): ?>
                                        <span class="ml-2 text-xs bg-green-100 text-green-600 px-2 py-1 rounded-full cursor-help" 
                                              title="Este total inclui todas as <?= $totalRevenues ?> contas filtradas, não apenas as <?= min($itemsPerPageRevenues, count($accountsRevenues)) ?> exibidas nesta página">
                                            <i class="fas fa-info-circle"></i>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <?php endif; ?>
<?php endif; ?>
<?php if ($action != 'list'): ?>
        <!-- Formulário de Adicionar/Editar -->
        <div class="bg-white rounded-lg shadow">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-900">
                    <i class="fas <?= $action == 'add' ? 'fa-plus' : 'fa-edit' ?> mr-2"></i>
                    <?= $action == 'add' ? 'Nova Conta' : 'Editar Conta' ?>
                </h2>
            </div>

            <form method="POST" enctype="multipart/form-data" class="p-6 space-y-6" id="account-form" onsubmit="return handleFormSubmit(event)">
                <input type="hidden" name="action" value="<?= $action ?>">
                <?php if ($action == 'edit'): ?>
                <input type="hidden" name="id" value="<?= $accountId ?>">
                <?php endif; ?>
                <input type="hidden" name="edit_context" value="<?= htmlspecialchars($editContext ?? ($_GET['edit_context'] ?? '')) ?>">
                <input type="hidden" id="propagate_url" name="propagate_url" value="0">
                <input type="hidden" id="propagate_description" name="propagate_description" value="0">
                <input type="hidden" id="propagate_value" name="propagate_value" value="0">
                <input type="hidden" id="realign_future_due_dates" name="realign_future_due_dates" value="0">
                <input type="hidden" id="apply_shared_to_parent" name="apply_shared_to_parent" value="0">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Descrição -->
                    <div class="md:col-span-1">
                        <label for="description" class="block text-sm font-medium text-gray-700 mb-2">
                            Descrição *
                        </label>
                        <input type="text" id="description" name="description" required
                               value="<?= htmlspecialchars($account['description'] ?? '') ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    </div>

                    <!-- Nome (Pessoa/Empresa) -->
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 mb-2">
                            Nome (pessoa/empresa)
                        </label>
                        <input type="text" id="name" name="name"
                               value="<?= htmlspecialchars($account['name'] ?? '') ?>"
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
                                    <a href="javascript:void(0)" onclick="openLightbox('<?= htmlspecialchars($account['attachment']) ?>')" 
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

                        <?php 
                          // Exibir apenas ao editar o pai da recorrência
                          if ($action === 'edit' && (empty($account['recurring_parent_id']) && intval($account['is_recurring']) === 1)) {
                              $nextGen = $recurringSetting['next_generation_date'] ?? null;
                              $stmtGen = $pdo->prepare("SELECT COUNT(*) AS total FROM accounts WHERE recurring_parent_id = :pid AND user_id = :uid");
                              $stmtGen->execute([':pid' => $account['id'], ':uid' => $userId]);
                              $generatedCount = (int)($stmtGen->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
                              $nextGenFmt = $nextGen ? date('d/m/Y', strtotime($nextGen)) : 'Agora';
                          ?>
                          <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-md text-sm text-blue-800">
                            <div class="flex items-center mb-1">
                              <i class="fas fa-calendar-plus mr-2"></i>
                              <span><strong>Próxima geração:</strong> <?= $nextGenFmt ?></span>
                            </div>
                            <div class="flex items-center">
                              <i class="fas fa-layer-group mr-2"></i>
                              <span><strong>Geradas:</strong> <?= $generatedCount ?></span>
                            </div>
                          </div>
                        <?php } ?>

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
    <!-- Modal Tailwind para edição de recorrentes -->
    <div id="recurringEditModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 items-center justify-center">
      <div class="bg-white rounded-lg shadow-lg w-full max-w-xl p-6">
        <h3 class="text-lg font-semibold mb-4">Confirmar alterações em recorrência</h3>
        <div id="modal-parent-section" class="space-y-3 hidden">
          <p class="text-sm text-gray-700">Você está editando a conta recorrente pai. Escolha como propagar:</p>
          <label class="flex items-center space-x-2">
            <input type="checkbox" id="modal_propagate_url" class="h-4 w-4" checked>
            <span class="text-sm">Propagar URL para todas as instâncias</span>
          </label>
          <label class="flex items-center space-x-2">
            <input type="checkbox" id="modal_propagate_description" class="h-4 w-4" checked>
            <span class="text-sm">Propagar descrição para todas as instâncias</span>
          </label>
          <label class="flex items-center space-x-2">
            <input type="checkbox" id="modal_propagate_value" class="h-4 w-4">
            <span class="text-sm">Propagar valor para instâncias futuras pendentes</span>
          </label>
          <label class="flex items-center space-x-2">
            <input type="checkbox" id="modal_realign_future_due_dates" class="h-4 w-4" checked>
            <span class="text-sm">Re-alinhar vencimentos das instâncias futuras</span>
          </label>
        </div>
        <div id="modal-instance-section" class="space-y-3 hidden">
          <p class="text-sm text-gray-700">Você está editando uma instância recorrente. Escolha:</p>
          <label class="flex items-center space-x-2">
            <input type="checkbox" id="modal_apply_shared_to_parent" class="h-4 w-4">
            <span class="text-sm">Aplicar URL/descrição/valor ao pai</span>
          </label>
        </div>
        <div class="mt-6 flex justify-end space-x-3">
          <button type="button" class="px-4 py-2 bg-gray-200 rounded" onclick="closeRecurringModal()">Cancelar</button>
          <button type="button" class="px-4 py-2 bg-primary text-white rounded" onclick="confirmRecurringModal()">Confirmar</button>
        </div>
      </div>
    </div>

    <script>
      // Contexto definido no servidor
      window.EDIT_CONTEXT = '<?= htmlspecialchars($editContext ?? ($_GET['edit_context'] ?? '')) ?>';
      function handleFormSubmit(e) {
        const form = document.getElementById('account-form');
        const isEdit = '<?= $action ?>' === 'edit';
        const hasContext = (window.EDIT_CONTEXT === 'parent' || window.EDIT_CONTEXT === 'instance');
        if (isEdit && hasContext) {
          e.preventDefault();
          openRecurringModal();
          return false;
        }
        return true;
      }
      function openRecurringModal() {
        const modal = document.getElementById('recurringEditModal');
        const parentSec = document.getElementById('modal-parent-section');
        const instanceSec = document.getElementById('modal-instance-section');
        if (window.EDIT_CONTEXT === 'parent') {
          parentSec.classList.remove('hidden');
          instanceSec.classList.add('hidden');
        } else {
          instanceSec.classList.remove('hidden');
          parentSec.classList.add('hidden');
        }
        modal.classList.remove('hidden');
        modal.classList.add('flex');
      }
      function closeRecurringModal() {
        const modal = document.getElementById('recurringEditModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
      }
      function confirmRecurringModal() {
        // Mapear checks para hidden inputs
        const toInt = (b) => b ? '1' : '0';
        const ctx = window.EDIT_CONTEXT === 'parent' ? 'parent' : 'instance';
        document.getElementById('propagate_url').value = toInt(document.getElementById('modal_propagate_url')?.checked);
        document.getElementById('propagate_description').value = toInt(document.getElementById('modal_propagate_description')?.checked);
        document.getElementById('propagate_value').value = toInt(document.getElementById('modal_propagate_value')?.checked);
        document.getElementById('realign_future_due_dates').value = toInt(document.getElementById('modal_realign_future_due_dates')?.checked);
        document.getElementById('apply_shared_to_parent').value = toInt(document.getElementById('modal_apply_shared_to_parent')?.checked);
        closeRecurringModal();
        document.getElementById('account-form').submit();
      }
    </script>

    </main>

    <!-- Lightbox Modal -->
    <div id="lightbox" class="fixed inset-0 bg-black bg-opacity-75 hidden z-50 flex items-center justify-center">
        <div class="relative max-w-4xl max-h-full w-full h-full flex items-center justify-center p-4">
            <!-- Botão de fechar -->
            <button onclick="closeLightbox()" class="absolute top-4 right-4 text-white hover:text-gray-300 z-10">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
            
            <!-- Conteúdo do lightbox -->
            <div id="lightbox-content" class="w-full h-full flex items-center justify-center">
                <!-- Conteúdo será inserido dinamicamente -->
            </div>
        </div>
    </div>

    <style>
        #lightbox {
            backdrop-filter: blur(4px);
        }
        
        #lightbox iframe {
            max-width: 90vw;
            max-height: 90vh;
            width: 100%;
            height: 100%;
            border: none;
            border-radius: 8px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        
        #lightbox img {
            max-width: 90vw;
            max-height: 90vh;
            object-fit: contain;
            border-radius: 8px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        
        .lightbox-loading {
            color: white;
            font-size: 18px;
            text-align: center;
        }
    </style>

    <script>
        // Funções do Lightbox
        function openLightbox(filePath) {
            const lightbox = document.getElementById('lightbox');
            const content = document.getElementById('lightbox-content');
            
            // Mostrar loading
            content.innerHTML = '<div class="lightbox-loading">Carregando...</div>';
            lightbox.classList.remove('hidden');
            
            // Detectar tipo de arquivo
            const fileExtension = filePath.split('.').pop().toLowerCase();
            const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg'];
            const pdfExtensions = ['pdf'];
            
            if (imageExtensions.includes(fileExtension)) {
                // Exibir imagem
                content.innerHTML = `<img src="${filePath}" alt="Comprovante" onload="this.style.opacity=1" style="opacity:0; transition: opacity 0.3s;">`;
            } else if (pdfExtensions.includes(fileExtension)) {
                // Exibir PDF em iframe
                content.innerHTML = `<iframe src="${filePath}" onload="this.style.opacity=1" style="opacity:0; transition: opacity 0.3s;"></iframe>`;
            } else {
                // Outros tipos de arquivo - tentar iframe
                content.innerHTML = `<iframe src="${filePath}" onload="this.style.opacity=1" style="opacity:0; transition: opacity 0.3s;"></iframe>`;
            }
        }
        
        function closeLightbox() {
            const lightbox = document.getElementById('lightbox');
            lightbox.classList.add('hidden');
            
            // Limpar conteúdo após animação
            setTimeout(() => {
                document.getElementById('lightbox-content').innerHTML = '';
            }, 300);
        }
        
        // Fechar lightbox ao clicar fora do conteúdo
        document.addEventListener('DOMContentLoaded', function() {
            const lightbox = document.getElementById('lightbox');
            lightbox.addEventListener('click', function(e) {
                if (e.target === lightbox) {
                    closeLightbox();
                }
            });
            
            // Fechar com ESC
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && !lightbox.classList.contains('hidden')) {
                    closeLightbox();
                }
            });
        });

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

        function toggleDateFilters() {
            const filterType = document.getElementById('dateFilterType');
            const monthYearFilter = document.getElementById('monthYearFilter');
            const yearFilter = document.getElementById('yearFilter');
            const customFilter = document.getElementById('customFilter');
            
            // Esconder todos os filtros primeiro
            monthYearFilter.style.display = 'none';
            yearFilter.style.display = 'none';
            customFilter.style.display = 'none';
            
            // Mostrar o filtro selecionado
            switch (filterType.value) {
                case 'month_year':
                    monthYearFilter.style.display = 'grid';
                    break;
                case 'year':
                    yearFilter.style.display = 'block';
                    break;
                case 'custom':
                    customFilter.style.display = 'grid';
                    break;
            }
        }

        function updatePagination(type) {
            const select = document.getElementById('items_per_page_' + type);
            const itemsPerPage = select.value;
            
            // Obter parâmetros atuais da URL
            const urlParams = new URLSearchParams(window.location.search);
            
            // Atualizar parâmetros
            urlParams.set('items_per_page_' + type, itemsPerPage);
            urlParams.set('page_' + type, '1'); // Resetar para primeira página
            
            // Redirecionar com novos parâmetros
            window.location.href = '?' + urlParams.toString();
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateCategories();
            if (document.getElementById('is_recurring')) {
                toggleRecurringOptions();
                toggleCustomInterval();
            }
            if (document.getElementById('dateFilterType')) {
                toggleDateFilters();
            }
        });
    </script>
</body>
</html>
