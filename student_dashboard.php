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
    WHERE s.class_id = ? AND s.status = 'active' AND s.teacher_id IS NOT NULL
    ORDER BY s.created_at DESC
");
$stmtSprints->execute([$student_id, $classe['id']]);
$sprints = $stmtSprints->fetchAll();

// Ranking Global do Aluno na Turma
$stmtRank = $pdo->prepare("
    SELECT student_id, 
           COUNT(id) as total_answered,
           SUM(IF(is_correct = 1, 1, 0)) as total_correct
    FROM student_answers
    WHERE student_id IN (SELECT student_id FROM class_students WHERE class_id = ?)
    GROUP BY student_id
    ORDER BY total_correct DESC, total_answered DESC
");
$stmtRank->execute([$classe['id']]);
$classRanking = $stmtRank->fetchAll();

$myRank = 0;
$myScore = 0;
foreach ($classRanking as $index => $row) {
    if ($row['student_id'] == $student_id) {
        $myRank = $index + 1;
        $myScore = $row['total_correct'];
        break;
    }
}

// Gamification Logic
$xp = $myScore * 50;
$level_index = floor($xp / 500) + 1;
$levels = [1 => 'Novato', 2 => 'Aprendiz', 3 => 'Especialista', 4 => 'Veterano', 5 => 'Mestre SAEP', 6 => 'Grão-Mestre'];
$current_level_name = $levels[min($level_index, 6)] ?? 'Grão-Mestre';
$next_level_xp = $level_index * 500;
$progress_pct = ($xp % 500) / 500 * 100;
if ($level_index > 6) $progress_pct = 100;

// Buscar foto de perfil
$stmtPic = $pdo->prepare("SELECT profile_pic FROM users WHERE id = ?");
$stmtPic->execute([$student_id]);
$my_profile_pic = $stmtPic->fetchColumn();

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
            <a href="student_arena.php" class="flex items-center gap-2 bg-senai-orange/10 text-senai-orange px-4 py-2 rounded-xl font-bold hover:bg-senai-orange hover:text-white transition-colors">
                <i class="ph-bold ph-sword"></i> <span class="hidden md:inline">Arena</span>
            </a>
            <a href="auth.php?logout=1" class="w-8 h-8 flex items-center justify-center rounded-full bg-slate-100 text-slate-500 hover:text-red-500 hover:bg-red-50 transition-colors shadow-inner" title="Sair">
                <i class="ph-bold ph-sign-out"></i>
            </a>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-1 overflow-y-auto w-full relative z-10 px-4 md:px-8 py-10">
        <div class="max-w-5xl mx-auto">
            <!-- PERFIL GAMIFICADO (SINTONIA) -->
            <div class="mb-10 animate-slide-up">
                <div class="bg-white/80 backdrop-blur-md border border-white/80 rounded-3xl p-6 md:p-8 shadow-sm flex flex-col md:flex-row items-center md:items-start justify-between gap-8 relative overflow-hidden">
                    <!-- Fundo Decorativo -->
                    <div class="absolute top-0 right-0 w-64 h-64 bg-gradient-to-bl from-senai-cyan/10 to-transparent rounded-bl-full pointer-events-none -z-10"></div>
                    
                    <div class="flex flex-col md:flex-row items-center md:items-start gap-6 text-center md:text-left w-full md:w-auto">
                        <!-- Avatar Holográfico com Upload -->
                        <div class="relative group cursor-pointer" onclick="document.getElementById('avatar-upload').click()">
                            <div class="w-24 h-24 rounded-2xl bg-gradient-to-br from-senai-blue to-senai-cyan p-1 shadow-lg shadow-senai-blue/30 transform group-hover:scale-105 transition-transform duration-500 relative overflow-hidden">
                                <?php if ($my_profile_pic): ?>
                                    <img src="uploads/avatars/<?= htmlspecialchars($my_profile_pic) ?>" alt="Avatar" class="w-full h-full object-cover rounded-[14px]">
                                <?php else: ?>
                                    <div class="w-full h-full bg-slate-900 rounded-[14px] flex items-center justify-center text-white text-3xl font-black relative overflow-hidden">
                                        <?= htmlspecialchars(substr(trim($_SESSION['user_name']), 0, 1)) ?>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Overlay de Hover para trocar a foto -->
                                <div class="absolute inset-1 rounded-[14px] bg-black/60 opacity-0 group-hover:opacity-100 transition-opacity flex flex-col items-center justify-center text-white z-20">
                                    <i class="ph-bold ph-camera text-xl mb-1"></i>
                                    <span class="text-[9px] font-bold uppercase tracking-wider">Alterar</span>
                                </div>
                            </div>
                            
                            <!-- Formulário oculto para upload automático -->
                            <form id="avatar-form" action="upload_avatar.php" method="POST" enctype="multipart/form-data" class="hidden">
                                <input type="file" id="avatar-upload" name="avatar" accept="image/*" onchange="document.getElementById('avatar-form').submit();">
                            </form>

                            <!-- Badge de Nível Flutuante -->
                            <div class="absolute -bottom-3 -right-3 bg-senai-orange text-white text-xs font-black px-3 py-1 rounded-lg border-2 border-white shadow-sm transform rotate-3">
                                LVL <?= $level_index ?>
                            </div>
                        </div>

                        <!-- Info do Aluno -->
                        <div class="pt-2">
                            <h1 class="text-3xl font-black text-slate-800 tracking-tight leading-none mb-2">
                                <?= htmlspecialchars(explode(' ', trim($_SESSION['user_name']))[0]) ?>
                            </h1>
                            <p class="text-senai-blue font-bold uppercase tracking-widest text-xs mb-4 flex items-center justify-center md:justify-start gap-1.5">
                                <i class="ph-fill ph-star"></i> <?= $current_level_name ?>
                            </p>
                            
                            <!-- Barra de XP -->
                            <div class="w-full md:w-64">
                                <div class="flex justify-between text-[10px] font-bold text-slate-400 mb-1">
                                    <span>XP Atual: <span class="text-senai-cyan"><?= number_format($xp, 0, ',', '.') ?></span></span>
                                    <span>Próximo: <?= number_format($next_level_xp, 0, ',', '.') ?></span>
                                </div>
                                <div class="w-full h-2 bg-slate-100 rounded-full overflow-hidden shadow-inner">
                                    <div class="h-full bg-gradient-to-r from-senai-cyan to-senai-blue rounded-full relative" style="width: <?= $progress_pct ?>%">
                                        <div class="absolute top-0 right-0 bottom-0 w-4 bg-white/30 skew-x-[-20deg] animate-[shimmer-fast_2s_infinite]"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Cards de Estatísticas -->
                    <div class="flex items-center gap-3 w-full md:w-auto">
                        <div class="flex-1 md:w-32 bg-slate-50 border border-slate-100 rounded-2xl p-4 text-center shadow-sm">
                            <i class="ph-fill ph-target text-2xl text-senai-cyan mb-1"></i>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Acertos Totais</p>
                            <p class="text-2xl font-black text-slate-700"><?= $myScore ?></p>
                        </div>
                        
                        <?php if ($myRank > 0): 
                            $medalColor = "text-slate-400";
                            $icon = "ph-medal";
                            if ($myRank === 1) { $medalColor = "text-yellow-500"; $icon = "ph-trophy"; }
                            else if ($myRank === 2) { $medalColor = "text-slate-400"; $icon = "ph-medal"; }
                            else if ($myRank === 3) { $medalColor = "text-amber-600"; $icon = "ph-medal"; }
                        ?>
                        <a href="student_ranking.php" class="flex-1 md:w-32 bg-slate-50 border border-slate-100 rounded-2xl p-4 text-center shadow-sm relative overflow-hidden block group hover:bg-slate-100 hover:scale-105 hover:shadow-md transition-all cursor-pointer">
                            <?php if ($myRank <= 3): ?>
                                <div class="absolute -right-4 -top-4 w-12 h-12 bg-yellow-100 rounded-full blur-xl group-hover:scale-150 transition-transform"></div>
                            <?php endif; ?>
                            <i class="ph-fill <?= $icon ?> text-2xl <?= $medalColor ?> mb-1 relative z-10 group-hover:scale-110 transition-transform"></i>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider relative z-10">Sua Posição</p>
                            <p class="text-2xl font-black text-slate-700 relative z-10"><?= $myRank ?>º</p>
                            <div class="absolute inset-0 bg-gradient-to-t from-black/5 to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="mb-6 flex items-center justify-between">
                <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                    <i class="ph-bold ph-sword text-senai-orange"></i> Avaliações Disponíveis
                </h2>
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
                            if ($s['feedback_released']) {
                                $statusText = "Corrigida";
                                $statusColor = "bg-green-100 text-green-700";
                                $btnText = "Ver Meu Boletim"; 
                                $btnColor = "bg-indigo-500 text-white hover:bg-indigo-600 shadow-lg shadow-indigo-500/20";
                                $btnLink = "sprint_review_student.php?id=" . $s['id'];
                                $isDisabled = false;
                            } else {
                                $statusText = "Aguardando Professor";
                                $statusColor = "bg-amber-100 text-amber-700";
                                $btnText = "Gabarito Bloqueado"; 
                                $btnColor = "bg-slate-200 text-slate-400";
                                $btnLink = "#";
                                $isDisabled = true;
                            }
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
