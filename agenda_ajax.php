<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'teacher'])) {
    echo json_encode(['success' => false, 'error' => 'Acesso negado.']);
    exit;
}

require_once 'config.php';
$pdo = getPDOConnection(true);

$user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['user_role'] === 'admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método inválido.']);
    exit;
}

$action = $_POST['action'] ?? '';
$sprint_id = isset($_POST['sprint_id']) ? (int)$_POST['sprint_id'] : 0;

if (!$sprint_id) {
    echo json_encode(['success' => false, 'error' => 'Sprint não identificada.']);
    exit;
}

// Verifica permissão na sprint
if ($is_admin) {
    $stmtCheck = $pdo->prepare("SELECT * FROM sprints WHERE id = ?");
    $stmtCheck->execute([$sprint_id]);
} else {
    $stmtCheck = $pdo->prepare("SELECT * FROM sprints WHERE id = ? AND teacher_id = ?");
    $stmtCheck->execute([$sprint_id, $user_id]);
}
$sprint = $stmtCheck->fetch();

if (!$sprint) {
    echo json_encode(['success' => false, 'error' => 'Sprint não encontrada ou sem permissão.']);
    exit;
}

if ($action === 'reschedule') {
    $start_time = !empty($_POST['start_time']) ? $_POST['start_time'] : null;
    $end_time = !empty($_POST['end_time']) ? $_POST['end_time'] : null;

    $status = $sprint['status'];
    if ($start_time) {
        $startDt = new DateTime($start_time);
        $now = new DateTime();
        if ($startDt > $now) {
            $status = 'scheduled';
        } else {
            if ($status === 'scheduled') {
                $status = 'active';
            }
        }
    } else {
        if ($status === 'scheduled') {
            $status = 'active';
        }
    }

    try {
        $stmtUpdate = $pdo->prepare("UPDATE sprints SET start_time = ?, end_time = ?, status = ? WHERE id = ?");
        $stmtUpdate->execute([$start_time, $end_time, $status, $sprint_id]);

        echo json_encode([
            'success' => true,
            'message' => 'Agendamento atualizado com sucesso!',
            'status' => $status,
            'start_time' => $start_time,
            'end_time' => $end_time
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Erro ao atualizar: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Ação não reconhecida.']);
exit;
