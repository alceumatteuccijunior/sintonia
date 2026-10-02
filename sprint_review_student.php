<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: index.php");
    exit;
}
require_once 'config.php';
$pdo = getPDOConnection(true);

$sprint_id = $_GET['id'] ?? null;
$student_id = $_SESSION['user_id'];

if (!$sprint_id) {
    header("Location: student_dashboard.php");
    exit;
}

// Verifica se tem permissão e se o gabarito está liberado
$stmt = $pdo->prepare("
    SELECT s.*, c.name as class_name, a.completed_at
    FROM sprints s
    JOIN sprint_attempts a ON a.sprint_id = s.id AND a.student_id = ?
    JOIN classes c ON s.class_id = c.id
    WHERE s.id = ?
");
$stmt->execute([$student_id, $sprint_id]);
$sprint = $stmt->fetch();

if (!$sprint || !$sprint['completed_at']) {
    die("Você ainda não finalizou esta prova.");
}

if (!$sprint['feedback_released']) {
    die("O professor ainda não liberou o gabarito desta prova.");
}

// Busca as questões e a resposta do aluno
$stmtQ = $pdo->prepare("
    SELECT q.*, sq.order_num, mc.description as capacity_desc,
           sa.selected_option_id, sa.is_correct as student_is_correct
    FROM sprint_questions sq
    JOIN questions q ON sq.question_id = q.id
    LEFT JOIN module_capacities mc ON mc.capacity_code = q.capacity AND mc.module_id = q.module_id
    LEFT JOIN student_answers sa ON sa.question_id = q.id AND sa.sprint_id = sq.sprint_id AND sa.student_id = ?
    WHERE sq.sprint_id = ?
    ORDER BY sq.order_num ASC
");
$stmtQ->execute([$student_id, $sprint_id]);
$questions = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

// Opções
$qIds = array_column($questions, 'id');
$optionsByQ = [];
if (!empty($qIds)) {
    $placeholders = implode(',', array_fill(0, count($qIds), '?'));
    $stmtOpt = $pdo->prepare("SELECT * FROM question_options WHERE question_id IN ($placeholders) ORDER BY id ASC");
    $stmtOpt->execute($qIds);
    $allOptions = $stmtOpt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($allOptions as $opt) {
        $optionsByQ[$opt['question_id']][] = $opt;
    }
}

// Calculando Nota
$total = count($questions);
$acertos = 0;
foreach($questions as $q) {
    if($q['student_is_correct'] == 1) $acertos++;
}
$pct = $total > 0 ? round(($acertos / $total) * 100, 1) : 0;

include 'includes/header.php';
?>

<div id="app-screen" class="absolute inset-0 z-40 flex flex-col w-full h-full bg-[#F8FAFC]">
    
    <header class="h-16 flex items-center justify-between px-6 md:px-8 bg-white/70 backdrop-blur-md border-b border-white shadow-sm relative z-20 sticky top-0">
        <div class="flex items-center gap-3">
            <a href="student_dashboard.php" class="w-8 h-8 rounded-lg bg-slate-100 text-slate-500 hover:text-senai-blue hover:bg-slate-200 flex items-center justify-center transition-all">
                <i class="ph-bold ph-arrow-left"></i>
            </a>
            <span class="font-bold text-slate-800 text-lg tracking-tight hidden md:block">Boletim Detalhado</span>
        </div>
        
        <div class="flex items-center gap-4 text-sm font-bold">
            <span class="text-slate-500">Acertos:</span>
            <span class="text-senai-blue text-xl"><?= $acertos ?>/<?= $total ?></span>
            <span class="bg-<?= $pct >= 60 ? 'green' : 'red' ?>-100 text-<?= $pct >= 60 ? 'green' : 'red' ?>-700 px-2 py-1 rounded-md"><?= $pct ?>%</span>
        </div>
    </header>

    <main class="flex-1 overflow-y-auto w-full relative z-10 px-4 md:px-8 py-10">
        <div class="max-w-4xl mx-auto space-y-8">
            
            <div class="mb-8">
                <h1 class="text-2xl font-bold text-slate-800 mb-1"><?= htmlspecialchars($sprint['name']) ?></h1>
                <p class="text-slate-500">Revise suas respostas abaixo. As marcações verdes indicam a resposta correta.</p>
            </div>

            <?php foreach($questions as $index => $q): 
                $opts = $optionsByQ[$q['id']] ?? [];
                $letters = ['A', 'B', 'C', 'D', 'E'];
            ?>
            <div class="bg-white border <?= $q['student_is_correct'] ? 'border-green-200' : 'border-red-200' ?> rounded-3xl p-6 md:p-8 shadow-sm relative overflow-hidden">
                <!-- Status lateral -->
                <div class="absolute top-0 left-0 w-2 h-full <?= $q['student_is_correct'] ? 'bg-green-500' : 'bg-red-500' ?>"></div>
                
                <div class="flex items-center gap-3 mb-6">
                    <span class="bg-slate-100 text-slate-600 px-3 py-1 rounded-lg text-sm font-bold">Questão <?= $index + 1 ?></span>
                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider" title="<?= htmlspecialchars($q['capacity_desc'] ?? '') ?>">Capacidade: <?= htmlspecialchars($q['capacity']) ?></span>
                </div>
                
                <div class="text-slate-500 text-sm mb-4 leading-relaxed font-medium"><?= nl2br(htmlspecialchars($q['context'])) ?></div>
                <div class="text-slate-800 text-lg md:text-xl font-bold mb-8 leading-tight"><?= htmlspecialchars($q['command']) ?></div>
                
                <div class="space-y-3">
                    <?php foreach($opts as $i => $opt): 
                        $letter = $letters[$i] ?? '?';
                        $is_correct_opt = (int)$opt['is_correct'] === 1;
                        $student_selected_this = $opt['id'] == $q['selected_option_id'];
                        
                        $baseClass = "bg-slate-50 border-slate-200 text-slate-600";
                        $letterClass = "bg-slate-200 text-slate-500";
                        $icon = "";

                        if ($is_correct_opt) {
                            $baseClass = "bg-green-50 border-green-200 text-green-800 shadow-sm ring-1 ring-green-200";
                            $letterClass = "bg-green-500 text-white";
                            $icon = "<i class='ph-bold ph-check-circle text-green-500 text-xl'></i>";
                        } elseif ($student_selected_this && !$is_correct_opt) {
                            $baseClass = "bg-red-50 border-red-200 text-red-800";
                            $letterClass = "bg-red-500 text-white";
                            $icon = "<i class='ph-bold ph-x-circle text-red-500 text-xl'></i>";
                        }
                    ?>
                    <div class="p-4 rounded-2xl border <?= $baseClass ?> flex items-center gap-4">
                        <div class="w-8 h-8 rounded-lg flex items-center justify-center font-bold flex-shrink-0 <?= $letterClass ?>">
                            <?= $letter ?>
                        </div>
                        <div class="font-medium flex-1">
                            <?= htmlspecialchars($opt['text']) ?>
                        </div>
                        <?= $icon ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if(!$q['selected_option_id']): ?>
                    <div class="mt-4 text-red-500 text-sm font-bold flex items-center gap-2">
                        <i class="ph-bold ph-warning-circle"></i> Você não respondeu esta questão.
                    </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

        </div>
    </main>
</div>

<?php include 'includes/footer.php'; ?>
