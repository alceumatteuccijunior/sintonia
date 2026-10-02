<?php
session_start();
require_once 'config.php';

// Conexão com o banco
$pdo = getPDOConnection(true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Login Normal
    if (isset($_POST['email']) && isset($_POST['password'])) {
        $email = $_POST['email'];
        $password = $_POST['password'];
        
        $stmt = $pdo->prepare("SELECT id, name, password, role, unit_id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['unit_id'] = $user['unit_id'];
            
            if ($user['role'] === 'student') {
                $stmtClass = $pdo->prepare("SELECT class_id FROM class_students WHERE student_id = ? LIMIT 1");
                $stmtClass->execute([$user['id']]);
                if ($stmtClass->fetch()) {
                    header("Location: student_dashboard.php");
                } else {
                    header("Location: student_setup.php");
                }
            } elseif ($user['role'] === 'admin') {
                header("Location: admin_dashboard.php");
            } else {
                header("Location: dashboard.php");
            }
            exit;
        } else {
            header("Location: index.php?error=" . urlencode("E-mail ou senha inválidos."));
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
