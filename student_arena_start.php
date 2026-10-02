<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: index.php");
    exit;
}
require_once 'config.php';
$pdo = getPDOConnection(true);

$student_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: student_arena.php");
    exit;
}

// 1. Encontrar a turma do aluno
$stmtClass = $pdo->prepare("SELECT class_id FROM class_students WHERE student_id = ? LIMIT 1");
$stmtClass->execute([$student_id]);
$class_id = $stmtClass->fetchColumn();

if (!$class_id) {
    header("Location: student_setup.php");
    exit;
}

// 2. Sortear 5 questões aleatórias (que o aluno nunca acertou)
$stmtQuestions = $pdo->prepare("
    SELECT q.id 
    FROM questions q 
    WHERE q.id NOT IN (
        SELECT sa.question_id 
        FROM student_answers sa 
        WHERE sa.student_id = ? AND sa.is_correct = 1
    )
    ORDER BY RAND() 
    LIMIT 5
");
$stmtQuestions->execute([$student_id]);
$questions = $stmtQuestions->fetchAll(PDO::FETCH_COLUMN);

if (count($questions) == 0) {
    // Parabéns, o aluno zerou o banco de questões!
    // Para não dar erro, pegamos 5 aleatórias gerais
    $stmtFallback = $pdo->query("SELECT id FROM questions ORDER BY RAND() LIMIT 5");
    $questions = $stmtFallback->fetchAll(PDO::FETCH_COLUMN);
}

// 3. Criar uma "Sprint de Treinamento" invisível para o professor
$sprint_name = "Missão de Treinamento - " . date('d/m/Y');
$stmtCreateSprint = $pdo->prepare("
    INSERT INTO sprints (class_id, teacher_id, name, status, time_limit_minutes) 
    VALUES (?, NULL, ?, 'active', 999)
");
$stmtCreateSprint->execute([$class_id, $sprint_name]);
$sprint_id = $pdo->lastInsertId();

// 4. Vincular as questões à Sprint
$stmtInsertQ = $pdo->prepare("INSERT INTO sprint_questions (sprint_id, question_id, order_num) VALUES (?, ?, ?)");
$order = 1;
foreach ($questions as $q_id) {
    $stmtInsertQ->execute([$sprint_id, $q_id, $order]);
    $order++;
}

// 5. Redirecionar o aluno para responder a prova
header("Location: sprint_solve.php?id=" . $sprint_id);
exit;
