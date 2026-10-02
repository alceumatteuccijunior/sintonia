<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
require_once 'config.php';
$pdo = getPDOConnection(true);

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['avatar'])) {
    $file = $_FILES['avatar'];
    
    // Validar erros de upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        die("Erro no upload do arquivo.");
    }
    
    // Validar tipo de arquivo (Apenas imagens incluindo GIF)
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $file_info = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($file_info, $file['tmp_name']);
    finfo_close($file_info);
    
    if (!in_array($mime_type, $allowed_types)) {
        die("Formato de imagem não permitido. Use JPG, PNG ou GIF.");
    }
    
    // Criar diretório se não existir
    $upload_dir = 'uploads/avatars/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Gerar nome único
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $new_filename = 'avatar_' . $user_id . '_' . time() . '.' . $ext;
    $destination = $upload_dir . $new_filename;
    
    // Mover arquivo
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        // Atualizar banco de dados
        $stmt = $pdo->prepare("UPDATE users SET profile_pic = ? WHERE id = ?");
        $stmt->execute([$new_filename, $user_id]);
        
        // Se for aluno, volta pro dashboard de aluno
        if ($_SESSION['user_role'] === 'student') {
            header("Location: student_dashboard.php");
        } else {
            header("Location: dashboard.php");
        }
        exit;
    } else {
        die("Falha ao salvar a imagem no servidor.");
    }
}
header("Location: student_dashboard.php");
exit;
