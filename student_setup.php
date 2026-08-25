<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: index.php");
    exit;
}
require_once 'config.php';
$pdo = getPDOConnection(true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $class_id = $_POST['class_id'] ?? '';
    if ($class_id) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO class_students (class_id, student_id) VALUES (?, ?)");
        $stmt->execute([$class_id, $_SESSION['user_id']]);
        header("Location: student_dashboard.php");
        exit;
    }
}

// Verifica se já tem turma
$stmtCheck = $pdo->prepare("SELECT class_id FROM class_students WHERE student_id = ?");
$stmtCheck->execute([$_SESSION['user_id']]);
if ($stmtCheck->fetch()) {
    header("Location: student_dashboard.php");
    exit;
}

$stmtClasses = $pdo->query("
    SELECT c.*, co.name as course_name 
    FROM classes c 
    JOIN courses co ON c.course_id = co.id 
    ORDER BY c.year DESC, c.semester DESC, c.name ASC
");
$classes = $stmtClasses->fetchAll();

include 'includes/header.php';
?>

<div id="login-screen" class="absolute inset-0 z-50 flex bg-[#F8FAFC] items-center justify-center p-4">
    <!-- Fundo Vivo com Parallax -->
    <div class="fixed inset-0 w-full h-full overflow-hidden pointer-events-none z-0">
        <div class="absolute top-[-5%] left-[-5%] w-[500px] h-[500px] bg-senai-orange/15 rounded-full mix-blend-multiply filter blur-[80px] opacity-70 animate-blob"></div>
        <div class="absolute bottom-[-5%] right-[-5%] w-[600px] h-[600px] bg-senai-cyan/15 rounded-full mix-blend-multiply filter blur-[90px] opacity-60 animate-blob" style="animation-delay: -5s;"></div>
    </div>

    <div class="w-full max-w-md relative z-10">
        <div class="bg-white/80 backdrop-blur-xl border border-white p-8 rounded-3xl shadow-lg animate-slide-up text-center">
            
            <div class="w-16 h-16 bg-senai-orange/10 text-senai-orange rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="ph-fill ph-student text-3xl"></i>
            </div>

            <h1 class="text-2xl font-bold text-slate-800 mb-2">Bem-vindo, <?= htmlspecialchars(explode(' ', $_SESSION['user_name'])[0]) ?>!</h1>
            <p class="text-slate-500 font-medium text-sm mb-8">Para começar, selecione em qual turma você está matriculado.</p>

            <?php if (empty($classes)): ?>
                <div class="bg-red-50 text-red-500 p-4 rounded-xl text-sm font-medium mb-6">
                    Nenhuma turma cadastrada no sistema. Fale com seu professor.
                </div>
                <a href="auth.php?logout=1" class="text-senai-blue font-bold text-sm hover:underline">Sair</a>
            <?php else: ?>
                <form method="POST" action="student_setup.php" class="space-y-6 text-left">
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-2">Sua Turma</label>
                        <select name="class_id" required class="w-full px-5 py-3.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-senai-blue/20 focus:border-senai-blue transition-all bg-white/50 text-slate-800 font-medium">
                            <option value="" disabled selected>Escolha sua turma...</option>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?= $c['id'] ?>">
                                    <?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['course_name']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="w-full bg-senai-orange text-white px-8 py-3.5 rounded-xl font-bold shadow-[0_4px_15px_-3px_rgba(242,92,39,0.4)] hover:shadow-[0_8px_20px_-3px_rgba(242,92,39,0.5)] hover:-translate-y-0.5 transition-all">
                        Entrar na Turma
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
