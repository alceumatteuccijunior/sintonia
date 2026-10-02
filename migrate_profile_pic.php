<?php
require_once 'config.php';
$pdo = getPDOConnection(true);

try {
    $pdo->exec("ALTER TABLE users ADD COLUMN profile_pic VARCHAR(255) NULL AFTER name");
    echo "Coluna profile_pic adicionada com sucesso!";
} catch (PDOException $e) {
    echo "A coluna já existe ou ocorreu um erro: " . $e->getMessage();
}
