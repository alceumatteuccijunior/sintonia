<?php
require_once '../config.php';

echo "<div style='font-family: sans-serif; max-width: 800px; margin: 40px auto; line-height: 1.6;'>";
echo "<h1 style='color: #0f172a;'>Migração V1 para V2 (Multi-Tenant)</h1>";

try {
    $pdo = getPDOConnection(true);
    echo "<p>Conectado ao banco de dados com sucesso.</p>";

    // 1. Criar as novas tabelas (Regionals e Units) se não existirem
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS regionals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) UNIQUE NOT NULL
        ) ENGINE=InnoDB;
    ");
    echo "<p>✅ Tabela <b>regionals</b> verificada/criada.</p>";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS units (
            id INT AUTO_INCREMENT PRIMARY KEY,
            regional_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            FOREIGN KEY (regional_id) REFERENCES regionals(id) ON DELETE CASCADE,
            UNIQUE KEY (regional_id, name)
        ) ENGINE=InnoDB;
    ");
    echo "<p>✅ Tabela <b>units</b> verificada/criada.</p>";

    // 2. Criar Regional e Unidade Padrão para acomodar os dados antigos
    $stmtReg = $pdo->query("SELECT id FROM regionals WHERE name = 'Administração Global'");
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
    echo "<p>✅ Repositório padrão <b>'Administração Global > Sede Central'</b> criado (ID da Unidade: $unitId).</p>";

    // 3. Adicionar colunas e FKs na tabela `users`
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'unit_id'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN unit_id INT NULL AFTER id");
        $pdo->exec("ALTER TABLE users ADD CONSTRAINT fk_users_unit FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL");
        echo "<p>✅ Coluna <b>unit_id</b> e Constraint adicionadas na tabela <i>users</i>.</p>";
    } else {
        echo "<p>⚠️ Coluna <b>unit_id</b> já existe na tabela <i>users</i>.</p>";
    }

    // 4. Adicionar colunas e FKs na tabela `classes`
    $stmt = $pdo->query("SHOW COLUMNS FROM classes LIKE 'unit_id'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE classes ADD COLUMN unit_id INT NULL AFTER id");
        $pdo->exec("ALTER TABLE classes ADD CONSTRAINT fk_classes_unit FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE");
        echo "<p>✅ Coluna <b>unit_id</b> e Constraint adicionadas na tabela <i>classes</i>.</p>";
    } else {
        echo "<p>⚠️ Coluna <b>unit_id</b> já existe na tabela <i>classes</i>.</p>";
    }

    // 5. Migrar os dados órfãos para a Sede Central
    $stmtUsers = $pdo->prepare("UPDATE users SET unit_id = ? WHERE unit_id IS NULL");
    $stmtUsers->execute([$unitId]);
    $affectedUsers = $stmtUsers->rowCount();
    echo "<p>✅ <b>$affectedUsers</b> usuários antigos foram migrados para a Sede Central.</p>";

    $stmtClasses = $pdo->prepare("UPDATE classes SET unit_id = ? WHERE unit_id IS NULL");
    $stmtClasses->execute([$unitId]);
    $affectedClasses = $stmtClasses->rowCount();
    echo "<p>✅ <b>$affectedClasses</b> turmas antigas foram migradas para a Sede Central.</p>";

    echo "<h2 style='color: #16a34a;'>🎉 Migração concluída com sucesso!</h2>";
    echo "<p>O seu sistema V1 acaba de ser promovido para V2 (Multi-Tenant) sem perda de dados.</p>";
    echo "<a href='../index.php' style='display: inline-block; margin-top: 10px; padding: 10px 20px; background: #0f172a; color: #fff; text-decoration: none; border-radius: 8px;'>Ir para o Sistema</a>";

} catch (Exception $e) {
    echo "<h2 style='color: #dc2626;'>Erro Crítico na Migração:</h2>";
    echo "<pre style='background: #fef2f2; border: 1px solid #fca5a5; padding: 15px; border-radius: 8px; color: #991b1b;'>" . $e->getMessage() . "</pre>";
}

echo "</div>";
