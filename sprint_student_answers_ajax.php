<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'teacher'])) {
    die('<div class="text-red-500 font-bold p-4">Acesso negado.</div>');
}

require_once 'config.php';
$pdo = getPDOConnection();

$sprint_id = $_GET['sprint_id'] ?? null;
$student_id = $_GET['student_id'] ?? null;

if (!$sprint_id || !$student_id) {
    die('<div class="text-red-500 font-bold p-4">Parâmetros inválidos.</div>');
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS course_capacities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            course_id INT NOT NULL,
            capacity_code VARCHAR(50) NOT NULL,
            description TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_course_cap (course_id, capacity_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

// Fetch all questions in the sprint and the student's answer
$stmt = $pdo->prepare("
    SELECT 
        sq.order_num,
        q.id as question_id,
        q.command,
        q.capacity,
        COALESCE(cc.description, mc.description) as capacity_desc,
        m.name as module_name,
        sa.is_correct,
        sa.selected_option_id,
        qo.text as selected_option_text,
        qo.image_url as selected_option_image,
        (SELECT text FROM question_options WHERE question_id = q.id AND is_correct = 1 LIMIT 1) as correct_option_text,
        (SELECT image_url FROM question_options WHERE question_id = q.id AND is_correct = 1 LIMIT 1) as correct_option_image
    FROM sprint_questions sq
    JOIN questions q ON sq.question_id = q.id
    LEFT JOIN modules m ON q.module_id = m.id
    LEFT JOIN course_capacities cc ON cc.course_id = m.course_id AND cc.capacity_code = q.capacity
    LEFT JOIN module_capacities mc ON mc.module_id = q.module_id AND mc.capacity_code = q.capacity
    LEFT JOIN student_answers sa ON sa.question_id = q.id AND sa.sprint_id = sq.sprint_id AND sa.student_id = ?
    LEFT JOIN question_options qo ON sa.selected_option_id = qo.id
    WHERE sq.sprint_id = ?
    ORDER BY sq.order_num ASC
");
$stmt->execute([$student_id, $sprint_id]);
$questions = $stmt->fetchAll();

// Buscar tempo de prova e aluno
$stmtAttempt = $pdo->prepare("
    SELECT a.started_at, a.completed_at, u.name as student_name
    FROM sprint_attempts a
    JOIN users u ON a.student_id = u.id
    WHERE a.sprint_id = ? AND a.student_id = ?
    ORDER BY a.id DESC LIMIT 1
");
$stmtAttempt->execute([$sprint_id, $student_id]);
$attempt = $stmtAttempt->fetch();

$time_str = "N/A";
if ($attempt && $attempt['started_at'] && $attempt['completed_at']) {
    $diff = strtotime($attempt['completed_at']) - strtotime($attempt['started_at']);
    if ($diff > 0) {
        $mins = floor($diff / 60);
        $secs = $diff % 60;
        $time_str = $mins > 0 ? "{$mins}m {$secs}s" : "{$secs}s";
    }
} elseif ($attempt && $attempt['started_at']) {
    $time_str = "Em andamento";
}

if (count($questions) === 0) {
    die('<div class="text-slate-500 font-bold p-8 text-center">Nenhuma questão encontrada para esta sprint.</div>');
}

$correct_count = 0;
foreach ($questions as $q) {
    if ($q['is_correct'] == 1) $correct_count++;
}
$rate = round(($correct_count / count($questions)) * 100, 1);
?>

<?php
$answered_total_modal = count(array_filter(array_column($questions, 'selected_option_id')));
$total_q_modal = count($questions);
$stu_name_js = htmlspecialchars($attempt['student_name'] ?? 'Aluno', ENT_QUOTES);
$is_completed_modal = !empty($attempt['completed_at']);
?>
<div class="mb-6 flex flex-wrap items-center justify-between gap-4 bg-white p-4 rounded-2xl shadow-sm border border-slate-100">
    <div class="flex items-center gap-4">
        <div class="text-center px-4 border-r border-slate-100">
            <p class="text-[10px] uppercase font-bold text-slate-400">Respondidas</p>
            <p class="text-xl font-black text-slate-700"><?= $answered_total_modal ?>/<?= $total_q_modal ?></p>
        </div>
        <div class="text-center px-4 border-r border-slate-100">
            <p class="text-[10px] uppercase font-bold text-slate-400">Acertos</p>
            <p class="text-xl font-black text-senai-blue"><?= $correct_count ?></p>
        </div>
        <div class="text-center px-4 border-r border-slate-100">
            <p class="text-[10px] uppercase font-bold text-slate-400">Tempo de Prova</p>
            <p class="text-xl font-black text-slate-700 whitespace-nowrap"><?= $time_str ?></p>
        </div>
        <div class="text-center px-4">
            <p class="text-[10px] uppercase font-bold text-slate-400">Taxa</p>
            <p class="text-xl font-black <?= $rate >= 70 ? 'text-green-600' : ($rate >= 50 ? 'text-yellow-600' : 'text-red-500') ?>"><?= $rate ?>%</p>
        </div>
    </div>

    <?php if ($attempt): ?>
    <div>
        <button type="button"
                onclick="if (typeof confirmReopenAttempt === 'function') { confirmReopenAttempt(<?= (int)$student_id ?>, '<?= $stu_name_js ?>', <?= $answered_total_modal ?>, <?= $total_q_modal ?>, <?= $is_completed_modal ? 'true' : 'false' ?>); }"
                class="px-4 py-2.5 rounded-xl font-bold text-xs flex items-center gap-2 shadow-sm transition-all <?= $is_completed_modal ? 'bg-emerald-600 hover:bg-emerald-700 text-white' : 'bg-amber-500 hover:bg-amber-600 text-white' ?>">
            <i class="ph-bold ph-arrow-counter-clockwise text-sm"></i>
            <?= $is_completed_modal ? 'Permitir que o Aluno Retome a Prova' : 'Zerar Avisos Anticola / Destravar Prova' ?>
        </button>
    </div>
    <?php endif; ?>
</div>

<div class="space-y-3">
    <?php foreach ($questions as $q): 
        $is_correct = $q['is_correct'] === 1;
        $is_wrong = $q['is_correct'] === 0;
        $is_null = is_null($q['is_correct']);
        
        if ($is_correct) {
            $status_class = 'bg-green-50 border-green-200';
            $icon = '<div class="w-8 h-8 rounded-full bg-green-100 text-green-600 flex items-center justify-center shrink-0"><i class="ph-bold ph-check"></i></div>';
        } elseif ($is_wrong) {
            $status_class = 'bg-red-50 border-red-200';
            $icon = '<div class="w-8 h-8 rounded-full bg-red-100 text-red-600 flex items-center justify-center shrink-0"><i class="ph-bold ph-x"></i></div>';
        } else {
            $status_class = 'bg-slate-50 border-slate-200';
            $icon = '<div class="w-8 h-8 rounded-full bg-slate-200 text-slate-500 flex items-center justify-center shrink-0"><i class="ph-bold ph-minus"></i></div>';
        }
    ?>
    <div class="p-4 rounded-xl border <?= $status_class ?> flex items-start gap-4 transition-colors">
        <div class="pt-1">
            <?= $icon ?>
        </div>
        <div class="flex-1">
            <div class="flex flex-wrap items-center gap-2 mb-1.5">
                <span class="bg-slate-800 text-white text-[10px] font-bold px-2 py-0.5 rounded">Q<?= $q['order_num'] ?></span>
                <span class="text-xs font-bold text-slate-500 uppercase"><?= htmlspecialchars($q['module_name'] ?: 'Geral') ?></span>
                <span class="text-xs text-slate-700 font-semibold border-l border-slate-300 pl-2">
                    <strong class="text-senai-blue"><?= htmlspecialchars($q['capacity']) ?></strong><?= !empty($q['capacity_desc']) ? ' — ' . htmlspecialchars($q['capacity_desc']) : '' ?>
                </span>
            </div>
            
            <p class="text-sm font-semibold text-slate-800 mb-3 line-clamp-2"><?= htmlspecialchars($q['command']) ?></p>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-2 border-t border-slate-200/60 pt-3">
                <div>
                    <span class="text-[10px] uppercase font-bold text-slate-400 block mb-1">Resposta do Aluno</span>
                    <?php if ($is_null): ?>
                        <span class="text-sm font-medium text-slate-400 italic">Não respondeu</span>
                    <?php else: ?>
                        <?php if (!empty($q['selected_option_text'])): ?>
                            <span class="text-sm font-bold block <?= $is_correct ? 'text-green-700' : 'text-red-600' ?>"><?= htmlspecialchars($q['selected_option_text']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($q['selected_option_image'])): ?>
                            <img src="<?= htmlspecialchars($q['selected_option_image']) ?>" alt="Resposta do Aluno" class="mt-1.5 max-h-28 rounded-lg border border-slate-200 bg-white p-1 object-contain">
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                
                <?php if (!$is_correct): ?>
                <div>
                    <span class="text-[10px] uppercase font-bold text-slate-400 block mb-1">Resposta Correta</span>
                    <?php if (!empty($q['correct_option_text'])): ?>
                        <span class="text-sm font-bold text-green-700 block"><?= htmlspecialchars($q['correct_option_text']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($q['correct_option_image'])): ?>
                        <img src="<?= htmlspecialchars($q['correct_option_image']) ?>" alt="Resposta Correta" class="mt-1.5 max-h-28 rounded-lg border border-slate-200 bg-white p-1 object-contain">
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
