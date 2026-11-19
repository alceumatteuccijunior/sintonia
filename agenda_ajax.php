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

// Verifica permissão na sprint (apenas sprints/diagnósticas oficiais) e carrega dados completos para notificação
$sqlCheck = "
    SELECT s.*,
           c.name AS class_name,
           co.name AS course_name,
           u.name AS unit_name,
           t.name AS teacher_name,
           t.email AS teacher_email
    FROM sprints s
    JOIN classes c ON s.class_id = c.id
    LEFT JOIN courses co ON c.course_id = co.id
    LEFT JOIN units u ON c.unit_id = u.id
    LEFT JOIN users t ON s.teacher_id = t.id
    WHERE s.id = ?
      AND s.teacher_id IS NOT NULL
      AND s.name NOT LIKE 'DUELO|%'
      AND s.name NOT LIKE 'Missão de Treinamento%'
";
if ($is_admin) {
    $stmtCheck = $pdo->prepare($sqlCheck);
    $stmtCheck->execute([$sprint_id]);
} else {
    $stmtCheck = $pdo->prepare($sqlCheck . " AND s.teacher_id = ?");
    $stmtCheck->execute([$sprint_id, $user_id]);
}
$sprint = $stmtCheck->fetch(PDO::FETCH_ASSOC);

if (!$sprint) {
    echo json_encode(['success' => false, 'error' => 'Sprint não encontrada ou sem permissão.']);
    exit;
}

if ($action === 'reschedule' || $action === 'update_sprint') {
    $oldTeacherId    = (int)$sprint['teacher_id'];
    $oldTeacherName  = (string)($sprint['teacher_name'] ?? '');
    $oldTeacherEmail = (string)($sprint['teacher_email'] ?? '');

    $newName      = !empty(trim($_POST['name'] ?? '')) ? trim($_POST['name']) : $sprint['name'];
    $newTeacherId = !empty($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : $oldTeacherId;
    $newClassId   = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : (int)$sprint['class_id'];
    $newTimeLimit = !empty($_POST['time_limit_minutes']) ? max(1, (int)$_POST['time_limit_minutes']) : (int)$sprint['time_limit_minutes'];
    $newTimeMin   = (isset($_POST['time_min_minutes']) && $_POST['time_min_minutes'] !== '')
        ? ((int)$_POST['time_min_minutes'] > 0 ? (int)$_POST['time_min_minutes'] : null)
        : $sprint['time_min_minutes'];

    $start_time = !empty($_POST['start_time']) ? date('Y-m-d H:i:s', strtotime($_POST['start_time'])) : null;
    $end_time   = !empty($_POST['end_time']) ? date('Y-m-d H:i:s', strtotime($_POST['end_time'])) : null;

    if ($start_time && $end_time && strtotime($end_time) <= strtotime($start_time)) {
        $end_time = null;
    }

    $statusOverride = trim($_POST['status_override'] ?? '');
    if ($statusOverride === 'completed') {
        $status = 'completed';
    } else {
        $status = ($sprint['status'] === 'completed' && $statusOverride === 'auto') ? 'active' : $sprint['status'];
        if ($start_time) {
            if (strtotime($start_time) > time()) {
                $status = 'scheduled';
            } elseif ($status === 'scheduled' || $status === 'completed') {
                $status = 'active';
            }
        } else {
            if ($status === 'scheduled' || ($status === 'completed' && $statusOverride === 'auto')) {
                $status = 'active';
            }
        }
    }

    try {
        try {
            $pdo->exec("ALTER TABLE sprints MODIFY COLUMN status ENUM('draft', 'active', 'completed', 'scheduled') DEFAULT 'draft'");
        } catch (Exception $ex) {}

        $stmtUpdate = $pdo->prepare("
            UPDATE sprints
            SET name = ?, teacher_id = ?, class_id = ?, time_limit_minutes = ?, time_min_minutes = ?, start_time = ?, end_time = ?, status = ?
            WHERE id = ?
        ");
        $stmtUpdate->execute([
            $newName,
            $newTeacherId,
            $newClassId,
            $newTimeLimit,
            $newTimeMin,
            $start_time,
            $end_time,
            $status,
            $sprint_id
        ]);

        // Dispara notificações por e-mail (para novo professor e, se houve troca de docente, avisa o professor anterior também)
        $emailSent = false;
        $changedByName = $_SESSION['user_name'] ?? 'Gestão / Coordenação';
        try {
            require_once 'includes/mailer.php';
            if ($newTeacherId !== $oldTeacherId) {
                $stmtNewT = $pdo->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
                $stmtNewT->execute([$newTeacherId]);
                $newTeacherName = (string)($stmtNewT->fetchColumn() ?: 'Novo Docente');

                // 1. Avisa o professor anterior sobre a transferência de responsabilidade
                if ($oldTeacherEmail !== '') {
                    send_teacher_sprint_reassigned_out_email($oldTeacherName, $oldTeacherEmail, $newTeacherName, $sprint, $changedByName);
                }
                // 2. Envia o e-mail completo de atribuição + link do Google Agenda + guia anticola ao novo professor
                $emailSent = send_teacher_sprint_schedule_email($pdo, (int)$sprint_id, false);
                $msgSuccess = "Diagnóstica atualizada! E-mail enviado ao novo professor ({$newTeacherName}) e aviso de transferência enviado ao professor anterior.";
            } else {
                // Mesmo professor: envia e-mail com os dados atualizados / reagendamento
                $emailSent = send_teacher_sprint_schedule_email($pdo, (int)$sprint_id, true);
                $msgSuccess = 'Alterações salvas e e-mail de atualização enviado ao professor responsável!';
            }
        } catch (Exception $mailEx) {
            $msgSuccess = 'Alterações salvas com sucesso!';
        }

        echo json_encode([
            'success'    => true,
            'message'    => $msgSuccess,
            'email_sent' => $emailSent,
            'status'     => $status,
            'start_time' => $start_time,
            'end_time'   => $end_time
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Erro ao atualizar: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete_sprint') {
    try {
        $pdo->beginTransaction();

        // Remove respostas, tentativas, vínculos de questões e a sprint
        $pdo->prepare("DELETE FROM student_answers WHERE sprint_id = ?")->execute([$sprint_id]);
        $pdo->prepare("DELETE FROM sprint_attempts WHERE sprint_id = ?")->execute([$sprint_id]);
        $pdo->prepare("DELETE FROM sprint_questions WHERE sprint_id = ?")->execute([$sprint_id]);
        $pdo->prepare("DELETE FROM sprints WHERE id = ?")->execute([$sprint_id]);

        $pdo->commit();

        // Dispara e-mail de cancelamento ao professor responsável
        $emailSent = false;
        try {
            require_once 'includes/mailer.php';
            $cancelledBy = $_SESSION['user_name'] ?? 'Gestão / Coordenação';
            $emailSent = send_teacher_sprint_cancelled_email($sprint, $cancelledBy);
        } catch (Exception $mailEx) {}

        echo json_encode([
            'success'    => true,
            'email_sent' => $emailSent,
            'message'    => 'Diagnóstica excluída com sucesso! E-mail de aviso de cancelamento enviado ao professor responsável.'
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'error' => 'Erro ao excluir diagnóstica: ' . $e->getMessage()]);
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
