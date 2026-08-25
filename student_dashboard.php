<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: index.php");
    exit;
}
require_once 'config.php';
$pdo = getPDOConnection(true);

$student_id = $_SESSION['user_id'];

// Pega a turma do aluno
$stmtClass = $pdo->prepare("
    SELECT c.*, co.name as course_name 
    FROM class_students cs 
    JOIN classes c ON cs.class_id = c.id 
    JOIN courses co ON c.course_id = co.id 
    WHERE cs.student_id = ? LIMIT 1
");
$stmtClass->execute([$student_id]);
$classe = $stmtClass->fetch();

if (!$classe) {
    header("Location: student_setup.php");
    exit;
}

// Pega sprints ativas da turma dele
$stmtSprints = $pdo->prepare("
    SELECT s.*, 
           (SELECT count(*) FROM sprint_questions WHERE sprint_id = s.id) as total_questions,
           a.started_at,
           a.completed_at
    FROM sprints s
    LEFT JOIN sprint_attempts a ON a.sprint_id = s.id AND a.student_id = ?
    WHERE s.class_id = ? AND s.status = 'active'
    ORDER BY s.created_at DESC
");
$stmtSprints->execute([$student_id, $classe['id']]);
$sprints = $stmtSprints->fetchAll();

include 'includes/header.php';
?>

<div id="app-screen" class="absolute inset-0 z-40 flex flex-col w-full h-full bg-[#F8FAFC]">
    
    <!-- Fundo Vivo com Parallax -->
    <div class="fixed inset-0 w-full h-full overflow-hidden pointer-events-none z-0">
        <div class="absolute top-[-5%] right-[-5%] w-[500px] h-[500px] bg-senai-orange/10 rounded-full mix-blend-multiply filter blur-[80px] opacity-70 animate-blob"></div>
        <div class="absolute bottom-[-5%] left-[-5%] w-[600px] h-[600px] bg-senai-cyan/10 rounded-full mix-blend-multiply filter blur-[90px] opacity-60 animate-blob" style="animation-delay: -5s;"></div>
    </div>

    <!-- Header Simples do Aluno -->
    <header class="h-16 flex items-center justify-between px-6 md:px-8 bg-white/70 backdrop-blur-md border-b border-white shadow-sm relative z-20">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg bg-senai-orange text-white flex items-center justify-center shadow-sm">
                <i class="ph-bold ph-student"></i>
            </div>
            <span class="font-bold text-slate-800 text-lg tracking-tight">Sintonia Aluno</span>
        </div>
        
        <div class="flex items-center gap-4">
            <div class="hidden md:flex items-center gap-2 bg-slate-100 px-3 py-1.5 rounded-lg text-sm">
                <span class="font-bold text-slate-600"><?= htmlspecialchars($classe['name']) ?></span>
                <span class="text-slate-400">•</span>
                <span class="font-medium text-slate-500"><?= htmlspecialchars($classe['course_name']) ?></span>
            </div>
            <a href="auth.php?logout=1" class="w-8 h-8 flex items-center justify-center rounded-full bg-slate-100 text-slate-500 hover:text-red-500 hover:bg-red-50 transition-colors shadow-inner" title="Sair">
                <i class="ph-bold ph-sign-out"></i>
            </a>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-1 overflow-y-auto w-full relative z-10 px-4 md:px-8 py-10">
        <div class="max-w-5xl mx-auto">
            
            <div class="mb-10 animate-slide-up">
                <h1 class="text-3xl md:text-4xl font-bold tracking-tight mb-2 text-slate-800">
                    Olá, <span class="text-senai-orange"><?= htmlspecialchars(explode(' ', trim($_SESSION['user_name']))[0]) ?></span>
                </h1>
                <p class="text-slate-500 font-medium">Aqui estão as avaliações (Sprints) disponíveis para sua turma no momento.</p>
            </div>

            <?php if (empty($sprints)): ?>
                <div class="bg-white/70 backdrop-blur-md border border-white/80 p-10 rounded-3xl shadow-sm text-center">
                    <i class="ph-thin ph-coffee text-5xl text-slate-400 mb-4 opacity-50"></i>
                    <h3 class="text-xl font-bold text-slate-700 mb-2">Tudo tranquilo por aqui!</h3>
                    <p class="text-slate-500">O seu professor não liberou nenhuma Sprint para a turma <strong><?= htmlspecialchars($classe['name']) ?></strong> no momento.</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($sprints as $s): 
                        
                        // Estado da Sprint para este aluno
                        $statusText = "Disponível";
                        $statusColor = "bg-green-100 text-green-700";
                        $btnText = "Iniciar Prova";
                        $btnColor = "bg-senai-orange hover:bg-[#d94b1a] shadow-[0_4px_15px_-3px_rgba(242,92,39,0.4)]";
                        $btnLink = "sprint_solve.php?id=" . $s['id'];
                        $isDisabled = false;

                        if ($s['completed_at']) {
                            $statusText = "Finalizada";
                            $statusColor = "bg-slate-100 text-slate-600";
                            $btnText = "Ver Resultado"; // Futuro
                            $btnColor = "bg-slate-200 text-slate-500";
                            $btnLink = "#";
                            $isDisabled = true;
                        } elseif ($s['started_at']) {
                            // Verifica se o tempo já estourou em relação ao started_at
                            $started = new DateTime($s['started_at']);
                            $now = new DateTime();
                            $diff_minutes = ($now->getTimestamp() - $started->getTimestamp()) / 60;
                            
                            if ($diff_minutes >= $s['time_limit_minutes']) {
                                // O tempo estourou e não foi finalizada ainda. Deverá ser auto-finalizada ao entrar.
                                $statusText = "Tempo Esgotado";
                                $statusColor = "bg-red-100 text-red-700";
                                $btnText = "Revisar/Enviar";
                            } else {
                                $statusText = "Em Andamento";
                                $statusColor = "bg-senai-blue/10 text-senai-blue";
                                $btnText = "Continuar Prova";
                            }
                        }
                    ?>
                        <div class="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm hover:shadow-lg transition-all group flex flex-col h-full relative overflow-hidden">
                            <!-- Decorativo -->
                            <div class="absolute top-0 right-0 w-24 h-24 bg-gradient-to-bl from-slate-50 to-transparent -z-10 rounded-bl-3xl"></div>
                            
                            <div class="flex justify-between items-start mb-4">
                                <div class="w-12 h-12 rounded-xl bg-slate-100 flex items-center justify-center text-slate-500">
                                    <i class="ph-fill ph-file-text text-2xl"></i>
                                </div>
                                <span class="text-[10px] font-bold px-2 py-1 rounded-md uppercase tracking-wider <?= $statusColor ?>">
                                    <?= $statusText ?>
                                </span>
                            </div>
                            
                            <h3 class="text-xl font-bold text-slate-800 mb-1 line-clamp-2">
                                <?= htmlspecialchars($s['name']) ?>
                            </h3>
                            
                            <div class="flex items-center gap-4 text-xs font-semibold text-slate-500 mb-6 mt-2">
                                <span class="flex items-center gap-1.5"><i class="ph-fill ph-clock text-senai-orange"></i> <?= $s['time_limit_minutes'] ?> min</span>
                                <span class="flex items-center gap-1.5"><i class="ph-fill ph-list-numbers text-senai-blue"></i> <?= $s['total_questions'] ?> questões</span>
                            </div>
                            
                            <div class="mt-auto">
                                <a href="<?= $btnLink ?>" class="w-full block text-center <?= $btnColor ?> <?= $isDisabled ? 'pointer-events-none opacity-80 text-slate-500' : 'text-white hover:-translate-y-0.5' ?> px-4 py-3.5 rounded-xl font-bold transition-all">
                                    <?= $btnText ?>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>
    </main>
</div>

<?php include 'includes/footer.php'; ?>
