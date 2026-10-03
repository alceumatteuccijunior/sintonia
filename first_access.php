<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['must_change_password']) || !$_SESSION['must_change_password']) {
    header("Location: index.php");
    exit;
}

require_once 'config.php';
$pdo = getPDOConnection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (strlen($new_password) < 6) {
        $error = "A nova senha deve ter pelo menos 6 caracteres.";
    } elseif ($new_password !== $confirm_password) {
        $error = "As senhas não coincidem. Tente novamente.";
    } else {
        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?");
        $stmt->execute([$hash, $_SESSION['user_id']]);
        
        $_SESSION['must_change_password'] = 0;
        
        // Redirecionamento correto por papel
        if ($_SESSION['user_role'] === 'student') {
            header("Location: student_dashboard.php");
        } elseif ($_SESSION['user_role'] === 'admin') {
            header("Location: admin_dashboard.php");
        } else {
            header("Location: dashboard.php");
        }
        exit;
    }
}
$currentPage = 'first_access';
include 'includes/header.php';
?>

<div class="min-h-screen bg-[#F8FAFC] flex flex-col items-center justify-center p-4 relative overflow-hidden">
    <!-- Fundo abstrato -->
    <div class="absolute inset-0 z-0 pointer-events-none">
        <div class="absolute top-[-20%] left-[-10%] w-[500px] h-[500px] rounded-full bg-indigo-500/10 blur-[80px] mix-blend-multiply"></div>
        <div class="absolute bottom-[-20%] right-[-10%] w-[600px] h-[600px] rounded-full bg-senai-cyan/10 blur-[100px] mix-blend-multiply"></div>
    </div>

    <div class="w-full max-w-md bg-white/80 backdrop-blur-xl border border-white p-8 rounded-3xl shadow-xl z-10 relative">
        <div class="text-center mb-8">
            <div class="w-16 h-16 bg-gradient-to-br from-indigo-500 to-senai-blue text-white rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg">
                <i class="ph-bold ph-lock-key text-3xl"></i>
            </div>
            <h1 class="text-2xl font-bold text-slate-800">Bem-vindo(a) ao iaS!</h1>
            <p class="text-slate-500 text-sm mt-2">Como este é seu primeiro acesso, por motivos de segurança, você precisa cadastrar uma nova senha.</p>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-50 text-red-600 px-4 py-3 rounded-xl text-sm font-bold mb-6 border border-red-100 flex items-center gap-2">
                <i class="ph-bold ph-warning-circle text-lg"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-5">
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
            
            <button type="submit" class="w-full bg-indigo-600 text-white font-bold py-3.5 px-4 rounded-xl shadow-md hover:bg-indigo-700 hover:shadow-lg transition-all flex items-center justify-center gap-2 mt-4">
                <i class="ph-bold ph-check-circle text-lg"></i>
                Salvar Senha e Entrar
            </button>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
