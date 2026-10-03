<?php
session_start();
require_once 'config.php';
require_once 'includes/mailer.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $pdo = getPDOConnection();
        $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            $stmtUpdate = $pdo->prepare("UPDATE users SET password_reset_token = ?, reset_token_expires_at = ? WHERE id = ?");
            $stmtUpdate->execute([$token, $expires, $user['id']]);
            
            // Montar link dinâmico
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'];
            $uri = rtrim(dirname($_SERVER['REQUEST_URI']), '/\\');
            $resetLink = "$protocol://$host$uri/reset_password.php?token=$token";
            
            $emailBody = "Olá <strong>{$user['name']}</strong>,<br><br>Recebemos uma solicitação para redefinir sua senha na plataforma aiS.<br><br>Para criar uma nova senha, clique no botão abaixo:<br><br><a href='$resetLink' style='display:inline-block; padding:12px 24px; background-color:#4f46e5; color:#ffffff; font-weight:bold; text-decoration:none; border-radius:8px;'>Redefinir Minha Senha</a><br><br><br>Se você não solicitou isso, apenas ignore este e-mail. Este link é válido por apenas 1 hora.";
            
            send_system_email($email, "Recuperação de Senha - aiS", $emailBody);
        }
        
        // Sempre mostramos a mensagem positiva por segurança (Security best practice)
        $message = "Se o e-mail informado estiver correto, você receberá um link de redefinição em alguns minutos. (Verifique também o Spam)";
        $messageType = "success";
    } else {
        $message = "Formato de e-mail inválido.";
        $messageType = "error";
    }
}

$currentPage = 'forgot_password';
include 'includes/header.php';
?>

<div class="min-h-screen bg-[#F8FAFC] flex flex-col items-center justify-center p-4 relative overflow-hidden">
    <div class="absolute inset-0 z-0 pointer-events-none">
        <div class="absolute top-0 right-0 w-96 h-96 bg-indigo-500/10 rounded-full blur-[100px] mix-blend-multiply"></div>
        <div class="absolute bottom-0 left-0 w-96 h-96 bg-senai-orange/10 rounded-full blur-[100px] mix-blend-multiply"></div>
    </div>

    <div class="w-full max-w-md bg-white/80 backdrop-blur-xl border border-white p-8 rounded-3xl shadow-xl z-10 relative">
        <div class="text-center mb-6">
            <h2 class="text-3xl font-black text-slate-800 tracking-tight">aiS</h2>
            <p class="text-slate-500 font-medium mt-1">Recuperação de Senha</p>
        </div>

        <?php if ($message): ?>
            <div class="<?= $messageType === 'success' ? 'bg-green-50 text-green-700 border-green-100' : 'bg-red-50 text-red-600 border-red-100' ?> px-4 py-3 rounded-xl text-sm font-medium mb-6 border text-center">
                <?= htmlspecialchars($message) ?>
            </div>
            
            <?php if($messageType === 'success'): ?>
                <a href="index.php" class="w-full flex items-center justify-center gap-2 border border-slate-200 text-slate-600 font-bold py-3.5 px-4 rounded-xl hover:bg-slate-50 transition-colors">
                    <i class="ph-bold ph-arrow-left"></i>
                    Voltar para o Login
                </a>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($messageType !== 'success'): ?>
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">Seu E-mail Cadastrado</label>
                    <input type="email" name="email" required
                        class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all bg-white/50"
                        placeholder="nome@exemplo.com">
                </div>
                
                <button type="submit" class="w-full bg-indigo-600 text-white font-bold py-3.5 px-4 rounded-xl shadow-md hover:bg-indigo-700 hover:shadow-lg transition-all flex items-center justify-center gap-2 mt-2">
                    <i class="ph-bold ph-paper-plane-right"></i>
                    Enviar Link de Recuperação
                </button>
                
                <div class="pt-4 text-center">
                    <a href="index.php" class="text-sm font-bold text-slate-500 hover:text-indigo-600 transition-colors">
                        Voltar para o Login
                    </a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
