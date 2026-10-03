<?php
// migrate_auth.php
require 'config.php';

try {
    // 1. Adicionar flag de primeiro acesso
    $pdo->exec("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) DEFAULT 0;");
    
    // 2. Adicionar tokens para recuperação de senha
    $pdo->exec("ALTER TABLE users ADD COLUMN password_reset_token VARCHAR(255) DEFAULT NULL;");
    $pdo->exec("ALTER TABLE users ADD COLUMN reset_token_expires_at DATETIME DEFAULT NULL;");
    
    echo "<div style='padding:20px; font-family:sans-serif; background:#dcfce7; color:#166534;'>
            <strong>Sucesso:</strong> Colunas de autenticação (must_change_password, password_reset_token, etc) criadas com sucesso na tabela users!
          </div>";
} catch (PDOException $e) {
    // Pode falhar se já existirem, o que é seguro ignorar
    echo "<div style='padding:20px; font-family:sans-serif; color:red;'>
            <strong>Aviso:</strong> " . $e->getMessage() . "
          </div>";
}
?>
