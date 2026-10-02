<?php
// sql/setup.php
// Script para configuração inicial do banco de dados do Sintonia

require_once '../config.php';

echo "<!DOCTYPE html>
<html lang='pt-BR'>
<head>
    <meta charset='UTF-8'>
    <title>Setup - Sintonia</title>
    <style>
        body { font-family: 'Inter', sans-serif; background: #F8FAFC; color: #333; padding: 40px; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        h1 { color: #1A428A; }
        .success { color: #34A853; font-weight: bold; }
        .error { color: #EA4335; font-weight: bold; }
        .log { background: #1e1e1e; color: #00ff00; padding: 15px; border-radius: 5px; font-family: monospace; overflow-x: auto; }
    </style>
</head>
<body>
<div class='container'>
    <h1>Setup do Banco de Dados - Sintonia</h1>
    <div class='log'>";

try {
    // 1. Conecta sem especificar o banco de dados (para poder criá-lo)
    $pdoSetup = getPDOConnection(false);
    
    // 2. Cria o banco se não existir
    echo "Criando banco de dados '" . DB_NAME . "'...<br>";
    $pdoSetup->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "<span class='success'>✔ Banco criado/verificado com sucesso.</span><br><br>";

    // 3. Conecta no banco criado
    $pdoSetup->exec("USE `" . DB_NAME . "`");

    // 4. Estrutura das tabelas
    $queries = [
        "regionals" => "
            CREATE TABLE IF NOT EXISTS regionals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) UNIQUE NOT NULL
            ) ENGINE=InnoDB;
        ",
        "units" => "
            CREATE TABLE IF NOT EXISTS units (
                id INT AUTO_INCREMENT PRIMARY KEY,
                regional_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                FOREIGN KEY (regional_id) REFERENCES regionals(id) ON DELETE CASCADE,
                UNIQUE KEY (regional_id, name)
            ) ENGINE=InnoDB;
        ",
        "users" => "
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                unit_id INT NULL,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                role ENUM('admin', 'teacher', 'student') NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL
            ) ENGINE=InnoDB;
        ",
        "courses" => "
            CREATE TABLE IF NOT EXISTS courses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                description TEXT
            ) ENGINE=InnoDB;
        ",
        "modules" => "
            CREATE TABLE IF NOT EXISTS modules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                course_id INT,
                name VARCHAR(255) NOT NULL,
                FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
            ) ENGINE=InnoDB;
        ",
        "module_capacities" => "
            CREATE TABLE IF NOT EXISTS module_capacities (
                id INT AUTO_INCREMENT PRIMARY KEY,
                module_id INT,
                capacity_code VARCHAR(50) NOT NULL,
                description TEXT NOT NULL,
                FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE,
                UNIQUE KEY (module_id, capacity_code)
            ) ENGINE=InnoDB;
        ",
        "classes" => "
            CREATE TABLE IF NOT EXISTS classes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                unit_id INT NULL,
                course_id INT,
                name VARCHAR(255) NOT NULL,
                year INT,
                semester INT,
                FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE,
                FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
            ) ENGINE=InnoDB;
        ",
        "class_students" => "
            CREATE TABLE IF NOT EXISTS class_students (
                class_id INT,
                student_id INT,
                PRIMARY KEY (class_id, student_id),
                FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
                FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB;
        ",
        "questions" => "
            CREATE TABLE IF NOT EXISTS questions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                module_id INT,
                capacity VARCHAR(50),
                context TEXT,
                command TEXT NOT NULL,
                difficulty ENUM('Fácil', 'Médio', 'Difícil') DEFAULT 'Médio',
                teacher_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE,
                FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB;
        ",
        "question_options" => "
            CREATE TABLE IF NOT EXISTS question_options (
                id INT AUTO_INCREMENT PRIMARY KEY,
                question_id INT,
                text TEXT NOT NULL,
                is_correct BOOLEAN DEFAULT FALSE,
                FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB;
        ",
        "sprints" => "
            CREATE TABLE IF NOT EXISTS sprints (
                id INT AUTO_INCREMENT PRIMARY KEY,
                class_id INT,
                teacher_id INT,
                name VARCHAR(255) NOT NULL,
                status ENUM('draft', 'active', 'completed') DEFAULT 'draft',
                time_limit_minutes INT DEFAULT 0,
                feedback_released BOOLEAN DEFAULT FALSE,
                start_time DATETIME NULL,
                end_time DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
                FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB;
        ",
        "sprint_questions" => "
            CREATE TABLE IF NOT EXISTS sprint_questions (
                sprint_id INT,
                question_id INT,
                order_num INT,
                PRIMARY KEY (sprint_id, question_id),
                FOREIGN KEY (sprint_id) REFERENCES sprints(id) ON DELETE CASCADE,
                FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB;
        ",
        "student_answers" => "
            CREATE TABLE IF NOT EXISTS student_answers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sprint_id INT,
                question_id INT,
                student_id INT,
                selected_option_id INT NULL,
                is_correct BOOLEAN NULL,
                answered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (sprint_id) REFERENCES sprints(id) ON DELETE CASCADE,
                FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
                FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (selected_option_id) REFERENCES question_options(id) ON DELETE CASCADE
            ) ENGINE=InnoDB;
        ",
        "sprint_attempts" => "
            CREATE TABLE IF NOT EXISTS sprint_attempts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sprint_id INT,
                student_id INT,
                started_at DATETIME NOT NULL,
                completed_at DATETIME NULL,
                FOREIGN KEY (sprint_id) REFERENCES sprints(id) ON DELETE CASCADE,
                FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE KEY (sprint_id, student_id)
            ) ENGINE=InnoDB;
        "
    ];

    foreach ($queries as $tableName => $query) {
        echo "Criando tabela '{$tableName}'... ";
        $pdoSetup->exec($query);
        echo "<span class='success'>✔ OK</span><br>";
    }

    echo "</div>";
    echo "<h2 class='success' style='margin-top:20px;'>✔ Tudo pronto!</h2>";
    echo "<p>O banco de dados do Sintonia foi configurado com sucesso. Você já pode deletar este arquivo se estiver em um ambiente de produção.</p>";

} catch (PDOException $e) {
    echo "</div>";
    echo "<h2 class='error'>Erro durante a configuração:</h2>";
    echo "<p class='error'>" . $e->getMessage() . "</p>";
}

echo "</div></body></html>";
?>
