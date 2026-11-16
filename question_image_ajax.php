<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'teacher'])) {
    echo json_encode(['success' => false, 'error' => 'Acesso negado.']);
    exit;
}

require_once 'config.php';
$pdo = getPDOConnection(true);

// Garante que a coluna image_url exista em question_options
try {
    $stmtCol = $pdo->query("SHOW COLUMNS FROM question_options LIKE 'image_url'");
    if ($stmtCol && !$stmtCol->fetch()) {
        $pdo->exec("ALTER TABLE question_options ADD COLUMN image_url VARCHAR(500) NULL DEFAULT NULL AFTER text");
    }
} catch (Exception $e) {}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$question_id = isset($_POST['question_id']) ? (int)$_POST['question_id'] : (isset($_GET['question_id']) ? (int)$_GET['question_id'] : 0);
$option_id   = isset($_POST['option_id']) ? (int)$_POST['option_id'] : (isset($_GET['option_id']) ? (int)$_GET['option_id'] : 0);

$uploadDir = __DIR__ . '/uploads/questions/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

/**
 * Valida e move um arquivo de imagem enviado em $_FILES['image']
 */
function validateAndMoveUploadedImage($prefix, $uploadDir) {
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $errorCode = $_FILES['image']['error'] ?? 'nenhum arquivo';
        return ['success' => false, 'error' => 'Nenhum arquivo enviado ou erro no upload (' . $errorCode . ').'];
    }

    $file = $_FILES['image'];

    if ($file['size'] > 10 * 1024 * 1024) {
        return ['success' => false, 'error' => 'A imagem excede o tamanho máximo permitido de 10MB.'];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
    if (!in_array($ext, $allowedExts)) {
        return ['success' => false, 'error' => 'Formato não suportado. Use JPG, PNG, WEBP, GIF ou SVG.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'];
    if (!in_array($mimeType, $allowedMimes)) {
        return ['success' => false, 'error' => 'Arquivo inválido. O conteúdo não é uma imagem válida.'];
    }

    $newFileName = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destination = $uploadDir . $newFileName;
    $relativeUrl = 'uploads/questions/' . $newFileName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => false, 'error' => 'Falha ao mover a imagem para a pasta do servidor.'];
    }

    return ['success' => true, 'image_url' => $relativeUrl];
}

