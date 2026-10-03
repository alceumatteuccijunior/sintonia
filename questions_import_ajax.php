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
        
        // Padrão: Pegamos o primeiro curso, ou criamos um "Geral" para simplificar a estrutura CSV (já que o CSV não tem curso).
        $stmt = $pdo->prepare("SELECT id FROM courses LIMIT 1");
        $stmt->execute();
        $courseId = $stmt->fetchColumn();
        if (!$courseId) {
            $pdo->exec("INSERT INTO courses (name, description) VALUES ('Curso Geral', 'Gerado pelo sistema')");
            $courseId = $pdo->lastInsertId();
        }
        
        $totalQuestoes = 0;
        $fileHandle = fopen($file['tmp_name'], 'r');
        
        // Pular cabeçalho
        fgetcsv($fileHandle, 0, ",");
        
        while (($row = fgetcsv($fileHandle, 0, ",")) !== FALSE) {
            // Colunas: Módulo (0), Capacidade (1), Enunciado (2), Dificuldade (3), Tag (4), A(Correta)(5), B(6), C(7), D(8), E(9)
            if (count($row) < 10) continue; // Pular linhas incompletas
            
            $moduleName = trim($row[0]);
            $capacity = trim($row[1]);
            $command = trim($row[2]);
            $difficulty = trim($row[3]);
            $tag = trim($row[4]) ?: null;
            
            $optCorrect = trim($row[5]);
            $optB = trim($row[6]);
            $optC = trim($row[7]);
            $optD = trim($row[8]);
            $optE = trim($row[9]);
            
            if (!$moduleName || !$command || !$optCorrect) continue;
            
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
