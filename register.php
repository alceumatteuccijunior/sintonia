<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
require_once 'config.php';
$pdo = getPDOConnection(true);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $unit_id = $_POST['unit_id'] ?? null;
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if ($unit_id && $name && $email && $password) {
        // Verificar se e-mail já existe
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = "Este e-mail já está cadastrado.";
        } else {
            try {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $insert = $pdo->prepare("INSERT INTO users (unit_id, name, email, password, role) VALUES (?, ?, ?, ?, 'student')");
                $insert->execute([$unit_id, $name, $email, $hashed]);
                
                $userId = $pdo->lastInsertId();
                $_SESSION['user_id'] = $userId;
                $_SESSION['user_name'] = $name;
                $_SESSION['user_role'] = 'student';
                
                header("Location: student_setup.php");
                exit;
            } catch (Exception $e) {
                $error = "Erro ao cadastrar: " . $e->getMessage();
            }
        }
    } else {
        $error = "Preencha todos os campos.";
    }
}

include 'includes/header.php';
?>

<div id="register-screen" class="absolute inset-0 z-50 flex items-center justify-center bg-slate-50 p-4">
    <div class="max-w-md w-full bg-white rounded-3xl p-8 shadow-xl shadow-slate-200/50 border border-slate-100">
        
        <div class="text-center mb-8">
            <div class="w-16 h-16 bg-senai-orange/10 text-senai-orange rounded-2xl flex items-center justify-center mx-auto mb-4">
                <i class="ph-fill ph-student text-3xl"></i>
            </div>
            <h1 class="text-2xl font-bold text-slate-800 tracking-tight">Cadastro de Aluno</h1>
            <p class="text-slate-500 font-medium text-sm mt-2">Crie sua conta no aiS.</p>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-50 text-red-500 p-4 rounded-xl text-sm font-medium mb-6 flex items-center gap-2">
                <i class="ph-bold ph-warning-circle"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Aviso E-mail SENAI -->
        <div class="bg-blue-50/50 border border-blue-100 p-4 rounded-xl mb-6 flex items-start gap-3">
            <i class="ph-fill ph-info text-blue-500 text-xl flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-blue-800 font-medium leading-relaxed">
                Recomendamos utilizar seu e-mail institucional (terminado em <strong class="font-bold">@aluno.senai.br</strong> ou <strong class="font-bold">@senai.br</strong>) para facilitar sua identificação pelo professor.
            </p>
        </div>

        <form action="register.php" method="POST" class="space-y-4">
            <div>
                <label class="block text-sm font-bold text-slate-700 mb-1">Nome Completo</label>
                <input type="text" name="name" required class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-senai-orange/20 focus:border-senai-orange transition-all placeholder:text-slate-400" placeholder="Seu nome completo">
            </div>
            <div>
                <label class="block text-sm font-bold text-slate-700 mb-1">Unidade Escolar</label>
                <select name="unit_id" required class="search-select w-full px-4 py-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-senai-orange/20 focus:border-senai-orange transition-all bg-white text-slate-700">
                    <option value="" disabled selected>Selecione sua Unidade</option>
                    <?php
                    $stmtUnits = $pdo->query("
                        SELECT u.id, u.name as unit_name, r.name as regional_name 
                        FROM units u 
                        JOIN regionals r ON u.regional_id = r.id 
                        ORDER BY r.name ASC, u.name ASC
                    ");
                    $current_regional = '';
                    while ($unit = $stmtUnits->fetch()) {
                        if ($current_regional !== $unit['regional_name']) {
                            if ($current_regional !== '') echo "</optgroup>";
                            $current_regional = $unit['regional_name'];
                            echo "<optgroup label='" . htmlspecialchars($current_regional) . "'>";
                        }
                        echo "<option value='{$unit['id']}'>" . htmlspecialchars($unit['unit_name']) . "</option>";
                    }
                    if ($current_regional !== '') echo "</optgroup>";
                    ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-bold text-slate-700 mb-1">E-mail</label>
                <input type="email" name="email" required class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-senai-orange/20 focus:border-senai-orange transition-all placeholder:text-slate-400" placeholder="nome@aluno.senai.br">
            </div>
            <div>
                <label class="block text-sm font-bold text-slate-700 mb-1">Senha</label>
                <input type="password" name="password" required class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-senai-orange/20 focus:border-senai-orange transition-all placeholder:text-slate-400" placeholder="Crie uma senha segura">
            </div>
            
            <div class="pt-4">
                <button type="submit" class="w-full bg-senai-orange text-white font-bold py-3.5 px-4 rounded-xl shadow-[0_4px_15px_-3px_rgba(242,92,39,0.4)] hover:shadow-[0_8px_20px_-3px_rgba(242,92,39,0.5)] hover:-translate-y-0.5 transition-all duration-300">
                    Criar Minha Conta
                </button>
            </div>
        </form>

        <div class="mt-8 text-center">
            <a href="index.php" class="text-sm font-bold text-slate-500 hover:text-senai-blue transition-colors">Já tem conta? Fazer Login</a>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