// =============================================================
// 1. AÇÃO: UPLOAD DE IMAGEM PARA UMA ALTERNATIVA (upload_option)
// =============================================================
if ($action === 'upload_option') {
    if (!$option_id) {
        echo json_encode(['success' => false, 'error' => 'ID da alternativa não fornecido.']);
        exit;
    }

    $stmtOpt = $pdo->prepare("SELECT * FROM question_options WHERE id = ?");
    $stmtOpt->execute([$option_id]);
    $optRow = $stmtOpt->fetch();

    if (!$optRow) {
        echo json_encode(['success' => false, 'error' => 'Alternativa não encontrada.']);
        exit;
    }

    $uploadResult = validateAndMoveUploadedImage('opt_' . $option_id, $uploadDir);
    if (!$uploadResult['success']) {
        echo json_encode($uploadResult);
        exit;
    }

    // Remove imagem anterior da alternativa se existir
    if (!empty($optRow['image_url']) && str_starts_with($optRow['image_url'], 'uploads/questions/')) {
        $oldPath = __DIR__ . '/' . $optRow['image_url'];
        if (file_exists($oldPath) && is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    $stmtUpd = $pdo->prepare("UPDATE question_options SET image_url = ? WHERE id = ?");
    $stmtUpd->execute([$uploadResult['image_url'], $option_id]);

    echo json_encode([
        'success'     => true,
        'message'     => 'Imagem vinculada à alternativa com sucesso!',
        'image_url'   => $uploadResult['image_url'],
        'option_id'   => $option_id,
        'question_id' => (int)$optRow['question_id']
    ]);
    exit;
}

// =============================================================
// 2. AÇÃO: REMOVER IMAGEM DE UMA ALTERNATIVA (remove_option)
// =============================================================
if ($action === 'remove_option') {
    if (!$option_id) {
        echo json_encode(['success' => false, 'error' => 'ID da alternativa não fornecido.']);
        exit;
    }

    $stmtOpt = $pdo->prepare("SELECT * FROM question_options WHERE id = ?");
    $stmtOpt->execute([$option_id]);
    $optRow = $stmtOpt->fetch();

    if (!$optRow) {
        echo json_encode(['success' => false, 'error' => 'Alternativa não encontrada.']);
        exit;
    }

    if (!empty($optRow['image_url']) && str_starts_with($optRow['image_url'], 'uploads/questions/')) {
        $oldPath = __DIR__ . '/' . $optRow['image_url'];
        if (file_exists($oldPath) && is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    $stmtUpd = $pdo->prepare("UPDATE question_options SET image_url = NULL WHERE id = ?");
    $stmtUpd->execute([$option_id]);

    echo json_encode([
        'success'     => true,
        'message'     => 'Imagem da alternativa removida com sucesso!',
        'option_id'   => $option_id,
        'question_id' => (int)$optRow['question_id']
    ]);
    exit;
}

// =============================================================
// 3. AÇÃO: UPLOAD DE IMAGEM AVULSA (PRÉ-SALVAMENTO / REVISÃO)
// =============================================================
if ($action === 'upload_temp') {
    $uploadResult = validateAndMoveUploadedImage('opt_tmp', $uploadDir);
    echo json_encode($uploadResult);
    exit;
}

// =============================================================
// 4. AÇÕES DE IMAGEM DO ENUNCIADO DA QUESTÃO (upload, remove, get)
// =============================================================
if (!$question_id) {
    echo json_encode(['success' => false, 'error' => 'ID da questão não fornecido.']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM questions WHERE id = ?");
$stmt->execute([$question_id]);
$question = $stmt->fetch();

if (!$question) {
    echo json_encode(['success' => false, 'error' => 'Questão não encontrada.']);
    exit;
}

if ($action === 'upload') {
    $uploadResult = validateAndMoveUploadedImage('q_' . $question_id, $uploadDir);
    if (!$uploadResult['success']) {
        echo json_encode($uploadResult);
        exit;
    }

    if (!empty($question['image_url']) && str_starts_with($question['image_url'], 'uploads/questions/')) {
        $oldPath = __DIR__ . '/' . $question['image_url'];
        if (file_exists($oldPath) && is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    $stmtUpdate = $pdo->prepare("UPDATE questions SET image_url = ? WHERE id = ?");
    $stmtUpdate->execute([$uploadResult['image_url'], $question_id]);

    echo json_encode([
        'success'     => true,
        'message'     => 'Imagem vinculada com sucesso à questão!',
        'image_url'   => $uploadResult['image_url'],
        'question_id' => $question_id
    ]);
    exit;
}

if ($action === 'remove') {
    if (!empty($question['image_url']) && str_starts_with($question['image_url'], 'uploads/questions/')) {
        $oldPath = __DIR__ . '/' . $question['image_url'];
        if (file_exists($oldPath) && is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    $stmtUpdate = $pdo->prepare("UPDATE questions SET image_url = NULL WHERE id = ?");
    $stmtUpdate->execute([$question_id]);

    echo json_encode([
        'success'     => true,
        'message'     => 'Imagem removida com sucesso!',
        'question_id' => $question_id
    ]);
    exit;
}

if ($action === 'get') {
    echo json_encode([
        'success'  => true,
        'question' => [
            'id'         => $question['id'],
            'command'    => $question['command'],
            'context'    => $question['context'],
            'capacity'   => $question['capacity'],
            'difficulty' => $question['difficulty'],
            'image_url'  => $question['image_url']
        ]
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Ação não reconhecida.']);
exit;
