<?php
// migrate_questions_tag.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
$pdo = getPDOConnection();

try {
    // Adiciona coluna import_tag na tabela questions se não existir
    $stmt = $pdo->query("SHOW COLUMNS FROM questions LIKE 'import_tag'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE questions ADD COLUMN import_tag VARCHAR(100) NULL DEFAULT NULL AFTER difficulty");
        echo "Coluna 'import_tag' adicionada com sucesso!<br>";
    } else {
        echo "Coluna 'import_tag' já existe.<br>";
    }
    
    echo "Migração concluída!";
} catch (PDOException $e) {
    die("Erro na migração: " . $e->getMessage());
}
