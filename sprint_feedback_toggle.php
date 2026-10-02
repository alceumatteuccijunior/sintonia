<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'teacher'])) {
    header("Location: index.php");
    exit;
}
require_once 'config.php';
$pdo = getPDOConnection(true);

$sprint_id = $_GET['id'] ?? null;
if (!$sprint_id) {
    header("Location: dashboard.php");
    exit;
}

// Verifica permissão
$stmt = $pdo->prepare("SELECT id, feedback_released FROM sprints WHERE id = ? AND teacher_id = ?");
$stmt->execute([$sprint_id, $_SESSION['user_id']]);
$sprint = $stmt->fetch();

if ($sprint) {
    $new_status = $sprint['feedback_released'] ? 0 : 1;
    $update = $pdo->prepare("UPDATE sprints SET feedback_released = ? WHERE id = ?");
    $update->execute([$new_status, $sprint_id]);
}

header("Location: sprint_report.php?id=" . $sprint_id);
exit;
