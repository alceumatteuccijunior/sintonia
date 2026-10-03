<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config.php';
$pdo = getPDOConnection();
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM sprints LIKE 'time_min_minutes'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE sprints ADD COLUMN time_min_minutes INT NULL DEFAULT NULL AFTER time_limit_minutes");
        echo "Coluna 'time_min_minutes' adicionada com sucesso!<br>";
    } else {
        echo "Coluna 'time_min_minutes' já existe.<br>";
    }
    echo "Migração concluída!";
} catch (PDOException $e) {
    die("Erro na migração: " . $e->getMessage());
}
