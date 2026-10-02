<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'teacher'])) {
    echo json_encode(['success' => false, 'message' => 'Acesso negado.']);
    exit;
}

require_once 'config.php';
$pdo = getPDOConnection(true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['json_file'])) {
    
    $file = $_FILES['json_file'];
    
    // Validar erros de upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Erro ao realizar upload do arquivo. Código: ' . $file['error']]);
        exit;
    }
    
    // Validar extensão
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    if (strtolower($ext) !== 'json') {
        echo json_encode(['success' => false, 'message' => 'Formato inválido. Envie um arquivo .json.']);
        exit;
    }
    
    // Ler o arquivo
    $jsonContent = file_get_contents($file['tmp_name']);
    $data = json_decode($jsonContent, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['success' => false, 'message' => 'O arquivo JSON contém erros de sintaxe (JSON Inválido). Verifique a formatação do template.']);
        exit;
    }
    
    // Validar estrutura principal
    if (!isset($data['prova']) || !isset($data['prova']['curso']) || !isset($data['prova']['modulos'])) {
        echo json_encode(['success' => false, 'message' => 'Estrutura inválida. O JSON não segue o formato esperado do Template (Falta "prova", "curso" ou "modulos").']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        $courseName = trim($data['prova']['curso']);
        $teacherId = $_SESSION['user_id'];
        
        // 1. Verificar/Criar Curso
        $stmt = $pdo->prepare("SELECT id FROM courses WHERE name = ?");
        $stmt->execute([$courseName]);
        $course = $stmt->fetch();
        
        if (!$course) {
            $stmt = $pdo->prepare("INSERT INTO courses (name, description) VALUES (?, ?)");
            $stmt->execute([$courseName, 'Curso gerado via importação de JSON.']);
            $courseId = $pdo->lastInsertId();
        } else {
            $courseId = $course['id'];
        }
        
        $totalQuestoes = 0;
        $totalModulos = 0;
        
        // 2. Iterar Módulos
        foreach ($data['prova']['modulos'] as $modulo) {
            if (!isset($modulo['nome'])) continue;
            
            $unidadeName = trim($modulo['nome']);
            
            // Verificar/Criar Módulo
            $stmt = $pdo->prepare("SELECT id FROM modules WHERE course_id = ? AND name = ?");
            $stmt->execute([$courseId, $unidadeName]);
            $module_db = $stmt->fetch();
            
            if (!$module_db) {
                $stmt = $pdo->prepare("INSERT INTO modules (course_id, name) VALUES (?, ?)");
                $stmt->execute([$courseId, $unidadeName]);
                $moduleId = $pdo->lastInsertId();
                $totalModulos++;
            } else {
                $moduleId = $module_db['id'];
            }
            
            // 3. Cadastrar Capacidades (se houver)
            if (isset($modulo['capacidades_avaliadas']) && is_array($modulo['capacidades_avaliadas'])) {
                foreach ($modulo['capacidades_avaliadas'] as $cap) {
                    $capCode = trim($cap['id'] ?? '');
                    $capDesc = trim($cap['descricao'] ?? '');
                    if ($capCode && $capDesc) {
                        $stmt = $pdo->prepare("INSERT IGNORE INTO module_capacities (module_id, capacity_code, description) VALUES (?, ?, ?)");
                        $stmt->execute([$moduleId, $capCode, $capDesc]);
                    }
                }
            }
            
            // 4. Cadastrar Questões
            if (isset($modulo['questoes']) && is_array($modulo['questoes'])) {
                foreach ($modulo['questoes'] as $q) {
                    $dificuldade = $q['dificuldade'] ?? 'Médio';
                    $capacidade = $q['capacidade'] ?? '';
                    $contexto = $q['contexto'] ?? '';
                    $comando = $q['comando'] ?? '';
                    $alternativas = $q['alternativas'] ?? [];
                    $resposta_correta = $q['resposta_correta'] ?? '';
                    
                    if (empty($comando) || empty($alternativas) || empty($resposta_correta)) {
                        continue; // Pula questões mal formatadas
                    }
                    
                    // Inserir Questão
                    $stmt = $pdo->prepare("INSERT INTO questions (module_id, capacity, context, command, difficulty, teacher_id) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$moduleId, $capacidade, $contexto, $comando, $dificuldade, $teacherId]);
                    $questionId = $pdo->lastInsertId();
                    
                    // Inserir Alternativas
                    foreach ($alternativas as $letra => $texto) {
                        $is_correct = ($letra === $resposta_correta) ? 1 : 0;
                        $stmtOpt = $pdo->prepare("INSERT INTO question_options (question_id, text, is_correct) VALUES (?, ?, ?)");
                        $stmtOpt->execute([$questionId, $texto, $is_correct]);
                    }
                    
                    $totalQuestoes++;
                }
            }
        }
        
        $pdo->commit();
        echo json_encode([
            'success' => true, 
            'message' => "Importação concluída com sucesso! $totalQuestoes questões foram adicionadas ao curso '$courseName'."
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Erro no banco de dados durante a importação: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Nenhum arquivo enviado.']);
}
