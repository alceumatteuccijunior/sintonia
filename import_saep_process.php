<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    die("Acesso negado.");
}

require_once 'config.php';
$pdo = getPDOConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['saep_file'])) {
    die("Método inválido ou arquivo não enviado.");
}

$file = $_FILES['saep_file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    die("Erro no upload do arquivo.");
}

$handle = fopen($file['tmp_name'], "r");
if ($handle === FALSE) {
    die("Erro ao ler o arquivo.");
}

// Configurações e caches para evitar consultas repetidas
$regionalsCache = [];
$unitsCache = [];
$coursesCache = [];
$classesCache = [];
$usersCache = [];
$modulesCache = [];
$capacitiesCache = [];
$questionsCache = [];
$optionsCache = []; // $optionsCache[question_id][letra] = option_id
$sprintsCache = [];
$sprintAttemptsCache = [];
$sprintQuestionsSet = []; // Para saber se já vinculamos a questão na sprint

$pdo->beginTransaction();

try {
    $headerFound = false;
    $colMap = []; // Guarda o índice das colunas importantes
    $rowIndex = 0;

    while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {
        $rowIndex++;
        
        // Verifica se é a linha de cabeçalho
        if (!$headerFound) {
            // Verifica se a linha contém as colunas base
            if (in_array('DR', $data) && in_array('Aluno', $data) && in_array('Gabarito', $data)) {
                $headerFound = true;
                foreach ($data as $index => $colName) {
                    $colMap[trim($colName)] = $index;
                }
            }
            continue;
        }

        // Garante que a linha tem os dados necessários
        if (!isset($data[$colMap['Aluno']]) || empty(trim($data[$colMap['Aluno']]))) {
            continue;
        }

        // --- 1. Extração de Variáveis ---
        $dr_name = trim($data[$colMap['DR']] ?? 'Padrão');
        $escola_name = trim($data[$colMap['Escola']] ?? 'Sede');
        $course_name = trim($data[$colMap['Curso']]);
        $class_name = trim($data[$colMap['Turma']]);
        $student_name = trim($data[$colMap['Aluno']]);
        $matricula = trim($data[$colMap['Matrícula']]);
        
        $sprint_name = trim($data[$colMap['Avaliação']]);
        $time_realization = trim($data[$colMap['Tempo de realização']]); // Ex: 00:26:50
        
        $q_ident = trim($data[$colMap['Identificador']]);
        $capacity_code = trim($data[$colMap['Capacidade']]);
        if (is_numeric($capacity_code)) {
            $capacity_code = 'C' . $capacity_code; // Padroniza para C1, C2
        }
        $module_name = trim($data[$colMap['Subfunção']]);
        $capacity_desc = trim($data[$colMap['Padrão de desempenho']]);
        $q_knowledge = trim($data[$colMap['Conhecimento']]);
        
        $raw_diff = strtolower(trim($data[$colMap['Dificuldade']]));
        $difficulty = 'Médio';
        if (strpos($raw_diff, 'facil') !== false || strpos($raw_diff, 'fácil') !== false) $difficulty = 'Fácil';
        if (strpos($raw_diff, 'dificil') !== false || strpos($raw_diff, 'difícil') !== false) $difficulty = 'Difícil';
        
        $student_choice = trim($data[$colMap['Marcação respondente']]);
        $correct_choice = trim($data[$colMap['Gabarito']]);


        // --- 1.5. Regionais e Unidades ---
        if (!isset($regionalsCache[$dr_name])) {
            $stmt = $pdo->prepare("SELECT id FROM regionals WHERE name = ?");
            $stmt->execute([$dr_name]);
            $rid = $stmt->fetchColumn();
            if (!$rid) {
                $pdo->prepare("INSERT INTO regionals (name) VALUES (?)")->execute([$dr_name]);
                $rid = $pdo->lastInsertId();
            }
            $regionalsCache[$dr_name] = $rid;
        }
        $regional_id = $regionalsCache[$dr_name];

        $unit_cache_key = $regional_id . '_' . $escola_name;
        if (!isset($unitsCache[$unit_cache_key])) {
            $stmt = $pdo->prepare("SELECT id FROM units WHERE regional_id = ? AND name = ?");
            $stmt->execute([$regional_id, $escola_name]);
            $unid = $stmt->fetchColumn();
            if (!$unid) {
                $pdo->prepare("INSERT INTO units (regional_id, name) VALUES (?, ?)")->execute([$regional_id, $escola_name]);
                $unid = $pdo->lastInsertId();
            }
            $unitsCache[$unit_cache_key] = $unid;
        }
        $unit_id = $unitsCache[$unit_cache_key];

        // --- 2. Cursos e Turmas ---
        if (!isset($coursesCache[$course_name])) {
            $stmt = $pdo->prepare("SELECT id FROM courses WHERE name = ?");
            $stmt->execute([$course_name]);
            $cid = $stmt->fetchColumn();
            if (!$cid) {
                $pdo->prepare("INSERT INTO courses (name) VALUES (?)")->execute([$course_name]);
                $cid = $pdo->lastInsertId();
            }
            $coursesCache[$course_name] = $cid;
        }
        $course_id = $coursesCache[$course_name];

        if (!isset($classesCache[$class_name])) {
            $stmt = $pdo->prepare("SELECT id FROM classes WHERE name = ? AND course_id = ? AND unit_id = ?");
            $stmt->execute([$class_name, $course_id, $unit_id]);
            $clid = $stmt->fetchColumn();
            if (!$clid) {
                // Tenta extrair ano/semestre se houver, caso contrário, joga padrão
                $pdo->prepare("INSERT INTO classes (unit_id, course_id, name, year, semester) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$unit_id, $course_id, $class_name, date('Y'), 1]);
                $clid = $pdo->lastInsertId();
            }
            $classesCache[$class_name] = $clid;
        }
        $class_id = $classesCache[$class_name];

        // --- 3. Alunos ---
        if (!isset($usersCache[$matricula])) {
            $email = strtolower($matricula) . "@estudante.sesisenai.org.br";
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $uid = $stmt->fetchColumn();
            if (!$uid) {
                $hash = password_hash($matricula, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO users (unit_id, name, email, password, role) VALUES (?, ?, ?, ?, 'student')")
                    ->execute([$unit_id, $student_name, $email, $hash]);
                $uid = $pdo->lastInsertId();
            } else {
                // Se o usuário já existe, garantir que ele esteja vinculado a esta unidade
                $pdo->prepare("UPDATE users SET unit_id = ? WHERE id = ? AND unit_id IS NULL")->execute([$unit_id, $uid]);
            }
            $usersCache[$matricula] = $uid;
            
            // Vincular aluno à turma se não estiver
            $pdo->prepare("INSERT IGNORE INTO class_students (class_id, student_id) VALUES (?, ?)")
                ->execute([$class_id, $uid]);
        }
        $student_id = $usersCache[$matricula];

        // --- 4. Sprint (Avaliação) ---
        $sprint_cache_key = $sprint_name . '_' . $class_id;
        if (!isset($sprintsCache[$sprint_cache_key])) {
            $stmt = $pdo->prepare("SELECT id FROM sprints WHERE name = ? AND class_id = ?");
            $stmt->execute([$sprint_name, $class_id]);
            $spid = $stmt->fetchColumn();
            if (!$spid) {
                $pdo->prepare("INSERT INTO sprints (class_id, teacher_id, name, status, time_limit_minutes, start_time, end_time) VALUES (?, ?, ?, 'completed', 0, NOW(), NOW())")
                    ->execute([$class_id, $_SESSION['user_id'], $sprint_name]);
                $spid = $pdo->lastInsertId();
            }
            $sprintsCache[$sprint_cache_key] = $spid;
        }
        $sprint_id = $sprintsCache[$sprint_cache_key];

        // --- 5. Tentativa do Aluno (Attempt) ---
        $attempt_cache_key = $sprint_id . '_' . $student_id;
        if (!isset($sprintAttemptsCache[$attempt_cache_key])) {
            $stmt = $pdo->prepare("SELECT id FROM sprint_attempts WHERE sprint_id = ? AND student_id = ?");
            $stmt->execute([$sprint_id, $student_id]);
            if (!$stmt->fetchColumn()) {
                // Calcula completed_at baseado no tempo de realização (HH:MM:SS)
                $time_parts = explode(':', $time_realization);
                $secs = 0;
                if (count($time_parts) == 3) {
                    $secs = ($time_parts[0]*3600) + ($time_parts[1]*60) + $time_parts[2];
                }
                $start_time = date('Y-m-d H:i:s', time() - $secs); // Simula o tempo
                
                $pdo->prepare("INSERT INTO sprint_attempts (sprint_id, student_id, started_at, completed_at) VALUES (?, ?, ?, NOW())")
                    ->execute([$sprint_id, $student_id, $start_time]);
            }
            $sprintAttemptsCache[$attempt_cache_key] = true;
        }

        // --- 6. Módulos e Capacidades ---
        if (!isset($modulesCache[$module_name])) {
            $stmt = $pdo->prepare("SELECT id FROM modules WHERE name = ? AND course_id = ?");
            $stmt->execute([$module_name, $course_id]);
            $mid = $stmt->fetchColumn();
            if (!$mid) {
                $pdo->prepare("INSERT INTO modules (course_id, name) VALUES (?, ?)")->execute([$course_id, $module_name]);
                $mid = $pdo->lastInsertId();
            }
            $modulesCache[$module_name] = $mid;
        }
        $module_id = $modulesCache[$module_name];

        if (!isset($capacitiesCache[$module_id][$capacity_code])) {
            $stmt = $pdo->prepare("SELECT id FROM module_capacities WHERE module_id = ? AND capacity_code = ?");
            $stmt->execute([$module_id, $capacity_code]);
            $capid = $stmt->fetchColumn();
            if (!$capid) {
                $pdo->prepare("INSERT INTO module_capacities (module_id, capacity_code, description) VALUES (?, ?, ?)")
                    ->execute([$module_id, $capacity_code, $capacity_desc]);
                $capid = $pdo->lastInsertId();
            }
            $capacitiesCache[$module_id][$capacity_code] = $capid;
        }

        // --- 7. Questões e Alternativas ---
        if (!isset($questionsCache[$q_ident])) {
            // Verifica se a questão já existe (por Identificador/Command)
            $stmt = $pdo->prepare("SELECT id FROM questions WHERE command LIKE ? LIMIT 1");
            $stmt->execute(["%".$q_ident."%"]);
            $qid = $stmt->fetchColumn();
            
            if (!$qid) {
                // Cria a questão (Usando o Conhecimento + Identificador no comando para referência)
                $cmd = "[$q_ident] " . $q_knowledge;
                $pdo->prepare("INSERT INTO questions (module_id, capacity, command, difficulty, teacher_id) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$module_id, $capacity_code, $cmd, $difficulty, $_SESSION['user_id']]);
                $qid = $pdo->lastInsertId();
                
                // Criar as 5 alternativas (A, B, C, D, E)
                $letters = ['A', 'B', 'C', 'D', 'E'];
                foreach ($letters as $l) {
                    $is_correct = ($l === $correct_choice) ? 1 : 0;
                    $opt_text = "Alternativa " . $l;
                    $pdo->prepare("INSERT INTO question_options (question_id, text, is_correct) VALUES (?, ?, ?)")
                        ->execute([$qid, $opt_text, $is_correct]);
                    $opt_id = $pdo->lastInsertId();
                    $optionsCache[$qid][$l] = $opt_id;
                }
            } else {
                // Já existe, então precisa popular o cache de opções
                $stmtOpts = $pdo->prepare("SELECT id, text FROM question_options WHERE question_id = ?");
                $stmtOpts->execute([$qid]);
                $opts = $stmtOpts->fetchAll();
                // Assumindo que Alternativa A contém "A" etc
                foreach ($opts as $o) {
                    $l = substr($o['text'], -1); // "Alternativa A" -> "A"
                    if (in_array($l, ['A','B','C','D','E'])) {
                        $optionsCache[$qid][$l] = $o['id'];
                    }
                }
            }
            $questionsCache[$q_ident] = $qid;
        }
        $question_id = $questionsCache[$q_ident];

        // --- 8. Vincula Questão à Sprint ---
        $sq_key = $sprint_id . '_' . $question_id;
        if (!isset($sprintQuestionsSet[$sq_key])) {
            $pdo->prepare("INSERT IGNORE INTO sprint_questions (sprint_id, question_id, order_num) VALUES (?, ?, ?)")
                ->execute([$sprint_id, $question_id, count($sprintQuestionsSet) + 1]);
            $sprintQuestionsSet[$sq_key] = true;
        }

        // --- 9. Resposta do Aluno ---
        // Verifica se a resposta não está em branco
        $selected_option_id = null;
        $is_correct_ans = null;
        
        if (in_array($student_choice, ['A','B','C','D','E'])) {
            $selected_option_id = $optionsCache[$question_id][$student_choice] ?? null;
            $is_correct_ans = ($student_choice === $correct_choice) ? 1 : 0;
        }

        // Insere ou atualiza (para evitar duplicações caso rodem o mesmo arquivo 2x)
        $stmtAns = $pdo->prepare("SELECT id FROM student_answers WHERE sprint_id = ? AND question_id = ? AND student_id = ?");
        $stmtAns->execute([$sprint_id, $question_id, $student_id]);
        if (!$stmtAns->fetchColumn()) {
            $pdo->prepare("INSERT INTO student_answers (sprint_id, question_id, student_id, selected_option_id, is_correct) VALUES (?, ?, ?, ?, ?)")
                ->execute([$sprint_id, $question_id, $student_id, $selected_option_id, $is_correct_ans]);
        }
    }

    fclose($handle);
    $pdo->commit();

    // Redireciona com sucesso
    echo "<!DOCTYPE html><html lang='pt-BR'><head><meta charset='UTF-8'><title>Importação Concluída</title><script src='https://cdn.tailwindcss.com'></script></head>
          <body class='bg-slate-50 flex items-center justify-center h-screen'>
            <div class='bg-white p-8 rounded-3xl shadow-xl text-center max-w-md w-full border border-slate-100'>
                <div class='w-20 h-20 bg-green-100 text-green-500 rounded-full flex items-center justify-center mx-auto mb-6'>
                    <svg class='w-10 h-10' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='3' d='M5 13l4 4L19 7'></path></svg>
                </div>
                <h1 class='text-2xl font-bold text-slate-800 mb-2'>Importação Concluída!</h1>
                <p class='text-slate-500 mb-8'>Foram processadas " . $rowIndex . " linhas do SAEP com sucesso.</p>
                <a href='admin_dashboard.php' class='bg-indigo-600 text-white font-bold py-3 px-6 rounded-xl hover:bg-indigo-700 transition-colors inline-block w-full'>Voltar ao Painel</a>
            </div>
          </body></html>";

} catch (Exception $e) {
    $pdo->rollBack();
    die("Erro durante a importação: " . $e->getMessage());
}
?>
