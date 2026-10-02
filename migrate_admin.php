<?php
require_once 'config.php';

try {
    $pdo = getPDOConnection(true);

    // 1. Modifica a coluna role para aceitar admin
    $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'teacher', 'student') NOT NULL;");

    // 2. Verifica se o admin já existe
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = 'admin@senai.br'");
    $stmt->execute();
    
    if (!$stmt->fetch()) {
        // 2.5 Cria ou recupera a Regional/Unidade "Sede Central"
        $stmtReg = $pdo->prepare("SELECT id FROM regionals WHERE name = 'Administração Global'");
        $stmtReg->execute();
        $regId = $stmtReg->fetchColumn();
        if (!$regId) {
            $pdo->exec("INSERT INTO regionals (name) VALUES ('Administração Global')");
            $regId = $pdo->lastInsertId();
        }

        $stmtUnit = $pdo->prepare("SELECT id FROM units WHERE name = 'Sede Central' AND regional_id = ?");
        $stmtUnit->execute([$regId]);
        $unitId = $stmtUnit->fetchColumn();
        if (!$unitId) {
            $pdo->prepare("INSERT INTO units (regional_id, name) VALUES (?, 'Sede Central')")->execute([$regId]);
            $unitId = $pdo->lastInsertId();
        }

        // Cria o admin
        $password = password_hash('marciaalinemarcio', PASSWORD_DEFAULT);
        $insert = $pdo->prepare("INSERT INTO users (unit_id, name, email, password, role) VALUES (?, 'Administrador Principal', 'admin@senai.br', ?, 'admin')");
        $insert->execute([$unitId, $password]);
        
        echo "<h2 style='color:green;'>Sucesso!</h2>";
        echo "<p>Usuário Administrador (admin@senai.br) criado com a senha solicitada.</p>";
    } else {
        echo "<h2 style='color:blue;'>Aviso:</h2>";
        echo "<p>O administrador admin@senai.br já existe no banco de dados.</p>";
    }

    echo "<br><a href='index.php'>Ir para a tela de Login</a>";

} catch (Exception $e) {
    echo "<h2 style='color:red;'>Erro:</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
}
