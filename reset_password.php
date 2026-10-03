<?php
session_start();
require_once 'config.php';

$token = $_GET['token'] ?? '';
$error = '';
$success = false;

$pdo = getPDOConnection();

// Verifica validade do token
$stmt = $pdo->prepare("SELECT id FROM users WHERE password_reset_token = ? AND reset_token_expires_at > NOW()");
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user || empty($token)) {
    $error = "Este link é inválido ou já expirou. Por favor, solicite a recuperação de senha novamente.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (strlen($new_password) < 6) {
        $error = "A nova senha deve ter pelo menos 6 caracteres.";
    } elseif ($new_password !== $confirm_password) {
        $error = "As senhas não coincidem. Digite com atenção.";
    } else {
        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        
        // Atualiza a senha, limpa o token e marca que o usuário não precisa trocar a senha no primeiro acesso
        $stmtUp = $pdo->prepare("UPDATE users SET password = ?, password_reset_token = NULL, reset_token_expires_at = NULL, must_change_password = 0 WHERE id = ?");
        $stmtUp->execute([$hash, $user['id']]);
        
        $success = true;
    }
}

$currentPage = 'reset_password';
include 'includes/header.php';
?>

<div class="min-h-screen bg-[#F8FAFC] flex flex-col items-center justify-center p-4 relative overflow-hidden">
    <div class="w-full max-w-md bg-white/80 backdrop-blur-xl border border-white p-8 rounded-3xl shadow-xl z-10 relative">
        
        <div class="text-center mb-6">
            <div class="w-16 h-16 bg-gradient-to-br from-senai-cyan to-senai-blue text-white rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg">
                <i class="ph-bold <?= $success ? 'ph-check-circle' : 'ph-key' ?> text-3xl"></i>
            </div>
            <h2 class="text-2xl font-black text-slate-800 tracking-tight">Criar Nova Senha</h2>
        </div>

        <?php if ($success): ?>
            <div class="bg-green-50 text-green-700 px-4 py-4 rounded-xl text-sm font-medium mb-6 border border-green-100 text-center">
                Sua senha foi redefinida com sucesso!<br>Você já pode acessar a plataforma.
            </div>
            <a href="index.php" class="w-full flex items-center justify-center gap-2 bg-indigo-600 text-white font-bold py-3.5 px-4 rounded-xl hover:bg-indigo-700 transition-colors shadow-md">
                Ir para o Login
            </a>
            
        <?php elseif ($error && (!$user || empty($token))): ?>
            <div class="bg-red-50 text-red-600 px-4 py-4 rounded-xl text-sm font-medium mb-6 border border-red-100 text-center">
                <?= htmlspecialchars($error) ?>
            </div>
            <a href="forgot_password.php" class="w-full flex items-center justify-center gap-2 border border-slate-200 text-slate-600 font-bold py-3.5 px-4 rounded-xl hover:bg-slate-50 transition-colors">
                <i class="ph-bold ph-arrow-left"></i>
                Solicitar novo link
            </a>
            
        <?php else: ?>
            <?php if ($error): ?>
                <div class="bg-red-50 text-red-600 px-4 py-3 rounded-xl text-sm font-bold mb-6 border border-red-100 text-center">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">Nova Senha</label>
                    <input type="password" name="new_password" required minlength="6"
                        class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all bg-white/50"
                        placeholder="Mínimo 6 caracteres">
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">Confirmar Nova Senha</label>
                    <input type="password" name="confirm_password" required minlength="6"
                        class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all bg-white/50"
                        placeholder="Repita a senha">
                </div>
                
                <button type="submit" class="w-full bg-indigo-600 text-white font-bold py-3.5 px-4 rounded-xl shadow-md hover:bg-indigo-700 hover:shadow-lg transition-all mt-2">
                    Salvar Nova Senha
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
