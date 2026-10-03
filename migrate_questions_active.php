<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config.php';
$pdo = getPDOConnection();
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM questions LIKE 'is_active'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE questions ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER import_tag");
        echo "Coluna 'is_active' adicionada com sucesso!<br>";
    } else {
        echo "Coluna 'is_active' já existe.<br>";
    }
    echo "Migração concluída!";
} catch (PDOException $e) {
    die("Erro na migração: " . $e->getMessage());
}
