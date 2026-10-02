<?php
session_start();
// Opcional: Adicionar trava de segurança para garantir que apenas admins logados possam rodar isso
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    die("Acesso negado. Apenas administradores logados podem executar o reset da base de dados.");
}

require_once '../config.php';

echo "<div style='font-family: sans-serif; max-width: 800px; margin: 40px auto; line-height: 1.6;'>";
echo "<h1 style='color: #0f172a;'>Reset do Banco de Dados</h1>";

try {
    $pdo = getPDOConnection(true);
    
    // Desabilitar verificação de chaves estrangeiras para permitir o TRUNCATE
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    
    // 1. Limpar tabelas de transação (Provas e Respostas)
    $pdo->exec("TRUNCATE TABLE student_answers");
    $pdo->exec("TRUNCATE TABLE sprint_attempts");
    $pdo->exec("TRUNCATE TABLE sprint_questions");
    $pdo->exec("TRUNCATE TABLE sprints");
    echo "<p>✅ Provas, Gabaritos e Históricos de alunos apagados.</p>";

    // 2. Limpar turmas e alunos vinculados
    $pdo->exec("TRUNCATE TABLE class_students");
    $pdo->exec("TRUNCATE TABLE classes");
    echo "<p>✅ Turmas e matrículas apagadas.</p>";

    // 3. Limpar Banco de Questões
    $pdo->exec("TRUNCATE TABLE question_options");
    $pdo->exec("TRUNCATE TABLE questions");
    $pdo->exec("TRUNCATE TABLE modules");
    $pdo->exec("TRUNCATE TABLE courses");
    echo "<p>✅ Cursos, Módulos e Banco de Questões apagados.</p>";

    // 4. Limpar Regionais e Unidades
    // Nota: Como o FK no users é ON DELETE SET NULL, o unit_id dos admins virará NULL.
    $pdo->exec("TRUNCATE TABLE units");
    $pdo->exec("TRUNCATE TABLE regionals");
    echo "<p>✅ Catálogo de Escolas (Regionais e Unidades) apagado.</p>";

    // 5. Excluir usuários que NÃO SÃO administradores
    $stmtUsers = $pdo->exec("DELETE FROM users WHERE role != 'admin'");
    echo "<p>✅ $stmtUsers Professores e Alunos excluídos.</p>";

    // Reabilitar verificação de chaves estrangeiras
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    // Opcional: Re-criar a Sede Central para o Admin não ficar sem Unidade
    $pdo->exec("INSERT INTO regionals (id, name) VALUES (1, 'Administração Global')");
    $pdo->exec("INSERT INTO units (id, regional_id, name) VALUES (1, 1, 'Sede Central')");
    $pdo->exec("UPDATE users SET unit_id = 1 WHERE role = 'admin'");
    echo "<p>✅ Repositório Sede Central restaurado para os administradores.</p>";

    echo "<h2 style='color: #16a34a;'>🎉 Banco zerado com sucesso!</h2>";
    echo "<p>Seu sistema está totalmente limpo, mantendo apenas as contas de Administradores ativas.</p>";
    echo "<a href='../admin_dashboard.php' style='display: inline-block; margin-top: 10px; padding: 10px 20px; background: #0f172a; color: #fff; text-decoration: none; border-radius: 8px;'>Voltar ao Sistema</a>";

} catch (Exception $e) {
    // Garantir que as FKs voltem a ser checadas mesmo se der erro
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    echo "<h2 style='color: #dc2626;'>Erro Crítico no Reset:</h2>";
    echo "<pre style='background: #fef2f2; border: 1px solid #fca5a5; padding: 15px; border-radius: 8px; color: #991b1b;'>" . $e->getMessage() . "</pre>";
}

echo "</div>";
