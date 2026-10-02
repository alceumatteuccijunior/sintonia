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

// Buscar o Ranking da Turma
$stmtRank = $pdo->prepare("
    SELECT u.id as student_id, u.name as student_name, u.profile_pic,
           COUNT(sa.id) as total_answered,
           SUM(IF(sa.is_correct = 1, 1, 0)) as total_correct
    FROM users u
    JOIN class_students cs ON u.id = cs.student_id
    LEFT JOIN student_answers sa ON u.id = sa.student_id
    WHERE cs.class_id = ?
    GROUP BY u.id
    ORDER BY total_correct DESC, total_answered DESC
");
$stmtRank->execute([$classe['id']]);
$classRanking = $stmtRank->fetchAll();

// Array de Níveis (mesma lógica do dashboard)
$levels = [1 => 'Novato', 2 => 'Aprendiz', 3 => 'Especialista', 4 => 'Veterano', 5 => 'Mestre SAEP', 6 => 'Grão-Mestre'];

$rankingData = [];
$myRankIndex = null;
foreach ($classRanking as $index => $row) {
    $xp = ($row['total_correct'] ?? 0) * 50;
    $level_index = floor($xp / 500) + 1;
    $current_level_name = $levels[min($level_index, 6)] ?? 'Grão-Mestre';
    
    $rankingData[] = [
        'rank' => $index + 1,
        'student_id' => $row['student_id'],
        'student_name' => $row['student_name'],
        'profile_pic' => $row['profile_pic'],
        'score' => $row['total_correct'] ?? 0,
        'xp' => $xp,
        'level' => $level_index,
        'level_name' => $current_level_name,
        'is_me' => ($row['student_id'] == $student_id)
    ];

    if ($row['student_id'] == $student_id) {
        $myRankIndex = $index;
    }
}

// Separar Podio (Top 3) e o Restante
$podium = array_slice($rankingData, 0, 3);
$rest_of_class = array_slice($rankingData, 3);

include 'includes/header.php';
?>

<div id="app-screen" class="absolute inset-0 z-40 flex flex-col w-full h-full bg-[#F8FAFC]">
    
    <div class="fixed inset-0 w-full h-full overflow-hidden pointer-events-none z-0">
        <div class="absolute top-[-5%] left-[20%] w-[500px] h-[500px] bg-senai-orange/10 rounded-full mix-blend-multiply filter blur-[80px] opacity-70 animate-blob"></div>
        <div class="absolute bottom-[-5%] right-[20%] w-[600px] h-[600px] bg-senai-cyan/10 rounded-full mix-blend-multiply filter blur-[90px] opacity-60 animate-blob" style="animation-delay: -5s;"></div>
    </div>

    <!-- Header Simples -->
    <header class="h-16 flex items-center justify-between px-6 md:px-8 bg-white/70 backdrop-blur-md border-b border-white shadow-sm relative z-20">
        <div class="flex items-center gap-4">
            <a href="student_dashboard.php" class="w-8 h-8 flex items-center justify-center rounded-lg bg-slate-100 text-slate-500 hover:bg-slate-200 transition-colors shadow-inner" title="Voltar">
                <i class="ph-bold ph-arrow-left"></i>
            </a>
            <h1 class="font-bold text-slate-800 text-lg tracking-tight">Arena e Rankings</h1>
        </div>
        <div class="hidden md:flex items-center gap-2 bg-slate-100 px-3 py-1.5 rounded-lg text-sm shadow-inner">
            <span class="font-bold text-slate-600"><?= htmlspecialchars($classe['name']) ?></span>
            <span class="text-slate-400">•</span>
            <span class="font-medium text-slate-500"><?= htmlspecialchars($classe['course_name']) ?></span>
        </div>
    </header>

    <main class="flex-1 overflow-y-auto w-full relative z-10 px-4 md:px-8 py-10">
        <div class="max-w-4xl mx-auto">
            
            <div class="text-center mb-24 animate-slide-up">
                <h1 class="text-4xl md:text-5xl font-black text-slate-800 tracking-tight mb-3">Leaderboard</h1>
                <p class="text-slate-500 text-lg font-medium">Os melhores guerreiros do SAEP da turma <strong class="text-slate-700"><?= htmlspecialchars($classe['name']) ?></strong></p>
            </div>

            <!-- O Pódio (Top 3) -->
            <?php if (count($podium) > 0): ?>
            <div class="flex flex-col md:flex-row items-end justify-center gap-4 md:gap-8 mb-16 animate-slide-up stagger-1 h-auto md:h-64 mt-20 md:mt-0">
                <?php 
                // Ordem visual do Pódio: 2º (esquerda), 1º (centro), 3º (direita)
                $visual_order = [];
                if (isset($podium[1])) $visual_order[] = $podium[1]; // 2º lugar
                if (isset($podium[0])) $visual_order[] = $podium[0]; // 1º lugar
                if (isset($podium[2])) $visual_order[] = $podium[2]; // 3º lugar
                
                foreach ($visual_order as $p): 
                    $isFirst = $p['rank'] === 1;
                    $isSecond = $p['rank'] === 2;
                    $isThird = $p['rank'] === 3;
                    
                    $podiumHeight = $isFirst ? 'h-48 md:h-64' : ($isSecond ? 'h-40 md:h-52' : 'h-32 md:h-44');
                    $medalColor = $isFirst ? 'text-yellow-500' : ($isSecond ? 'text-slate-400' : 'text-amber-600');
                    $bgGradient = $isFirst ? 'from-yellow-400 to-yellow-600 shadow-yellow-500/40' : ($isSecond ? 'from-slate-300 to-slate-500 shadow-slate-500/40' : 'from-amber-500 to-amber-700 shadow-amber-600/40');
                    $avatarRing = $isFirst ? 'ring-4 ring-yellow-400' : ($isSecond ? 'ring-4 ring-slate-300' : 'ring-4 ring-amber-500');
                ?>
                <div class="w-full md:w-48 flex flex-col items-center relative group <?= $p['is_me'] ? 'scale-105' : '' ?>">
                    <!-- Avatar Flutuando -->
                    <div class="absolute -top-16 md:-top-20 z-20 flex flex-col items-center transform group-hover:-translate-y-2 transition-transform duration-300">
                        <?php if ($isFirst): ?>
                            <i class="ph-fill ph-crown text-4xl text-yellow-500 drop-shadow-md mb-[-8px]"></i>
                        <?php endif; ?>
                        <div class="w-20 h-20 md:w-24 md:h-24 bg-slate-900 rounded-2xl flex items-center justify-center text-white text-3xl font-black <?= $avatarRing ?> shadow-xl overflow-hidden">
                            <?php if ($p['profile_pic']): ?>
                                <img src="uploads/avatars/<?= htmlspecialchars($p['profile_pic']) ?>" alt="Avatar" class="w-full h-full object-cover">
                            <?php else: ?>
                                <?= htmlspecialchars(substr($p['student_name'], 0, 1)) ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($p['is_me']): ?>
                            <span class="bg-senai-orange text-white text-[10px] uppercase font-black px-3 py-0.5 rounded-full mt-[-10px] z-10 border border-white shadow-sm">Você</span>
                        <?php endif; ?>
                    </div>

                    <!-- O Bloco do Pódio -->
                    <div class="w-full <?= $podiumHeight ?> bg-gradient-to-t <?= $bgGradient ?> rounded-t-3xl shadow-xl flex flex-col items-center justify-end p-4 text-white relative overflow-hidden mt-10 md:mt-0">
                        <div class="absolute inset-0 bg-white/10 mix-blend-overlay"></div>
                        <h3 class="font-bold text-lg text-center leading-tight mb-1 truncate w-full z-10" style="text-shadow: 0 2px 4px rgba(0,0,0,0.3);"><?= htmlspecialchars(explode(' ', $p['student_name'])[0]) ?></h3>
                        <p class="text-xs font-medium opacity-90 z-10 mb-3"><?= $p['level_name'] ?> • LVL <?= $p['level'] ?></p>
                        <div class="bg-black/20 backdrop-blur-sm rounded-xl px-4 py-3 w-full text-center z-10 border border-white/10">
                            <p class="text-[10px] font-bold uppercase tracking-widest opacity-80 mb-0.5">XP Total</p>
                            <p class="text-xl md:text-2xl font-black text-white"><?= number_format($p['xp'], 0, ',', '.') ?></p>
                        </div>
                        <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 text-[150px] font-black text-white opacity-10 pointer-events-none"><?= $p['rank'] ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- A Lista (4º em diante) -->
            <?php if (count($rest_of_class) > 0): ?>
            <div class="bg-white/80 backdrop-blur-md border border-white/80 rounded-3xl p-6 shadow-sm animate-slide-up stagger-2">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-widest text-slate-400">
                            <th class="pb-3 font-semibold w-16 text-center">Pos</th>
                            <th class="pb-3 font-semibold">Guerreiro</th>
                            <th class="pb-3 font-semibold text-center hidden md:table-cell">Nível</th>
                            <th class="pb-3 font-semibold text-center hidden sm:table-cell">Acertos</th>
                            <th class="pb-3 font-semibold text-right">Pontuação XP</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm">
                        <?php foreach($rest_of_class as $r): ?>
                        <tr class="border-b border-slate-50 hover:bg-slate-50/80 transition-colors <?= $r['is_me'] ? 'bg-senai-blue/5 hover:bg-senai-blue/10' : '' ?>">
                            <td class="py-4 font-black text-lg text-slate-400 text-center">
                                <?= $r['rank'] ?>º
                            </td>
                            <td class="py-4">
                                <div class="flex items-center gap-4">
                                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-slate-700 to-slate-900 text-white flex items-center justify-center font-bold shadow-sm shrink-0 overflow-hidden">
                                        <?php if ($r['profile_pic']): ?>
                                            <img src="uploads/avatars/<?= htmlspecialchars($r['profile_pic']) ?>" alt="Avatar" class="w-full h-full object-cover">
                                        <?php else: ?>
                                            <?= htmlspecialchars(substr($r['student_name'], 0, 1)) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <p class="font-bold text-slate-800 text-base <?= $r['is_me'] ? 'text-senai-blue' : '' ?>">
                                            <?= htmlspecialchars($r['student_name']) ?>
                                            <?php if($r['is_me']): ?>
                                                <span class="ml-2 bg-senai-orange text-white text-[9px] uppercase font-black px-2 py-0.5 rounded-full align-middle shadow-sm">Você</span>
                                            <?php endif; ?>
                                        </p>
                                        <p class="text-xs text-slate-400 font-medium md:hidden"><?= $r['level_name'] ?> • LVL <?= $r['level'] ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="py-4 text-center hidden md:table-cell">
                                <span class="bg-slate-100 text-slate-600 font-bold px-3 py-1.5 rounded-lg text-xs shadow-inner">
                                    LVL <?= $r['level'] ?>
                                </span>
                            </td>
                            <td class="py-4 text-center text-slate-500 font-bold hidden sm:table-cell">
                                <?= $r['score'] ?>
                            </td>
                            <td class="py-4 text-right">
                                <span class="font-black text-senai-blue text-lg"><?= number_format($r['xp'], 0, ',', '.') ?></span>
                                <span class="text-[10px] text-slate-400 font-bold uppercase tracking-widest ml-1">XP</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        </div>
    </main>
</div>

<?php include 'includes/footer.php'; ?>
