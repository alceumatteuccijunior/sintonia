<?php
session_start();
require_once 'config.php';

// Conexão com o banco
$pdo = getPDOConnection(true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Login Mágico (Cria um admin de teste se não existir)
    if (isset($_POST['magic_login'])) {
        $email = 'professor@sintonia.com';
        $password = '123456';
        
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'teacher')");
            $stmt->execute(['Professor Teste', $email, $hashed]);
            $userId = $pdo->lastInsertId();
        } else {
            $userId = $user['id'];
        }
        
    // Login Mágico (Cria um aluno de teste)
    if (isset($_POST['magic_login_student'])) {
        $email = 'aluno@sintonia.com';
        $password = '123456';
        
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'student')");
            $stmt->execute(['Aluno Teste', $email, $hashed]);
            $userId = $pdo->lastInsertId();
        } else {
            $userId = $user['id'];
        }
        
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_name'] = 'Aluno Teste';
        $_SESSION['user_role'] = 'student';
        
        // Verifica se o aluno já tem uma turma
        $stmtClass = $pdo->prepare("SELECT class_id FROM class_students WHERE student_id = ? LIMIT 1");
        $stmtClass->execute([$userId]);
        if ($stmtClass->fetch()) {
            header("Location: student_dashboard.php");
        } else {
            header("Location: student_setup.php");
        }
        exit;
    }
    
    // Login Normal
    if (isset($_POST['email']) && isset($_POST['password'])) {
        $email = $_POST['email'];
        $password = $_POST['password'];
        
        $stmt = $pdo->prepare("SELECT id, name, password, role FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];
            
            if ($user['role'] === 'student') {
                $stmtClass = $pdo->prepare("SELECT class_id FROM class_students WHERE student_id = ? LIMIT 1");
                $stmtClass->execute([$user['id']]);
                if ($stmtClass->fetch()) {
                    header("Location: student_dashboard.php");
                } else {
                    header("Location: student_setup.php");
                }
            } else {
                header("Location: dashboard.php");
            }
            exit;
        } else {
            header("Location: index.php?error=" . urlencode("Credenciais inválidas."));
            exit;
        }
    }
}

// Tratamento de Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

header("Location: index.php");
exit;
