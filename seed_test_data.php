<?php
// seed_test_data.php
// Script para gerar dados simulados (Evolutivos) para testar os gráficos

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    die("Apenas administradores podem rodar o seed.");
}

require_once 'config.php';
$pdo = getPDOConnection();

echo "<h2>Iniciando geração de dados simulados...</h2>";

try {
    $pdo->beginTransaction();

    // 1. Criar um Curso e Turma de Teste
    $pdo->exec("INSERT INTO courses (name, description) VALUES ('Curso Mockado de TI', 'Curso gerado para testes de gráfico')");
    $course_id = $pdo->lastInsertId();

    $pdo->exec("INSERT INTO classes (course_id, name, year, semester) VALUES ($course_id, 'Turma Teste Gráficos 2026', 2026, 2)");
    $class_id = $pdo->lastInsertId();
    echo "<p>Turma 'Turma Teste Gráficos 2026' criada.</p>";

    // 2. Criar Módulos e Capacidades
    $modules = ['Lógica de Programação', 'Banco de Dados', 'Engenharia de Requisitos'];
    $module_ids = [];
    foreach ($modules as $m) {
        $stmt = $pdo->prepare("INSERT INTO modules (course_id, name) VALUES (?, ?)");
        $stmt->execute([$course_id, $m]);
        $module_ids[] = $pdo->lastInsertId();
    }

    $capacities = ['C1 - Sintaxe', 'C2 - Modelagem', 'C3 - Levantamento'];

    // 3. Criar Questões Mockadas (30 questões)
    $question_ids = [];
    for ($i = 1; $i <= 30; $i++) {
        $mod_idx = $i % 3;
        $mod_id = $module_ids[$mod_idx];
        $cap = $capacities[$mod_idx];
        
        $stmtQ = $pdo->prepare("INSERT INTO questions (module_id, capacity, command, difficulty) VALUES (?, ?, ?, 'Médio')");
        $stmtQ->execute([$mod_id, $cap, "Questão de teste $i sobre " . $modules[$mod_idx]]);
        $q_id = $pdo->lastInsertId();
        $question_ids[] = $q_id;

        // Criar opções (1 correta, 3 erradas)
        $pdo->exec("INSERT INTO question_options (question_id, text, is_correct) VALUES ($q_id, 'Opção Correta $i', 1)");
        $opt_correct = $pdo->lastInsertId();
        $pdo->exec("INSERT INTO question_options (question_id, text, is_correct) VALUES ($q_id, 'Errada A', 0)");
        $opt_wrong1 = $pdo->lastInsertId();
        $pdo->exec("INSERT INTO question_options (question_id, text, is_correct) VALUES ($q_id, 'Errada B', 0)");
        $opt_wrong2 = $pdo->lastInsertId();
    }
    echo "<p>30 Questões criadas.</p>";

    // 4. Criar Alunos de Teste
    $student_ids = [];
    $pass = password_hash('123456', PASSWORD_DEFAULT);
    for ($i = 1; $i <= 5; $i++) {
        $email = "alunomock$i@senai.br";
        $stmtU = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'student')");
        $stmtU->execute(["Aluno Teste $i", $email, $pass]);
        $s_id = $pdo->lastInsertId();
        $student_ids[] = $s_id;

        $pdo->exec("INSERT INTO class_students (class_id, student_id) VALUES ($class_id, $s_id)");
    }
    echo "<p>5 Alunos criados e vinculados à turma.</p>";

    // 5. Criar 4 Sprints espaçadas no tempo (Evolutivas)
    $teacher_id = $_SESSION['user_id'];
    $sprints_data = [
        ['name' => 'Sprint 1 - Nivelamento', 'date' => '2026-08-01 10:00:00', 'base_chance' => 30], // 30% de chance de acerto
        ['name' => 'Sprint 2 - Intermediário', 'date' => '2026-08-10 10:00:00', 'base_chance' => 50], // 50% de chance
        ['name' => 'Sprint 3 - Avançado', 'date' => '2026-08-20 10:00:00', 'base_chance' => 70], // 70% de chance
        ['name' => 'Sprint 4 - Simulado Final', 'date' => '2026-08-28 10:00:00', 'base_chance' => 90], // 90% de chance
    ];

    foreach ($sprints_data as $sd) {
        $stmtS = $pdo->prepare("INSERT INTO sprints (class_id, teacher_id, name, status, start_time, end_time) VALUES (?, ?, ?, 'completed', ?, ?)");
        $end_time = date('Y-m-d H:i:s', strtotime($sd['date'] . ' + 2 hours'));
        $stmtS->execute([$class_id, $teacher_id, $sd['name'], $sd['date'], $end_time]);
        $sprint_id = $pdo->lastInsertId();

        // Add questions to sprint (use 15 random questions)
        shuffle($question_ids);
        $sq_ids = array_slice($question_ids, 0, 15);
        $order = 1;
        foreach ($sq_ids as $qid) {
            $pdo->exec("INSERT INTO sprint_questions (sprint_id, question_id, order_num) VALUES ($sprint_id, $qid, $order)");
            $order++;
        }

        // Generate answers for students in this sprint
        foreach ($student_ids as $s_id) {
            $pdo->exec("INSERT INTO sprint_attempts (sprint_id, student_id, started_at, completed_at) VALUES ($sprint_id, $s_id, '{$sd['date']}', '{$end_time}')");

            foreach ($sq_ids as $qid) {
                // Determine if student got it right based on base_chance
                // add some randomness per student so lines aren't identical
                $student_bonus = ($s_id % 3) * 5; // pseudo-random bonus
                $chance = $sd['base_chance'] + $student_bonus;
                
                $is_correct = (rand(1, 100) <= $chance) ? 1 : 0;
                
                // Pega a opção baseada no acerto
                $stmtOpt = $pdo->query("SELECT id FROM question_options WHERE question_id = $qid AND is_correct = $is_correct LIMIT 1");
                if ($stmtOpt->rowCount() == 0) {
                    $stmtOpt = $pdo->query("SELECT id FROM question_options WHERE question_id = $qid LIMIT 1");
                }
                $opt_id = $stmtOpt->fetchColumn();

                $stmtA = $pdo->prepare("INSERT INTO student_answers (sprint_id, question_id, student_id, selected_option_id, is_correct) VALUES (?, ?, ?, ?, ?)");
                $stmtA->execute([$sprint_id, $qid, $s_id, $opt_id, $is_correct]);
            }
        }
    }
    echo "<p>4 Sprints realizadas no tempo simulando a Evolução (Média subindo de 30% para 90%). Respostas registradas.</p>";

    $pdo->commit();
    echo "<h3 style='color:green;'>Seed Finalizado com Sucesso!</h3>";
    echo "<p>Vá até o menu <b>Histórico do Aluno</b> e selecione a turma <b>Turma Teste Gráficos 2026</b> para ver os gráficos subindo lindamente!</p>";
    echo "<a href='student_history.php' style='padding: 10px 20px; background: #0038A8; color: white; text-decoration: none; border-radius: 5px; font-family: sans-serif; font-weight: bold;'>Acessar Histórico do Aluno</a>";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "<h3 style='color:red;'>Erro ao rodar seed: " . $e->getMessage() . "</h3>";
}
?>
