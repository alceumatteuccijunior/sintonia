<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config.php';

try {
    $pdo = getPDOConnection(true);

    // 1. Cria o diretório uploads/questions se não existir
    $uploadDir = __DIR__ . '/uploads/questions';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            echo "<p style='color:orange;'>Aviso: Não foi possível criar automaticamente a pasta {$uploadDir}. Verifique as permissões do servidor.</p>";
        } else {
            echo "<p style='color:green;'>Pasta 'uploads/questions' criada com sucesso.</p>";
        }
    }

    // 2. Adiciona a coluna image_url na tabela questions se não existir
    $stmt = $pdo->query("SHOW COLUMNS FROM questions LIKE 'image_url'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE questions ADD COLUMN image_url VARCHAR(255) NULL DEFAULT NULL AFTER command;");
        echo "<h2 style='color:green;'>Sucesso!</h2>";
        echo "<p>Coluna <b>image_url</b> adicionada à tabela <i>questions</i> com sucesso.</p>";
    } else {
        echo "<h2 style='color:blue;'>Aviso:</h2>";
        echo "<p>A coluna <b>image_url</b> já existe na tabela <i>questions</i>.</p>";
    }

    echo "<br><a href='questions.php' style='display:inline-block; padding:10px 20px; background:#1A428A; color:white; text-decoration:none; border-radius:8px; font-weight:bold;'>Ir para o Banco de Questões</a>";

} catch (Exception $e) {
    echo "<h2 style='color:red;'>Erro na migração:</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
}
