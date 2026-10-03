<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'teacher'])) {
    echo json_encode(['success' => false, 'message' => 'Acesso negado.']);
    exit;
}

require_once 'config.php';
$pdo = getPDOConnection(true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    
    $file = $_FILES['csv_file'];
    
    // Validar erros de upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Erro ao realizar upload do arquivo. Código: ' . $file['error']]);
        exit;
    }
    
    // Validar extensão
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    if (strtolower($ext) !== 'csv') {
        echo json_encode(['success' => false, 'message' => 'Formato inválido. Envie um arquivo .csv.']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        $teacherId = $_SESSION['user_id'];
        
        $totalQuestoes = 0;
        $fileHandle = fopen($file['tmp_name'], 'r');
        
        // Pular cabeçalho
        fgetcsv($fileHandle, 0, ",");
        
        while (($row = fgetcsv($fileHandle, 0, ",")) !== FALSE) {
            // Colunas: Curso (0), Módulo (1), Capacidade (2), Enunciado (3), Dificuldade (4), Tag (5), A(Correta)(6), B(7), C(8), D(9), E(10)
            if (count($row) < 11) continue; // Pular linhas incompletas
            
            $courseName = trim($row[0]);
            $moduleName = trim($row[1]);
            $capacity = trim($row[2]);
            $command = trim($row[3]);
            $difficulty = trim($row[4]);
            $tag = trim($row[5]) ?: null;
            
            $optCorrect = trim($row[6]);
            $optB = trim($row[7]);
            $optC = trim($row[8]);
            $optD = trim($row[9]);
            $optE = trim($row[10]);
            
            if (!$courseName || !$moduleName || !$command || !$optCorrect) continue;

            // 0. Course
            $stmt = $pdo->prepare("SELECT id FROM courses WHERE name = ?");
            $stmt->execute([$courseName]);
            $courseId = $stmt->fetchColumn();
            if (!$courseId) {
                $pdo->prepare("INSERT INTO courses (name, description) VALUES (?, 'Curso gerado via importação')")->execute([$courseName]);
                $courseId = $pdo->lastInsertId();
            }
            
            // 1. Module
            $stmt = $pdo->prepare("SELECT id FROM modules WHERE name = ? AND course_id = ?");
            $stmt->execute([$moduleName, $courseId]);
            $moduleId = $stmt->fetchColumn();
            if (!$moduleId) {
                $pdo->prepare("INSERT INTO modules (course_id, name) VALUES (?, ?)")->execute([$courseId, $moduleName]);
                $moduleId = $pdo->lastInsertId();
            }
            
            // 2. Insert Question
            // Usando IGNORE ou normal? Vamos normal
            $stmtInsert = $pdo->prepare("INSERT INTO questions (module_id, capacity, command, difficulty, import_tag, teacher_id) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtInsert->execute([$moduleId, $capacity, $command, $difficulty, $tag, $teacherId]);
            $questionId = $pdo->lastInsertId();
            
            // 3. Insert Options
            $optsToInsert = [
                ['text' => $optCorrect, 'correct' => 1],
                ['text' => $optB, 'correct' => 0],
                ['text' => $optC, 'correct' => 0],
                ['text' => $optD, 'correct' => 0],
                ['text' => $optE, 'correct' => 0],
            ];
            
            $stmtOpt = $pdo->prepare("INSERT INTO question_options (question_id, text, is_correct) VALUES (?, ?, ?)");
            foreach ($optsToInsert as $opt) {
                if (!empty($opt['text'])) {
                    $stmtOpt->execute([$questionId, $opt['text'], $opt['correct']]);
                }
            }
            
            $totalQuestoes++;
        }
        
        fclose($fileHandle);
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "Importação concluída com sucesso! $totalQuestoes questão(ões) foram importadas."
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Erro interno ao importar CSV: ' . $e->getMessage()
        ]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Nenhum arquivo enviado.']);
}
