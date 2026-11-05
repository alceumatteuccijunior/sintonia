<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config.php';

try {
    $pdo = getPDOConnection(true);

    // 1. Garante que status suporta 'scheduled'
    $pdo->exec("ALTER TABLE sprints MODIFY COLUMN status ENUM('draft', 'active', 'completed', 'scheduled') DEFAULT 'draft';");

    // 2. Garante start_time
    $stmt = $pdo->query("SHOW COLUMNS FROM sprints LIKE 'start_time'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE sprints ADD COLUMN start_time DATETIME NULL DEFAULT NULL AFTER feedback_released;");
        echo "Coluna 'start_time' adicionada com sucesso!<br>";
    } else {
        echo "Coluna 'start_time' já existe.<br>";
    }

    // 3. Garante end_time
    $stmt = $pdo->query("SHOW COLUMNS FROM sprints LIKE 'end_time'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE sprints ADD COLUMN end_time DATETIME NULL DEFAULT NULL AFTER start_time;");
        echo "Coluna 'end_time' adicionada com sucesso!<br>";
    } else {
        echo "Coluna 'end_time' já existe.<br>";
    }

    echo "<h2 style='color:green;'>Migração da Agenda e Agendamento concluída com sucesso!</h2>";
    echo "<a href='agenda.php'>Ir para a Agenda</a>";

} catch (Exception $e) {
    echo "<h2 style='color:red;'>Erro na migração:</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
}
