<?php
session_start();
date_default_timezone_set('America/Sao_Paulo');
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

// Verifica permissão na sprint (apenas sprints/diagnósticas oficiais)
if ($is_admin) {
    $stmtCheck = $pdo->prepare("SELECT * FROM sprints WHERE id = ? AND teacher_id IS NOT NULL AND name NOT LIKE 'DUELO|%' AND name NOT LIKE 'Missão de Treinamento%'");
    $stmtCheck->execute([$sprint_id]);
} else {
    $stmtCheck = $pdo->prepare("SELECT * FROM sprints WHERE id = ? AND teacher_id = ? AND name NOT LIKE 'DUELO|%' AND name NOT LIKE 'Missão de Treinamento%'");
    $stmtCheck->execute([$sprint_id, $user_id]);
}
$sprint = $stmtCheck->fetch();

if (!$sprint) {
    echo json_encode(['success' => false, 'error' => 'Sprint não encontrada ou sem permissão.']);
    exit;
}

if ($action === 'reschedule') {
    $start_time = !empty($_POST['start_time']) ? date('Y-m-d H:i:s', strtotime($_POST['start_time'])) : null;
    $end_time = !empty($_POST['end_time']) ? date('Y-m-d H:i:s', strtotime($_POST['end_time'])) : null;

    if ($start_time && $end_time && strtotime($end_time) <= strtotime($start_time)) {
        $end_time = null;
    }

    $status = $sprint['status'];
    if ($start_time) {
        if (strtotime($start_time) > time()) {
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
        try {
            $pdo->exec("ALTER TABLE sprints MODIFY COLUMN status ENUM('draft', 'active', 'completed', 'scheduled') DEFAULT 'draft'");
        } catch (Exception $ex) {}
        $stmtUpdate = $pdo->prepare("UPDATE sprints SET start_time = ?, end_time = ?, status = ? WHERE id = ?");
        $stmtUpdate->execute([$start_time, $end_time, $status, $sprint_id]);

        // Envia e-mail atualizado ao professor vinculado com o novo link do Google Agenda e orientações anticola
        $emailSent = false;
        try {
            require_once 'includes/mailer.php';
            $emailSent = send_teacher_sprint_schedule_email($pdo, (int)$sprint_id, true);
        } catch (Exception $mailEx) {}

        echo json_encode([
            'success' => true,
            'message' => 'Agendamento atualizado e e-mail enviado ao professor!',
            'email_sent' => $emailSent,
            'status' => $status,
            'start_time' => $start_time,
            'end_time' => $end_time
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Erro ao atualizar: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'send_teacher_email') {
    try {
        require_once 'includes/mailer.php';
        $sent = send_teacher_sprint_schedule_email($pdo, (int)$sprint_id, false);
        echo json_encode([
            'success' => true,
            'email_sent' => $sent,
            'message' => 'E-mail com link do Google Agenda e instruções (incluindo trava anticola) enviado ao professor vinculado!'
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Erro ao enviar e-mail: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Ação não reconhecida.']);
exit;
