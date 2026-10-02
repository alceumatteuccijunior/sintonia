<?php
require_once 'config.php';

try {
    $pdo = getPDOConnection(true);

    // Adiciona a coluna feedback_released na tabela sprints caso não exista
    $pdo->exec("
        ALTER TABLE sprints 
        ADD COLUMN IF NOT EXISTS feedback_released BOOLEAN DEFAULT FALSE 
        AFTER time_limit_minutes;
    ");

    echo "<h2 style='color:green;'>Sucesso!</h2>";
    echo "<p>Coluna 'feedback_released' adicionada à tabela sprints com sucesso.</p>";
    echo "<a href='dashboard.php'>Voltar ao Dashboard</a>";

} catch (Exception $e) {
    echo "<h2 style='color:red;'>Erro:</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
}
