<?php
// sql/import_json.php
// Script para importar o banco de questões inicial (JSON) para o banco de dados Sintonia

require_once '../config.php';

echo "<!DOCTYPE html>
<html lang='pt-BR'>
<head>
    <meta charset='UTF-8'>
    <title>Importação - Sintonia</title>
    <style>
        body { font-family: 'Inter', sans-serif; background: #F8FAFC; color: #333; padding: 40px; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        h1 { color: #1A428A; }
        .success { color: #34A853; }
        .info { color: #1A428A; }
        .error { color: #EA4335; font-weight: bold; }
        .log { background: #1e1e1e; color: #00ff00; padding: 15px; border-radius: 5px; font-family: monospace; overflow-y: auto; max-height: 500px; margin-top: 20px;}
    </style>
</head>
<body>
<div class='container'>
    <h1>Importação de Questões JSON</h1>
    <div class='log'>";

try {
    $pdo = getPDOConnection(true);

    $jsonFile = '../bancodequestoesinicial.json';
    if (!file_exists($jsonFile)) {
        throw new Exception("Arquivo JSON não encontrado no caminho: " . $jsonFile);
    }

    echo "Lendo arquivo JSON...<br>";
    $jsonContent = file_get_contents($jsonFile);
    $data = json_decode($jsonContent, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Erro ao interpretar o JSON: " . json_last_error_msg());
    }

    // Criar o curso se não existir
    $courseName = "Técnico em Informática para Internet";
    $stmt = $pdo->prepare("SELECT id FROM courses WHERE name = ?");
    $stmt->execute([$courseName]);
    $course = $stmt->fetch();

    if (!$course) {
        $stmt = $pdo->prepare("INSERT INTO courses (name, description) VALUES (?, ?)");
        $stmt->execute([$courseName, 'Curso gerado automaticamente via importação.']);
        $courseId = $pdo->lastInsertId();
        echo "<span class='info'>Curso '{$courseName}' criado (ID: {$courseId}).</span><br>";
    } else {
        $courseId = $course['id'];
        echo "<span class='info'>Curso '{$courseName}' encontrado (ID: {$courseId}).</span><br>";
    }

    $modulos = $data['prova']['modulos'] ?? [];
    $totalQuestoes = 0;

    foreach ($modulos as $modulo) {
        $unidadeName = $modulo['nome'];
        
        // Criar ou obter módulo (unidade curricular)
        $stmt = $pdo->prepare("SELECT id FROM modules WHERE course_id = ? AND name = ?");
        $stmt->execute([$courseId, $unidadeName]);
        $module_db = $stmt->fetch();

        if (!$module_db) {
            $stmt = $pdo->prepare("INSERT INTO modules (course_id, name) VALUES (?, ?)");
            $stmt->execute([$courseId, $unidadeName]);
            $moduleId = $pdo->lastInsertId();
            echo "<span class='info'>- Módulo '{$unidadeName}' criado (ID: {$moduleId}).</span><br>";
        } else {
            $moduleId = $module_db['id'];
            echo "<span class='info'>- Módulo '{$unidadeName}' encontrado (ID: {$moduleId}).</span><br>";
        }

        foreach ($modulo['questoes'] as $q) {
            $dificuldade = $q['dificuldade'] ?? 'Médio';
            $capacidade = $q['capacidade'] ?? '';
            $contexto = $q['contexto'] ?? '';
            $comando = $q['comando'] ?? '';
            $alternativas = $q['alternativas'] ?? [];
            $resposta_correta = $q['resposta_correta'] ?? '';

            // Inserir Questão
            $stmt = $pdo->prepare("INSERT INTO questions (module_id, capacity, context, command, difficulty) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$moduleId, $capacidade, $contexto, $comando, $dificuldade]);
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

    echo "</div>";
    echo "<h2 class='success' style='margin-top:20px;'>✔ Importação Concluída!</h2>";
    echo "<p>Total de {$totalQuestoes} questões importadas com sucesso para o curso '{$courseName}'.</p>";

} catch (Exception $e) {
    echo "</div>";
    echo "<h2 class='error'>Erro durante a importação:</h2>";
    echo "<p class='error'>" . $e->getMessage() . "</p>";
}

echo "</div></body></html>";
?>
