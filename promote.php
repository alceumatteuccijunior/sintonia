<?php
session_start();
require_once 'config.php';
$pdo = getPDOConnection(true);

if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $_SESSION['user_role'] = 'admin';
    echo "<h1>Sucesso!</h1><p>Seu usuário agora é um Administrador.</p>";
    echo "<a href='dashboard.php'>Voltar para o Dashboard</a>";
} else {
    echo "<h1>Erro</h1><p>Você precisa estar logado para se promover a Admin.</p>";
}
// APAGUE ESTE ARQUIVO APÓS O USO!
?>
