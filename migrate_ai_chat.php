<?php
require 'config.php';

try {
    // Tabela de Sessões de Chat
    $sql1 = "
    CREATE TABLE IF NOT EXISTS ai_chat_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($sql1);

    // Tabela de Mensagens do Chat
    $sql2 = "
    CREATE TABLE IF NOT EXISTS ai_chat_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        role ENUM('user', 'assistant', 'system') NOT NULL,
        content LONGTEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (session_id) REFERENCES ai_chat_sessions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($sql2);

    echo "<div style='font-family: sans-serif; padding: 20px; background: #e0f2fe; color: #0284c7; border-radius: 8px; margin: 20px; border: 1px solid #bae6fd;'>";
    echo "<h2>Tabelas do Tutor IA criadas com sucesso!</h2>";
    echo "<p>Agora você pode usar o chat inteligente.</p>";
    echo "<a href='dashboard.php' style='display: inline-block; margin-top: 10px; padding: 10px 20px; background: #0284c7; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;'>Voltar ao Dashboard</a>";
    echo "</div>";

} catch (PDOException $e) {
    echo "<div style='color: red; font-family: sans-serif; padding: 20px;'>Erro ao criar tabelas: " . $e->getMessage() . "</div>";
}
