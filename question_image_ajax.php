<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'teacher'])) {
    echo json_encode(['success' => false, 'error' => 'Acesso negado.']);
    exit;
}

require_once 'config.php';
$pdo = getPDOConnection(true);

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$question_id = isset($_POST['question_id']) ? (int)$_POST['question_id'] : (isset($_GET['question_id']) ? (int)$_GET['question_id'] : 0);

if (!$question_id) {
    echo json_encode(['success' => false, 'error' => 'ID da questão não fornecido.']);
    exit;
}

// 1. Busca a questão
$stmt = $pdo->prepare("SELECT * FROM questions WHERE id = ?");
$stmt->execute([$question_id]);
$question = $stmt->fetch();

if (!$question) {
    echo json_encode(['success' => false, 'error' => 'Questão não encontrada.']);
    exit;
}

$uploadDir = __DIR__ . '/uploads/questions/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

// =============================================================
// AÇÃO: UPLOAD DE IMAGEM
// =============================================================
if ($action === 'upload') {
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $errorCode = $_FILES['image']['error'] ?? 'nenhum arquivo';
        echo json_encode(['success' => false, 'error' => 'Nenhum arquivo enviado ou erro no upload (' . $errorCode . ').']);
        exit;
    }

    $file = $_FILES['image'];

    // Validação de Tamanho (Máx 10MB)
    if ($file['size'] > 10 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'A imagem excede o tamanho máximo permitido de 10MB.']);
        exit;
    }

    // Validação de Extensão e MIME
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
    
    if (!in_array($ext, $allowedExts)) {
        echo json_encode(['success' => false, 'error' => 'Formato não suportado. Use JPG, PNG, WEBP, GIF ou SVG.']);
        exit;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'];
    if (!in_array($mimeType, $allowedMimes)) {
        echo json_encode(['success' => false, 'error' => 'Arquivo inválido. O conteúdo não é uma imagem válida.']);
        exit;
    }

    // Gera nome único seguro
    $newFileName = 'q_' . $question_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destination = $uploadDir . $newFileName;
    $relativeUrl = 'uploads/questions/' . $newFileName;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        // Remove imagem anterior se existir e for local
        if (!empty($question['image_url']) && str_starts_with($question['image_url'], 'uploads/questions/')) {
            $oldPath = __DIR__ . '/' . $question['image_url'];
            if (file_exists($oldPath) && is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        // Atualiza no banco
        $stmtUpdate = $pdo->prepare("UPDATE questions SET image_url = ? WHERE id = ?");
        $stmtUpdate->execute([$relativeUrl, $question_id]);

        echo json_encode([
            'success' => true,
            'message' => 'Imagem vinculada com sucesso à questão!',
            'image_url' => $relativeUrl,
            'question_id' => $question_id
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Falha ao mover a imagem para a pasta do servidor.']);
    }
    exit;
}

// =============================================================
// AÇÃO: REMOVER IMAGEM
// =============================================================
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
        'success' => true,
        'message' => 'Imagem removida com sucesso!',
        'question_id' => $question_id
    ]);
    exit;
}

// =============================================================
// AÇÃO: OBTER DETALHES DA QUESTÃO COM IMAGEM
// =============================================================
if ($action === 'get') {
    echo json_encode([
        'success' => true,
        'question' => [
            'id' => $question['id'],
            'command' => $question['command'],
            'context' => $question['context'],
            'capacity' => $question['capacity'],
            'difficulty' => $question['difficulty'],
            'image_url' => $question['image_url']
        ]
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Ação não reconhecida.']);
exit;
