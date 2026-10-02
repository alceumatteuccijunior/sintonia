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

// Histórico de Missões do Aluno
$stmtMissions = $pdo->prepare("
    SELECT s.name, s.created_at,
           (SELECT COUNT(*) FROM student_answers WHERE sprint_id = s.id AND is_correct = 1) as correct_answers,
           (SELECT COUNT(*) FROM sprint_questions WHERE sprint_id = s.id) as total_questions
    FROM sprints s
    JOIN sprint_attempts a ON a.sprint_id = s.id
    WHERE s.teacher_id IS NULL AND s.name LIKE 'Missão de Treinamento%' AND a.student_id = ?
    ORDER BY s.created_at DESC LIMIT 3
");
$stmtMissions->execute([$student_id]);
$missions = $stmtMissions->fetchAll();

// Colegas de Turma (para desafiar)
$stmtClassmates = $pdo->prepare("
    SELECT u.id, u.name, u.profile_pic 
    FROM users u
    JOIN class_students cs ON u.id = cs.student_id
    WHERE cs.class_id = ? AND u.id != ?
    ORDER BY u.name ASC
");
$stmtClassmates->execute([$classe['id'], $student_id]);
$classmates = $stmtClassmates->fetchAll();

// Duelos do Aluno
$stmtDuels = $pdo->prepare("
    SELECT s.*
    FROM sprints s
    WHERE s.name LIKE ? OR s.name LIKE ?
    ORDER BY s.created_at DESC
");
$stmtDuels->execute(["DUELO|{$student_id}|%", "DUELO|%|{$student_id}|%"]);
$duels = $stmtDuels->fetchAll();

include 'includes/header.php';
?>

<div id="app-screen" class="absolute inset-0 z-40 flex flex-col w-full h-full bg-[#F8FAFC]">
    
    <div class="fixed inset-0 w-full h-full overflow-hidden pointer-events-none z-0">
        <div class="absolute top-[-5%] left-[-5%] w-[500px] h-[500px] bg-senai-orange/10 rounded-full mix-blend-multiply filter blur-[80px] opacity-70 animate-blob"></div>
        <div class="absolute bottom-[-5%] right-[-5%] w-[600px] h-[600px] bg-red-500/10 rounded-full mix-blend-multiply filter blur-[90px] opacity-60 animate-blob" style="animation-delay: -5s;"></div>
    </div>

    <!-- Header Simples -->
    <header class="h-16 flex items-center justify-between px-6 md:px-8 bg-white/70 backdrop-blur-md border-b border-white shadow-sm relative z-20">
        <div class="flex items-center gap-4">
            <a href="student_dashboard.php" class="w-8 h-8 flex items-center justify-center rounded-lg bg-slate-100 text-slate-500 hover:bg-slate-200 transition-colors shadow-inner" title="Voltar">
                <i class="ph-bold ph-arrow-left"></i>
            </a>
            <h1 class="font-bold text-slate-800 text-lg tracking-tight">Arena SAEP</h1>
        </div>
        <div class="hidden md:flex items-center gap-2 bg-slate-100 px-3 py-1.5 rounded-lg text-sm shadow-inner">
            <span class="font-bold text-slate-600"><?= htmlspecialchars($classe['name']) ?></span>
        </div>
    </header>

    <main class="flex-1 overflow-y-auto w-full relative z-10 px-4 md:px-8 py-10">
        <div class="max-w-4xl mx-auto">
            
            <div class="text-center mb-12 animate-slide-up">
                <i class="ph-fill ph-sword text-6xl text-senai-orange drop-shadow-lg mb-4"></i>
                <h1 class="text-4xl md:text-5xl font-black text-slate-800 tracking-tight mb-3">Arena de Treinamento</h1>
                <p class="text-slate-500 text-lg font-medium">Melhore suas habilidades completando missões e acumule XP!</p>
            </div>

            <!-- Painel Superior com 2 Colunas: Missão e Histórico -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-12">
                <!-- Missão Solo -->
                <div class="bg-white/80 backdrop-blur-md border border-white/80 rounded-3xl p-8 shadow-xl text-center relative overflow-hidden animate-slide-up stagger-1 h-full flex flex-col justify-center">
                    <div class="absolute inset-0 bg-gradient-to-br from-senai-orange/5 to-transparent pointer-events-none"></div>
                    <h2 class="text-2xl font-black text-slate-800 mb-2">Treinamento Solo</h2>
                    <p class="text-slate-500 mb-8 text-sm">Sortear 5 questões aleatórias focadas nas suas maiores deficiências.</p>
                    <form action="student_arena_start.php" method="POST" class="mt-auto">
                        <button type="submit" class="w-full bg-senai-orange text-white font-black text-lg py-4 px-6 rounded-2xl shadow-[0_10px_25px_-5px_rgba(242,92,39,0.5)] hover:shadow-[0_15px_35px_-5px_rgba(242,92,39,0.6)] hover:-translate-y-1 transition-all flex items-center justify-center gap-3 group">
                            <i class="ph-bold ph-lightning text-2xl group-hover:scale-125 transition-transform"></i>
                            Iniciar Missão
                        </button>
                    </form>
                </div>

                <!-- Histórico de Missões -->
                <div class="animate-slide-up stagger-2 bg-slate-50 rounded-3xl p-6 border border-slate-100 flex flex-col">
                    <h3 class="text-lg font-bold text-slate-800 mb-4 flex items-center gap-2">
                        <i class="ph-fill ph-clock-counter-clockwise text-slate-400"></i> Últimos Treinos
                    </h3>
                    <div class="space-y-3 flex-1 overflow-y-auto pr-2 no-scrollbar">
                        <?php if (count($missions) > 0): ?>
                            <?php foreach ($missions as $m): ?>
                            <div class="bg-white border border-slate-100 rounded-2xl p-4 shadow-sm flex items-center justify-between">
                                <div>
                                    <p class="font-bold text-slate-700 text-sm">Missão Diária</p>
                                    <p class="text-xs text-slate-400 font-medium"><?= date('d/m/Y', strtotime($m['created_at'])) ?></p>
                                </div>
                                <div class="text-right">
                                    <p class="font-black <?= $m['correct_answers'] > 0 ? 'text-senai-blue' : 'text-slate-400' ?> text-sm"><?= $m['correct_answers'] ?>/<?= $m['total_questions'] ?> Acertos</p>
                                    <p class="text-[10px] uppercase font-bold text-slate-400">+<?= $m['correct_answers'] * 50 ?> XP</p>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="h-full flex items-center justify-center text-slate-400 text-sm font-medium text-center">
                                Nenhum treino realizado ainda.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Duelos P2P -->
            <div class="mb-12 animate-slide-up stagger-3">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h2 class="text-2xl font-black text-slate-800 flex items-center gap-2">
                            <i class="ph-fill ph-sword text-senai-blue"></i> Duelos P2P
                        </h2>
                        <p class="text-slate-500 font-medium text-sm">Desafie seus colegas para uma batalha 1v1 (5 questões).</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <!-- Meus Duelos (Pendentes e Concluídos) -->
                    <div class="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm">
                        <h3 class="text-sm font-bold text-slate-800 mb-4 uppercase tracking-wider">Avisos e Resultados</h3>
                        <div class="space-y-3 max-h-[300px] overflow-y-auto no-scrollbar pr-2">
                            <?php if (count($duels) > 0): ?>
                                <?php foreach ($duels as $d): 
                                    $parts = explode('|', $d['name']);
                                    $c_id = $parts[1]; // Challenger
                                    $t_id = $parts[2]; // Target
                                    
                                    $is_challenger = ($c_id == $student_id);
                                    $opponent_id = $is_challenger ? $t_id : $c_id;
                                    
                                    // Pega o nome do oponente
                                    $opp_name = "Oponente";
                                    $opp_pic = null;
                                    foreach($classmates as $cm) { 
                                        if($cm['id'] == $opponent_id) {
                                            $opp_name = $cm['name']; 
                                            $opp_pic = $cm['profile_pic'];
                                        }
                                    }
                                    
                                    // Pega os scores
                                    $stmtScore = $pdo->prepare("SELECT student_id, completed_at, (SELECT COUNT(*) FROM student_answers WHERE sprint_id = ? AND student_id = sprint_attempts.student_id AND is_correct = 1) as score FROM sprint_attempts WHERE sprint_id = ?");
                                    $stmtScore->execute([$d['id'], $d['id']]);
                                    $attempts = $stmtScore->fetchAll(PDO::FETCH_ASSOC);
                                    
                                    $my_attempt = null;
                                    $opp_attempt = null;
                                    foreach($attempts as $att) {
                                        if($att['student_id'] == $student_id) $my_attempt = $att;
                                        else $opp_attempt = $att;
                                    }
                                    
                                    // Status do duelo
                                    $status = "Pendente";
                                    $statusColor = "bg-amber-100 text-amber-700";
                                    $actionBtn = "";
                                    
                                    if (!$my_attempt || !$my_attempt['completed_at']) {
                                        $status = "Sua Vez";
                                        $statusColor = "bg-senai-orange text-white";
                                        $actionBtn = "<a href='sprint_solve.php?id={$d['id']}' class='text-xs bg-white text-senai-orange font-black px-3 py-1.5 rounded-lg border border-senai-orange hover:bg-senai-orange hover:text-white transition-colors'>Lutar!</a>";
                                    } elseif (!$opp_attempt || !$opp_attempt['completed_at']) {
                                        $status = "Aguardando Oponente";
                                        $statusColor = "bg-slate-100 text-slate-500";
                                    } else {
                                        // Ambos terminaram, quem venceu?
                                        $my_s = $my_attempt['score'] ?? 0;
                                        $opp_s = $opp_attempt['score'] ?? 0;
                                        if ($my_s > $opp_s) {
                                            $status = "Vitória!";
                                            $statusColor = "bg-green-100 text-green-700";
                                        } elseif ($my_s < $opp_s) {
                                            $status = "Derrota";
                                            $statusColor = "bg-red-100 text-red-700";
                                        } else {
                                            $status = "Empate";
                                            $statusColor = "bg-slate-200 text-slate-600";
                                        }
                                        $actionBtn = "<span class='text-xs font-bold text-slate-400'>$my_s x $opp_s</span>";
                                    }
                                ?>
                                <div class="bg-slate-50 border border-slate-100 rounded-2xl p-4 flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-senai-blue to-senai-cyan text-white flex items-center justify-center font-bold text-sm shrink-0 overflow-hidden">
                                            <?php if ($opp_pic): ?>
                                                <img src="uploads/avatars/<?= htmlspecialchars($opp_pic) ?>" alt="Avatar" class="w-full h-full object-cover">
                                            <?php else: ?>
                                                <?= substr($opp_name, 0, 1) ?>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <p class="font-bold text-slate-700 text-sm truncate w-24 md:w-32"><?= htmlspecialchars(explode(' ', $opp_name)[0]) ?></p>
                                            <p class="text-[10px] font-bold uppercase px-2 py-0.5 rounded mt-1 inline-block <?= $statusColor ?>"><?= $status ?></p>
                                        </div>
                                    </div>
                                    <div>
                                        <?= $actionBtn ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="h-full pt-8 flex items-center justify-center text-slate-400 text-sm font-medium text-center">
                                    Nenhum duelo recente. Que tal desafiar alguém?
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Ranking da Turma (Desafiar) -->
                    <div class="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm">
                        <h3 class="text-sm font-bold text-slate-800 mb-4 uppercase tracking-wider">Escolha seu Oponente</h3>
                        <div class="space-y-2 max-h-[300px] overflow-y-auto no-scrollbar pr-2">
                            <?php foreach($classmates as $cm): ?>
                            <div class="flex items-center justify-between p-2 hover:bg-slate-50 rounded-xl transition-colors group">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-slate-200 text-slate-600 flex items-center justify-center font-bold text-xs shrink-0 overflow-hidden">
                                        <?php if ($cm['profile_pic']): ?>
                                            <img src="uploads/avatars/<?= htmlspecialchars($cm['profile_pic']) ?>" alt="Avatar" class="w-full h-full object-cover">
                                        <?php else: ?>
                                            <?= substr($cm['name'], 0, 1) ?>
                                        <?php endif; ?>
                                    </div>
                                    <p class="font-bold text-slate-700 text-sm truncate max-w-[120px]"><?= htmlspecialchars($cm['name']) ?></p>
                                </div>
                                <form action="student_arena_duel.php" method="POST">
                                    <input type="hidden" name="target_id" value="<?= $cm['id'] ?>">
                                    <button type="submit" class="text-senai-blue bg-blue-50 px-3 py-1.5 rounded-lg text-xs font-bold opacity-0 group-hover:opacity-100 transition-opacity hover:bg-senai-blue hover:text-white border border-transparent">
                                        Desafiar
                                    </button>
                                </form>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<?php include 'includes/footer.php'; ?>
