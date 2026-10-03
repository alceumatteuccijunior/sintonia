<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: index.php");
    exit;
}
require_once 'config.php';
$pdo = getPDOConnection(true);

$student_id = $_SESSION['user_id'];
$target_id = $_POST['target_id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$target_id || $target_id == $student_id) {
    header("Location: student_arena.php");
    exit;
}

// 1. Validar se o alvo é da mesma turma
$stmtClass = $pdo->prepare("SELECT class_id FROM class_students WHERE student_id = ? LIMIT 1");
$stmtClass->execute([$student_id]);
$my_class = $stmtClass->fetchColumn();

$stmtClassTarget = $pdo->prepare("SELECT class_id FROM class_students WHERE student_id = ? LIMIT 1");
$stmtClassTarget->execute([$target_id]);
$target_class = $stmtClassTarget->fetchColumn();

if (!$my_class || $my_class !== $target_class) {
    header("Location: student_arena.php");
    exit;
}

// 2. Verificar se JÁ EXISTE um duelo pendente entre os dois
$stmtCheck = $pdo->prepare("
    SELECT id FROM sprints 
    WHERE (name LIKE ? OR name LIKE ?) 
      AND id NOT IN (
          -- Considera pendente se alguém ainda não completou
          SELECT sprint_id FROM sprint_attempts WHERE completed_at IS NOT NULL GROUP BY sprint_id HAVING COUNT(*) >= 2
      )
    LIMIT 1
");
$stmtCheck->execute(["DUELO|{$student_id}|{$target_id}|%", "DUELO|{$target_id}|{$student_id}|%"]);
$existing_duel = $stmtCheck->fetchColumn();

if ($existing_duel) {
    // Já existe um duelo pendente. Redireciona para resolver ou avisa.
    header("Location: sprint_solve.php?id=" . $existing_duel);
    exit;
}

// 3. Gerar 5 questões aleatórias (GeriaS para ser justo)
$stmtQuestions = $pdo->query("SELECT id FROM questions ORDER BY RAND() LIMIT 5");
$questions = $stmtQuestions->fetchAll(PDO::FETCH_COLUMN);

if (count($questions) == 0) {
    header("Location: student_arena.php");
    exit;
}

// 4. Criar a Sprint do Duelo
$duel_name = "DUELO|{$student_id}|{$target_id}|" . time();
$stmtCreateDuel = $pdo->prepare("
    INSERT INTO sprints (class_id, teacher_id, name, status, time_limit_minutes) 
    VALUES (?, NULL, ?, 'active', 999)
");
$stmtCreateDuel->execute([$my_class, $duel_name]);
$sprint_id = $pdo->lastInsertId();

// 5. Vincular as questões
$stmtInsertQ = $pdo->prepare("INSERT INTO sprint_questions (sprint_id, question_id, order_num) VALUES (?, ?, ?)");
$order = 1;
foreach ($questions as $q_id) {
    $stmtInsertQ->execute([$sprint_id, $q_id, $order]);
    $order++;
}

// 6. Enviar o Desafiante direto para a batalha!
header("Location: sprint_solve.php?id=" . $sprint_id);
exit;
