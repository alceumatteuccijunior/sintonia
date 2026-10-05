<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'teacher'])) {
    header("Location: index.php");
    exit;
}
require_once 'config.php';
$pdo = getPDOConnection(true);

$sprint_id = $_GET['id'] ?? null;
if (!$sprint_id) {
    header("Location: dashboard.php");
    exit;
}

// 1. Dados Básicos da Sprint
if ($_SESSION['user_role'] === 'admin') {
    $stmt = $pdo->prepare("
        SELECT s.*, c.name as class_name 
        FROM sprints s 
        JOIN classes c ON s.class_id = c.id 
        WHERE s.id = ?
    ");
    $stmt->execute([$sprint_id]);
} else {
    $stmt = $pdo->prepare("
        SELECT s.*, c.name as class_name 
        FROM sprints s 
        JOIN classes c ON s.class_id = c.id 
        WHERE s.id = ? AND s.teacher_id = ?
    ");
    $stmt->execute([$sprint_id, $_SESSION['user_id']]);
}

$sprint = $stmt->fetch();
if (!$sprint) {
    die("Sprint não encontrada ou sem permissão.");
}

// Remoção de Questão da Sprint
if (isset($_GET['remove_q'])) {
    $remove_q = $_GET['remove_q'];
    
    // Remove o vínculo da questão com a sprint
    $pdo->prepare("DELETE FROM sprint_questions WHERE sprint_id = ? AND question_id = ?")->execute([$sprint_id, $remove_q]);
    
    // Remove as respostas dos alunos para essa questão nesta sprint
    $pdo->prepare("DELETE FROM student_answers WHERE sprint_id = ? AND question_id = ?")->execute([$sprint_id, $remove_q]);
    
    header("Location: sprint_report.php?id=" . $sprint_id);
    exit;
}

// 2. Ranking e Notas dos Alunos
$stmtRanking = $pdo->prepare("
    SELECT u.id as student_id,
           u.name as student_name, 
           COUNT(sa.id) as total_answered,
           SUM(IF(sa.is_correct = 1, 1, 0)) as total_correct,
           a.started_at,
           a.completed_at,
           a.id as attempt_id
    FROM sprint_attempts a
    JOIN users u ON a.student_id = u.id
    LEFT JOIN student_answers sa ON sa.sprint_id = a.sprint_id AND sa.student_id = a.student_id
    WHERE a.sprint_id = ?
    GROUP BY u.id, a.started_at, a.completed_at, a.id
    ORDER BY total_correct DESC, total_answered DESC
");
$stmtRanking->execute([$sprint_id]);
$ranking = $stmtRanking->fetchAll();

// 3. Desempenho por Módulo
$stmtMod = $pdo->prepare("
    SELECT m.name as module_name, 
           COUNT(sa.id) as total_answers,
           SUM(IF(sa.is_correct = 1, 1, 0)) as correct_answers
    FROM sprint_questions sq
    JOIN questions q ON sq.question_id = q.id
    JOIN modules m ON q.module_id = m.id
    LEFT JOIN student_answers sa ON sa.question_id = q.id AND sa.sprint_id = sq.sprint_id
    WHERE sq.sprint_id = ?
    GROUP BY m.id
");
$stmtMod->execute([$sprint_id]);
$modules_perf = $stmtMod->fetchAll();

// 4. Desempenho por Capacidade (Com Descrição e Módulo)
$stmtCap = $pdo->prepare("
    SELECT q.capacity as capacity_code, 
           mc.description as capacity_desc,
           m.name as module_name,
           COUNT(sa.id) as total_answers,
           SUM(IF(sa.is_correct = 1, 1, 0)) as correct_answers
    FROM sprint_questions sq
    JOIN questions q ON sq.question_id = q.id
    JOIN modules m ON q.module_id = m.id
    LEFT JOIN module_capacities mc ON mc.capacity_code = q.capacity AND mc.module_id = q.module_id
    LEFT JOIN student_answers sa ON sa.question_id = q.id AND sa.sprint_id = sq.sprint_id
    WHERE sq.sprint_id = ?
    GROUP BY q.capacity, mc.description, m.name
");
$stmtCap->execute([$sprint_id]);
$capacities = $stmtCap->fetchAll();

// 5. Desempenho por Questões Detalhado
$stmtQ = $pdo->prepare("
    SELECT q.id, q.command, q.capacity, mc.description as capacity_desc, m.name as module_name,
           COUNT(sa.id) as total_answers,
           SUM(IF(sa.is_correct = 1, 1, 0)) as correct_answers
    FROM sprint_questions sq
    JOIN questions q ON sq.question_id = q.id
    JOIN modules m ON q.module_id = m.id
    LEFT JOIN module_capacities mc ON mc.capacity_code = q.capacity AND mc.module_id = q.module_id
    LEFT JOIN student_answers sa ON sa.question_id = q.id AND sa.sprint_id = sq.sprint_id
    WHERE sq.sprint_id = ?
    GROUP BY q.id
    ORDER BY sq.order_num ASC
");
$stmtQ->execute([$sprint_id]);
$questions_perf = $stmtQ->fetchAll();

// 6. Distratores das Questões (Pegadinhas)
$stmtDistractors = $pdo->prepare("
    SELECT sa.question_id, qo.text as option_text, COUNT(sa.id) as chosen_count
    FROM student_answers sa
    JOIN question_options qo ON sa.selected_option_id = qo.id
    WHERE sa.sprint_id = ? AND sa.is_correct = 0
    GROUP BY sa.question_id, qo.id
    ORDER BY sa.question_id, chosen_count DESC
");
$stmtDistractors->execute([$sprint_id]);
$distractors_raw = $stmtDistractors->fetchAll();
$distractors = [];
foreach($distractors_raw as $d) {
    if (!isset($distractors[$d['question_id']])) {
        $distractors[$d['question_id']] = [];
    }
    $distractors[$d['question_id']][] = $d;
}

// ==============================================
// PREPARAÇÃO DOS DADOS PARA O JS E RANKINGS
// ==============================================
$labels = [];
$data_correct = [];
$data_wrong = [];
$worst_capacity = 'N/A';
$lowest_rate = 100;
$capacities_processed = [];

foreach ($capacities as $c) {
    $cap_code = $c['capacity_code'] ?: 'Geral';
    $mod = $c['module_name'] ?: 'Geral';
    $desc_text = $c['capacity_desc'] ? substr($c['capacity_desc'], 0, 50) . '...' : $cap_code;
    
    // Label do gráfico agora inclui o módulo
    $chart_label = "[$mod] $desc_text";
    $labels[] = (string)$chart_label;
    
    $correct = (int)$c['correct_answers'];
    $wrong = (int)$c['total_answers'] - $correct;
    $data_correct[] = $correct;
    $data_wrong[] = $wrong;
    
    $rate = ($c['total_answers'] > 0) ? ($correct / $c['total_answers']) * 100 : 0;
    if ($rate < $lowest_rate && $c['total_answers'] > 0) {
        $lowest_rate = $rate;
        $worst_capacity = $cap_code . ($c['capacity_desc'] ? ' - ' . $c['capacity_desc'] : '');
    }
    
    $c['rate'] = $rate;
    $c['is_critical'] = $rate < 50;
    $capacities_processed[] = $c;
}

// Ordenando Top e Bottom 5 Capacidades
usort($capacities_processed, function($a, $b) {
    return $b['rate'] <=> $a['rate']; // DESC
});
$top_capacities = array_slice($capacities_processed, 0, 5);
$bottom_capacities = array_slice(array_reverse($capacities_processed), 0, 5);

// Ordenando Top e Bottom 3 Questões
$questions_processed = [];
foreach ($questions_perf as $q) {
    $q['rate'] = ($q['total_answers'] > 0) ? ($q['correct_answers'] / $q['total_answers']) * 100 : 0;
    $questions_processed[] = $q;
}
usort($questions_processed, function($a, $b) {
    return $b['rate'] <=> $a['rate']; // DESC
});
$top_questions = array_slice($questions_processed, 0, 3);
$bottom_questions = array_slice(array_reverse($questions_processed), 0, 3);

// Métricas GeriaS (Saúde da Turma, Tempo Médio, Total Questions)
$stmtQCount = $pdo->prepare("SELECT COUNT(*) FROM sprint_questions WHERE sprint_id = ?");
$stmtQCount->execute([$sprint_id]);
$total_qs_possible = max(1, (int)$stmtQCount->fetchColumn());

$total_students_started = count($ranking);
$avg_score = 0;
$total_time_seconds = 0;
$valid_times = 0;
$health_high = 0;
$health_med = 0;
$health_low = 0;

if ($total_students_started > 0) {
    $sum_correct = array_sum(array_column($ranking, 'total_correct'));
    $avg_score = round($sum_correct / $total_students_started, 1);
    
    foreach ($ranking as $r) {
        // Cálculo de Saúde (Risco)
        $student_rate = ($r['total_correct'] / $total_qs_possible) * 100;
        if ($student_rate >= 70) $health_high++;
        elseif ($student_rate >= 50) $health_med++;
        else $health_low++;
        
        // Cálculo de Tempo Médio
        if ($r['started_at'] && $r['completed_at']) {
            $diff = strtotime($r['completed_at']) - strtotime($r['started_at']);
            if ($diff > 0 && $diff < 14400) { // Considera apenas tempos menores que 4h como válidos (evita bugs de sessão longa)
                $total_time_seconds += $diff;
                $valid_times++;
            }
        }
    }
}

$avg_time_str = "N/A";
if ($valid_times > 0) {
    $avg_mins = floor(($total_time_seconds / $valid_times) / 60);
    $avg_time_str = $avg_mins . " min";
}

include 'includes/header.php';
?>

<!-- Importando bibliotecas JS -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>

<style>
    /* Estilos Específicos para Impressão (PDF) */
    @media print {
        body { background: white !important; -webkit-print-color-adjust: exact; }
        #sidebar, #print-btn, #ai-btn, .no-print { display: none !important; }
        #main-content { margin-left: 0 !important; width: 100% !important; }
        .glass-panel, .bg-white\/70, .backdrop-blur-md { background: white !important; border: 1px solid #e2e8f0 !important; box-shadow: none !important; }
        #content-scroll-area { pt: 0; pb: 0; overflow: visible !important; height: auto !important; }
        details[open] summary ~ * { display: block !important; }
        details { page-break-inside: avoid; }
        .page-break { page-break-before: always; }
    }
    
    /* Remove a seta padrão do details/summary */
    details > summary { list-style: none; }
    details > summary::-webkit-details-marker { display: none; }
</style>

<div id="app-screen" class="absolute inset-0 z-40 flex w-full h-full bg-[#F8FAFC]">
    
    <div class="fixed inset-0 w-full h-full overflow-hidden pointer-events-none z-0 no-print">
        <div class="absolute top-[-5%] right-[-5%] w-[500px] h-[500px] bg-senai-cyan/15 rounded-full mix-blend-multiply filter blur-[80px] opacity-70 animate-blob"></div>
        <div class="absolute bottom-[-5%] left-[-5%] w-[600px] h-[600px] bg-senai-blue/10 rounded-full mix-blend-multiply filter blur-[80px] opacity-50 animate-blob"></div>
    </div>

    <?php
    $currentPage = 'dashboard';
    include 'includes/sidebar.php'; 
    ?>

    <main id="main-content" class="flex-1 flex flex-col relative h-full w-full">
        <header class="h-14 flex items-center justify-between px-4 md:px-6 absolute top-0 w-full z-20 pointer-events-none no-print">
            <button onclick="toggleSidebar()" class="pointer-events-auto w-10 h-10 flex items-center justify-center bg-white/50 hover:bg-white rounded-xl backdrop-blur-md shadow-sm border border-white/60 transition-all active:scale-95 text-senai-dark">
                <i id="sidebar-icon" class="ph-bold ph-list text-xl"></i>
            </button>
            <div class="flex-1"></div>
            <a href="dashboard.php" class="pointer-events-auto text-[11px] font-bold text-slate-600 hover:text-senai-blue flex items-center gap-1.5 bg-white/70 backdrop-blur-xl border border-white/80 shadow-sm px-4 py-2 rounded-full hover:shadow transition-all duration-300 active:scale-95">
                <i class="ph-fill ph-arrow-left"></i> Voltar
            </a>
        </header>

        <div id="content-scroll-area" class="flex-1 overflow-y-auto w-full flex flex-col items-center pt-24 pb-20 relative z-10 px-4 md:px-8">
            <div class="w-full max-w-5xl" id="report-container">
                
                <!-- Cabeçalho do Relatório -->
                <div class="flex flex-col md:flex-row justify-between items-start md:items-end mb-8 animate-slide-up gap-4">
                    <div>
                        <h1 class="text-3xl font-bold tracking-tight mb-2 text-slate-800 flex items-center gap-3">
                            Relatório Pedagógico e de Desempenho
                            <button onclick="openHelpModal('Relatório de Desempenho da Sprint', 'Analise detalhadamente o desempenho da turma nesta prova.<br><br><b>O que você pode fazer:</b><br>- Ver o ranking de notas da turma.<br>- Identificar rapidamente a taxa de acertos por questão para focar nas deficiências.<br>- Clicar em <b>Ver Detalhes</b> no nome de um aluno para ver exatamente o que ele acertou/errou e o tempo de prova.<br>- Gerar o plano de aula com a Inteligência Artificial (IA Corretiva).')" class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 hover:text-senai-blue hover:bg-blue-50 transition-colors shadow-inner no-print" title="Como Usar">
                                <i class="ph-bold ph-question text-lg"></i>
                            </button>
                        </h1>
                        <p class="text-slate-500 font-medium">Sprint: <strong class="text-senai-blue"><?= htmlspecialchars($sprint['name']) ?></strong> • Turma: <strong><?= htmlspecialchars($sprint['class_name']) ?></strong></p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3 no-print">
                        <button id="expand-all-btn" onclick="document.querySelectorAll('details').forEach(d => d.open = true)" class="bg-slate-200 text-slate-700 hover:bg-slate-300 px-4 py-2 rounded-xl font-bold shadow-sm transition-all text-sm flex items-center gap-2">
                            <i class="ph-bold ph-arrows-out-line-vertical"></i> Expandir Tudo
                        </button>
                        <a href="sprint_feedback_toggle.php?id=<?= $sprint_id ?>" class="px-4 py-2 rounded-xl font-bold shadow-sm transition-all text-sm flex items-center gap-2 <?= $sprint['feedback_released'] ? 'bg-red-500 hover:bg-red-600 text-white' : 'bg-green-500 hover:bg-green-600 text-white' ?>">
                            <?php if($sprint['feedback_released']): ?>
                                <i class="ph-bold ph-lock"></i> Bloquear Gabarito
                            <?php else: ?>
                                <i class="ph-bold ph-lock-open"></i> Liberar Gabarito
                            <?php endif; ?>
                        </a>
                        <a href="sprint_review_teacher.php?id=<?= $sprint_id ?>" target="_blank" class="bg-indigo-500 hover:bg-indigo-600 text-white px-4 py-2 rounded-xl font-bold shadow-sm transition-all text-sm flex items-center gap-2">
                            <i class="ph-bold ph-projector-screen-chart"></i> Modo Projetor
                        </a>
                        <button id="ai-btn" onclick="generateAIPlan()" class="bg-senai-orange text-white px-4 py-2 rounded-xl font-bold shadow-sm hover:shadow-md transition-all text-sm flex items-center gap-2 group">
                            <i class="ph-bold ph-magic-wand group-hover:rotate-12 transition-transform"></i> IA Corretiva
                        </button>
                        <a href="sprint_report_slides.php?id=<?= $sprint_id ?>" target="_blank" class="bg-senai-cyan text-white px-4 py-2 rounded-xl font-bold shadow-sm hover:shadow-md transition-all text-sm flex items-center gap-2">
                            <i class="ph-bold ph-presentation-chart"></i> Slides (IA)
                        </a>
                        <a href="sprint_export_word.php?id=<?= $sprint_id ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl font-bold shadow-sm hover:shadow-md transition-all text-sm flex items-center gap-2">
                            <i class="ph-bold ph-file-doc"></i> Exportar Word (MSEP)
                        </a>
                        <button id="print-btn" onclick="document.querySelectorAll('details').forEach(d => d.open = true); window.print();" class="bg-slate-700 text-white px-4 py-2 rounded-xl font-bold shadow-sm hover:shadow-md transition-all text-sm flex items-center gap-2">
                            <i class="ph-bold ph-printer"></i> Imprimir PDF
                        </button>
                    </div>
                </div>

                <!-- Modulo IA -->
                <div id="ai-module" class="hidden mb-8 animate-slide-up bg-gradient-to-r from-orange-50 to-amber-50 border border-orange-200 rounded-3xl p-8 shadow-inner no-print relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-senai-orange/10 rounded-full blur-2xl"></div>
                    <div class="relative z-10">
                        <h3 class="text-xl font-bold text-slate-800 mb-2 flex items-center gap-2">
                            <i class="ph-fill ph-magic-wand text-senai-orange"></i> Plano de Aula Sugerido pela IA
                        </h3>
                        <p class="text-sm text-slate-500 mb-6">Com base na maior deficiência da turma (Capacidade: <strong><?= htmlspecialchars($worst_capacity) ?></strong>).</p>
                        
                        <div id="ai-loading" class="flex flex-col items-center justify-center py-10">
                            <div class="w-10 h-10 border-4 border-senai-orange border-t-transparent rounded-full animate-spin mb-4"></div>
                            <p class="text-senai-orange font-bold animate-pulse">A Inteligência Artificial está analisando os dados...</p>
                        </div>
                        <div id="ai-content" class="hidden prose prose-slate max-w-none prose-h3:text-senai-blue prose-p:font-medium bg-white/60 p-6 rounded-2xl"></div>
                    </div>
                </div>

                <!-- KPIs (Business Metrics) -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8 animate-fade-in delay-200">
                    <div class="bg-white/80 backdrop-blur-md border border-white/80 p-5 rounded-3xl shadow-sm text-center">
                        <i class="ph-fill ph-users-three text-3xl text-senai-cyan mb-1"></i>
                        <h4 class="text-xs font-bold text-slate-500 uppercase tracking-widest">Participação</h4>
                        <p class="text-2xl font-bold text-slate-800 mt-1"><?= $total_students_started ?></p>
                        <p class="text-xs text-slate-400 font-medium">Alunos Iniciaram</p>
                    </div>
                    <div class="bg-white/80 backdrop-blur-md border border-white/80 p-5 rounded-3xl shadow-sm text-center">
                        <i class="ph-fill ph-target text-3xl text-senai-blue mb-1"></i>
                        <h4 class="text-xs font-bold text-slate-500 uppercase tracking-widest">Média de Acertos</h4>
                        <p class="text-2xl font-bold text-slate-800 mt-1">
                            <?= $avg_score ?> 
                            <span class="text-lg text-slate-500 font-medium">(<?= $total_qs_possible > 0 ? round(($avg_score / $total_qs_possible) * 100, 1) : 0 ?>%)</span>
                        </p>
                        <p class="text-xs text-slate-400 font-medium">Em <?= $total_qs_possible ?> questões</p>
                    </div>
                    <div class="bg-white/80 backdrop-blur-md border border-white/80 p-5 rounded-3xl shadow-sm text-center">
                        <i class="ph-fill ph-clock-countdown text-3xl text-indigo-500 mb-1"></i>
                        <h4 class="text-xs font-bold text-slate-500 uppercase tracking-widest">Tempo Médio</h4>
                        <p class="text-2xl font-bold text-slate-800 mt-1"><?= $avg_time_str ?></p>
                        <p class="text-xs text-slate-400 font-medium">Para conclusão</p>
                    </div>
                    <div class="bg-white/80 backdrop-blur-md border border-white/80 p-5 rounded-3xl shadow-sm text-center">
                        <i class="ph-fill ph-warning-circle text-3xl text-senai-orange mb-1"></i>
                        <h4 class="text-xs font-bold text-slate-500 uppercase tracking-widest">Alunos em Risco</h4>
                        <p class="text-2xl font-bold text-red-500 mt-1"><?= $health_low ?></p>
                        <p class="text-xs text-slate-400 font-medium">Com nota abaixo de 50%</p>
                    </div>
                </div>

                <!-- SECTION 1: RANKING E SAÚDE DA TURMA -->
                <details class="group bg-white/80 backdrop-blur-md border border-white/80 rounded-3xl shadow-sm mb-6 animate-slide-up page-break" open>
                    <summary class="p-6 cursor-pointer select-none flex items-center justify-between outline-none hover:bg-slate-50/50 rounded-3xl transition-colors">
                        <div class="flex items-center gap-3">
                            <i class="ph-fill ph-trophy text-2xl text-senai-orange"></i>
                            <h3 class="text-lg font-bold text-slate-800">Ranking e Saúde da Turma</h3>
                        </div>
                        <i class="ph-bold ph-caret-down text-slate-400 group-open:rotate-180 transition-transform"></i>
                    </summary>
                    <div class="p-6 pt-0 border-t border-slate-100 mt-2">
                        
                        <!-- Barra de Saúde -->
                        <div class="flex flex-col mb-6 mt-4">
                            <span class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-2">Curva de Desempenho Geral</span>
                            <div class="flex h-4 rounded-full overflow-hidden mb-2">
                                <?php 
                                    $h_high_pct = $total_students_started ? ($health_high / $total_students_started) * 100 : 0;
                                    $h_med_pct = $total_students_started ? ($health_med / $total_students_started) * 100 : 0;
                                    $h_low_pct = $total_students_started ? ($health_low / $total_students_started) * 100 : 0;
                                ?>
                                <div class="bg-green-500" style="width: <?= $h_high_pct ?>%" title="Alto Desempenho (>= 70%)"></div>
                                <div class="bg-yellow-400" style="width: <?= $h_med_pct ?>%" title="Atenção (50% - 69%)"></div>
                                <div class="bg-red-500" style="width: <?= $h_low_pct ?>%" title="Em Risco (< 50%)"></div>
                            </div>
                            <div class="flex justify-between text-xs font-bold">
                                <span class="text-green-600">🟢 Alto Desempenho (<?= $health_high ?>)</span>
                                <span class="text-yellow-600">🟡 Atenção (<?= $health_med ?>)</span>
                                <span class="text-red-500">🔴 Em Risco (<?= $health_low ?>)</span>
                            </div>
                        </div>

                        <?php if (empty($ranking)): ?>
                            <div class="flex items-center justify-center text-slate-400 py-10">Nenhum dado de resposta ainda.</div>
                        <?php else: ?>
                            <div class="w-full">
                                <table class="w-full text-left border-collapse">
                                    <thead>
                                        <tr class="border-b border-slate-200 text-xs uppercase tracking-widest text-slate-400">
                                            <th class="pb-3 font-semibold w-12 text-center">Pos</th>
                                            <th class="pb-3 font-semibold">Aluno</th>
                                            <th class="pb-3 font-semibold text-center">Acertos</th>
                                            <th class="pb-3 font-semibold text-center">Respondidas</th>
                                            <th class="pb-3 font-semibold text-center">Tempo</th>
                                            <th class="pb-3 font-semibold text-right">Taxa</th>
                                            <th class="pb-3 font-semibold text-center w-24">Ação</th>
                                        </tr>
                                    </thead>
                                    <tbody class="text-sm">
                                        <?php foreach($ranking as $index => $r): 
                                            $isPodium = $index < 3;
                                            $medalColor = '';
                                            if($index === 0) $medalColor = 'text-yellow-500';
                                            elseif($index === 1) $medalColor = 'text-slate-400';
                                            elseif($index === 2) $medalColor = 'text-amber-600';
                                            
                                            $r_rate = ($r['total_correct'] / max(1, $total_qs_possible)) * 100;
                                            if ($r_rate >= 70) $badge = '<span class="bg-green-100 text-green-700 px-2 py-1 rounded text-[10px] uppercase font-bold w-16 text-center">Alto</span>';
                                            elseif ($r_rate >= 50) $badge = '<span class="bg-yellow-100 text-yellow-700 px-2 py-1 rounded text-[10px] uppercase font-bold w-16 text-center">Atenção</span>';
                                            else $badge = '<span class="bg-red-100 text-red-700 px-2 py-1 rounded text-[10px] uppercase font-bold w-16 text-center">Risco</span>';
                                            
                                            $time_str = "N/A";
                                            if ($r['started_at'] && $r['completed_at']) {
                                                $diff = strtotime($r['completed_at']) - strtotime($r['started_at']);
                                                if ($diff > 0) {
                                                    $mins = floor($diff / 60);
                                                    $secs = $diff % 60;
                                                    $time_str = $mins > 0 ? "{$mins}m {$secs}s" : "{$secs}s";
                                                }
                                            } elseif ($r['started_at']) {
                                                $time_str = "Em andamento";
                                            }
                                        ?>
                                        <tr class="border-b border-slate-50 hover:bg-slate-50/50 <?= $isPodium ? 'bg-orange-50/30' : '' ?>">
                                            <td class="py-3 font-bold text-lg text-center <?= $medalColor ?: 'text-slate-400' ?>"><?= $index + 1 ?>º</td>
                                            <td class="py-3 font-bold text-slate-700 flex items-center gap-3">
                                                <div class="w-8 h-8 rounded-full bg-senai-blue text-white flex items-center justify-center text-xs font-bold shrink-0">
                                                    <?= substr($r['student_name'], 0, 1) ?>
                                                </div>
                                                <?= htmlspecialchars($r['student_name']) ?>
                                            </td>
                                            <td class="py-3 text-center font-bold text-senai-blue"><?= $r['total_correct'] ?></td>
                                            <td class="py-3 text-center text-slate-400 font-medium"><?= $r['total_answered'] ?>/<?= $total_qs_possible ?></td>
                                            <td class="py-3 text-center text-slate-500 font-medium text-xs"><i class="ph ph-clock mr-1"></i><?= $time_str ?></td>
                                            <td class="py-3 text-right">
                                                <div class="flex items-center justify-end gap-2">
                                                    <span class="font-bold text-slate-700"><?= round($r_rate, 1) ?>%</span>
                                                    <?= $badge ?>
                                                </div>
                                            </td>
                                            <td class="py-3 text-center">
                                                <div class="flex items-center justify-center gap-1">
                                                    <button type="button" onclick="openStudentModal(<?= $r['student_id'] ?>, '<?= htmlspecialchars($r['student_name'], ENT_QUOTES) ?>')" class="text-senai-blue bg-blue-50 hover:bg-blue-100 px-3 py-1.5 rounded-lg text-xs font-bold transition-colors w-full" title="Ver respostas e Raio-X">Detalhes</button>
                                                    <?php if ($r['completed_at']): ?>
                                                    <a href="sprint_report_pdf.php?attempt_id=<?= $r['attempt_id'] ?>" target="_blank" class="text-senai-orange bg-orange-50 hover:bg-orange-100 w-8 h-8 flex items-center justify-center rounded-lg transition-colors flex-shrink-0" title="Gerar Relatório A4 em PDF">
                                                        <i class="ph-bold ph-printer text-base"></i>
                                                    </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </details>

                <!-- SECTION 2: DESEMPENHO POR MÓDULO -->
                <details class="group bg-white/80 backdrop-blur-md border border-white/80 rounded-3xl shadow-sm mb-6 animate-slide-up page-break">
                    <summary class="p-6 cursor-pointer select-none flex items-center justify-between outline-none hover:bg-slate-50/50 rounded-3xl transition-colors">
                        <div class="flex items-center gap-3">
                            <i class="ph-fill ph-books text-2xl text-senai-blue"></i>
                            <h3 class="text-lg font-bold text-slate-800">Desempenho por Unidade Curricular (Módulos)</h3>
                        </div>
                        <i class="ph-bold ph-caret-down text-slate-400 group-open:rotate-180 transition-transform"></i>
                    </summary>
                    <div class="p-6 pt-0 border-t border-slate-100 mt-2 overflow-x-auto">
                        <table class="w-full text-left border-collapse mt-4">
                            <thead>
                                <tr class="border-b border-slate-200 text-xs uppercase tracking-widest text-slate-400">
                                    <th class="pb-3 font-semibold">Módulo</th>
                                    <th class="pb-3 font-semibold text-center">Respostas</th>
                                    <th class="pb-3 font-semibold text-center text-green-600">Acertos</th>
                                    <th class="pb-3 font-semibold text-center text-red-500">Erros</th>
                                    <th class="pb-3 font-semibold text-right">Taxa de Acerto</th>
                                </tr>
                            </thead>
                            <tbody class="text-sm">
                                <?php foreach($modules_perf as $m): 
                                    $tot = $m['total_answers'];
                                    $cor = $m['correct_answers'];
                                    $err = $tot - $cor;
                                    $pct = $tot > 0 ? round(($cor / $tot) * 100, 1) : 0;
                                ?>
                                <tr class="border-b border-slate-50 hover:bg-slate-50/50">
                                    <td class="py-3 font-bold text-slate-700"><?= htmlspecialchars($m['module_name']) ?></td>
                                    <td class="py-3 text-center font-medium text-slate-500"><?= $tot ?></td>
                                    <td class="py-3 text-center font-bold text-green-600"><?= $cor ?></td>
                                    <td class="py-3 text-center font-bold text-red-500"><?= $err ?></td>
                                    <td class="py-3 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <div class="w-32 h-2 bg-slate-100 rounded-full overflow-hidden">
                                                <div class="h-full <?= $pct < 50 ? 'bg-red-500' : ($pct < 75 ? 'bg-yellow-500' : 'bg-green-500') ?>" style="width: <?= $pct ?>%"></div>
                                            </div>
                                            <span class="font-bold w-12 text-right"><?= $pct ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>

                <!-- SECTION 3: GRÁFICO COMPLETO DE CAPACIDADES -->
                <details class="group bg-white/80 backdrop-blur-md border border-white/80 rounded-3xl shadow-sm mb-6 animate-slide-up page-break">
                    <summary class="p-6 cursor-pointer select-none flex items-center justify-between outline-none hover:bg-slate-50/50 rounded-3xl transition-colors">
                        <div class="flex items-center gap-3">
                            <i class="ph-fill ph-chart-polar text-2xl text-senai-cyan"></i>
                            <h3 class="text-lg font-bold text-slate-800">Gráfico de Capacidades Completo</h3>
                        </div>
                        <i class="ph-bold ph-caret-down text-slate-400 group-open:rotate-180 transition-transform"></i>
                    </summary>
                    <div class="p-6 pt-0 border-t border-slate-100 mt-2">
                        <?php $chartHeight = max(300, count($labels) * 35); ?>
                        <div class="relative w-full mt-4" style="height: <?= $chartHeight ?>px;">
                            <canvas id="capacityChart"></canvas>
                        </div>
                    </div>
                </details>

                <!-- SECTION 4: DESTAQUES E ALERTAS -->
                <details class="group bg-white/80 backdrop-blur-md border border-white/80 rounded-3xl shadow-sm mb-6 animate-slide-up page-break" open>
                    <summary class="p-6 cursor-pointer select-none flex items-center justify-between outline-none hover:bg-slate-50/50 rounded-3xl transition-colors">
                        <div class="flex items-center gap-3">
                            <i class="ph-fill ph-star text-2xl text-yellow-500"></i>
                            <h3 class="text-lg font-bold text-slate-800">Destaques e Deficiências</h3>
                        </div>
                        <i class="ph-bold ph-caret-down text-slate-400 group-open:rotate-180 transition-transform"></i>
                    </summary>
                    <div class="p-6 pt-0 border-t border-slate-100 mt-2">
                        
                        <div class="mb-8 mt-4">
                            <h4 class="font-bold text-slate-800 mb-4 flex items-center gap-2"><i class="ph-bold ph-list-numbers text-senai-blue"></i> Ranking Geral de Capacidades</h4>
                            <div class="space-y-3">
                                <?php foreach($capacities_processed as $c): 
                                    $cRate = round($c['rate'], 1);
                                    $barColor = $cRate >= 70 ? 'bg-green-500' : ($cRate >= 50 ? 'bg-yellow-500' : 'bg-red-500');
                                    $textColor = $cRate >= 70 ? 'text-green-600' : ($cRate >= 50 ? 'text-yellow-600' : 'text-red-500');
                                ?>
                                    <div class="bg-white border border-slate-100 p-3 rounded-xl flex items-center gap-4 shadow-sm hover:bg-slate-50 transition-colors">
                                        <div class="w-1/3 pr-4">
                                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wide mb-0.5"><?= htmlspecialchars($c['module_name'] ?: 'Geral') ?></p>
                                            <p class="font-bold text-slate-700 text-sm line-clamp-2" title="<?= htmlspecialchars($c['capacity_desc'] ?? $c['capacity_code']) ?>"><?= htmlspecialchars($c['capacity_code']) ?> - <?= htmlspecialchars($c['capacity_desc'] ?? '') ?></p>
                                        </div>
                                        <div class="flex-1 bg-slate-100 h-3 rounded-full overflow-hidden">
                                            <div class="<?= $barColor ?> h-full transition-all duration-1000" style="width: <?= $cRate ?>%"></div>
                                        </div>
                                        <span class="font-black text-lg w-16 text-right <?= $textColor ?>"><?= $cRate ?>%</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Separação -->
                        <div class="h-px w-full bg-slate-100 mb-8"></div>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                            <!-- Top 3 Questions -->
                            <div>
                                <h4 class="font-bold text-green-600 mb-4 flex items-center gap-2"><i class="ph-bold ph-check-circle"></i> As 3 Questões MiaS Fáceis</h4>
                                <div class="space-y-3">
                                    <?php foreach($top_questions as $i => $tq): ?>
                                        <div class="bg-green-50 border border-green-100 p-3 rounded-xl flex items-center justify-between">
                                            <div class="flex-1 pr-4">
                                                <p class="font-bold text-slate-700 text-sm line-clamp-3"><?= htmlspecialchars($tq['command']) ?></p>
                                            </div>
                                            <span class="font-black text-lg text-green-600"><?= round($tq['rate'],1) ?>%</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <!-- Bottom 3 Questions -->
                            <div>
                                <h4 class="font-bold text-red-500 mb-4 flex items-center gap-2"><i class="ph-bold ph-warning"></i> As 3 Questões MiaS Difíceis</h4>
                                <div class="space-y-3">
                                    <?php foreach($bottom_questions as $i => $bq): ?>
                                        <div class="bg-red-50 border border-red-100 p-3 rounded-xl flex items-center justify-between">
                                            <div class="flex-1 pr-4">
                                                <p class="font-bold text-slate-700 text-sm line-clamp-3"><?= htmlspecialchars($bq['command']) ?></p>
                                            </div>
                                            <span class="font-black text-lg text-red-600"><?= round($bq['rate'],1) ?>%</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                    </div>
                </details>

                <!-- SECTION 5: ANÁLISE DETALHADA E DISTRATORES -->
                <details class="group bg-white/80 backdrop-blur-md border border-white/80 rounded-3xl shadow-sm mb-8 animate-slide-up page-break">
                    <summary class="p-6 cursor-pointer select-none flex items-center justify-between outline-none hover:bg-slate-50/50 rounded-3xl transition-colors">
                        <div class="flex items-center gap-3">
                            <i class="ph-fill ph-list-numbers text-2xl text-senai-blue"></i>
                            <h3 class="text-lg font-bold text-slate-800">Análise Detalhada Questão a Questão</h3>
                        </div>
                        <i class="ph-bold ph-caret-down text-slate-400 group-open:rotate-180 transition-transform"></i>
                    </summary>
                    <div class="p-6 pt-0 border-t border-slate-100 mt-2 overflow-x-auto">
                        <table class="w-full text-left border-collapse mt-4">
                            <thead>
                                <tr class="border-b border-slate-200 text-xs uppercase tracking-widest text-slate-400">
                                    <th class="pb-3 font-semibold w-12">Nº</th>
                                    <th class="pb-3 font-semibold">Questão / Capacidade / Módulo</th>
                                    <th class="pb-3 font-semibold text-center text-green-600">Acertos</th>
                                    <th class="pb-3 font-semibold text-center text-red-500">Erros</th>
                                    <th class="pb-3 font-semibold text-right">Desempenho</th>
                                </tr>
                            </thead>
                            <tbody class="text-sm">
                                <?php 
                                $qnum = 1;
                                foreach($questions_perf as $q): 
                                    $tot = $q['total_answers'];
                                    $cor = $q['correct_answers'];
                                    $err = $tot - $cor;
                                    $pct = $tot > 0 ? round(($cor / $tot) * 100, 1) : 0;
                                    $isCritical = $pct < 50 && $tot > 0;
                                ?>
                                <tr class="border-b border-slate-50 hover:bg-slate-50/50 <?= $isCritical ? 'bg-red-50/30' : '' ?>">
                                    <td class="py-4 font-bold text-slate-500 align-top"><?= $qnum++ ?></td>
                                    <td class="py-4 pr-4 align-top">
                                        <p class="font-bold text-slate-700 mb-2 leading-relaxed" title="<?= htmlspecialchars($q['command']) ?>"><?= nl2br(htmlspecialchars($q['command'])) ?></p>
                                        <div class="text-xs text-slate-500 font-medium flex flex-wrap items-center gap-2">
                                            <span class="bg-slate-200 px-2 py-1 rounded text-senai-blue font-bold"><?= htmlspecialchars($q['module_name'] ?: 'Geral') ?></span>
                                            <span class="font-bold text-slate-700"><?= htmlspecialchars($q['capacity']) ?></span>
                                            <span class="text-slate-400 text-[10px] uppercase line-clamp-1 flex-1"><?= htmlspecialchars($q['capacity_desc'] ?? 'Sem descrição') ?></span>
                                        </div>

                                        <!-- Pegadinha (Distrator) -->
                                        <?php if ($isCritical && isset($distractors[$q['id']][0])): ?>
                                            <div class="mt-3 text-xs bg-red-100/70 p-3 rounded-xl border border-red-200 inline-block w-full">
                                                <strong class="text-red-700 flex items-center gap-1.5 mb-1.5">
                                                    <i class="ph-bold ph-warning"></i> Principal Pegadinha (Distrator miaS marcado):
                                                </strong>
                                                <div class="bg-white/80 p-2 rounded-lg text-slate-800 font-medium border border-white">
                                                    <?= htmlspecialchars($distractors[$q['id']][0]['option_text']) ?>
                                                </div>
                                                <div class="mt-1.5 text-red-600 font-bold">
                                                    <?= $distractors[$q['id']][0]['chosen_count'] ?> alunos caíram nessa alternativa
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                    </td>
                                    <td class="py-4 text-center font-bold text-green-600 align-top"><?= $cor ?></td>
                                    <td class="py-4 text-center font-bold text-red-500 align-top"><?= $err ?></td>
                                    <td class="py-4 text-right align-top">
                                        <div class="flex flex-col items-end gap-2">
                                            <div class="inline-block px-3 py-1.5 rounded-lg text-sm font-black shadow-sm <?= $isCritical ? 'bg-red-500 text-white' : 'bg-slate-100 text-slate-700' ?>">
                                                <?= $pct ?>%
                                                <?php if($isCritical): ?>
                                                    <i class="ph-fill ph-warning ml-1"></i>
                                                <?php endif; ?>
                                            </div>
                                            <a href="?id=<?= $sprint_id ?>&remove_q=<?= $q['id'] ?>" onclick="return confirm('Remover esta questão anulará as respostas dadas a ela e reajustará todas as notas dos alunos. Confirmar?')" class="text-slate-400 hover:text-red-500 transition-colors mt-2 text-xs flex items-center gap-1 font-semibold" title="Remover e anular">
                                                <i class="ph-bold ph-trash"></i> Anular
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>

            </div>
        </div>
    </main>
</div>

</div>

<!-- MODAL RAIO-X DO ALUNO -->
<div id="student-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm hidden opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-4xl mx-4 overflow-hidden transform scale-95 transition-transform duration-300 flex flex-col max-h-[90vh]">
        <!-- Modal Header -->
        <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-2xl bg-senai-blue/10 flex items-center justify-center text-senai-blue">
                    <i class="ph-bold ph-user-focus text-2xl"></i>
                </div>
                <div>
                    <h3 id="modal-student-name" class="text-xl font-bold text-slate-800">Carregando...</h3>
                    <p class="text-sm text-slate-500 font-medium">Raio-X de Respostas da Sprint</p>
                </div>
            </div>
            <button onclick="closeStudentModal()" class="w-10 h-10 flex items-center justify-center rounded-xl hover:bg-slate-200 text-slate-400 hover:text-slate-700 transition-colors">
                <i class="ph-bold ph-x text-xl"></i>
            </button>
        </div>
        
        <!-- Modal Body -->
        <div class="p-6 overflow-y-auto flex-1 relative bg-[#F8FAFC]">
            <div id="modal-loader" class="absolute inset-0 flex flex-col items-center justify-center bg-[#F8FAFC] z-10">
                <div class="w-10 h-10 border-4 border-senai-blue border-t-transparent rounded-full animate-spin mb-4"></div>
                <p class="text-senai-blue font-bold">Buscando respostas...</p>
            </div>
            
            <div id="modal-content" class="space-y-4">
                <!-- Conteúdo gerado via AJAX -->
            </div>
        </div>
        
        <!-- Modal Footer -->
        <div class="px-6 py-4 border-t border-slate-100 bg-white flex justify-end">
            <button onclick="closeStudentModal()" class="px-6 py-2.5 rounded-xl font-bold bg-slate-100 hover:bg-slate-200 text-slate-700 transition-colors">Fechar</button>
        </div>
    </div>
</div>

<script>
    function openStudentModal(studentId, studentName) {
        const modal = document.getElementById('student-modal');
        const loader = document.getElementById('modal-loader');
        const content = document.getElementById('modal-content');
        
        document.getElementById('modal-student-name').innerText = studentName;
        
        modal.classList.remove('hidden');
        // trigger reflow
        void modal.offsetWidth;
        modal.classList.remove('opacity-0');
        modal.querySelector('div').classList.remove('scale-95');
        
        loader.classList.remove('hidden');
        content.innerHTML = '';
        
        fetch(`sprint_student_answers_ajax.php?sprint_id=<?= $sprint_id ?>&student_id=${studentId}`)
            .then(res => res.text())
            .then(html => {
                loader.classList.add('hidden');
                content.innerHTML = html;
            })
            .catch(err => {
                loader.classList.add('hidden');
                content.innerHTML = '<div class="text-center text-red-500 font-bold p-10">Erro ao carregar detalhes.</div>';
            });
    }
    
    function closeStudentModal() {
        const modal = document.getElementById('student-modal');
        modal.classList.add('opacity-0');
        modal.querySelector('div').classList.add('scale-95');
        setTimeout(() => {
            modal.classList.add('hidden');
        }, 300);
    }
</script>

<script>
    // Inicializar Gráfico
    const ctx = document.getElementById('capacityChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($labels, JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]' ?>,
            datasets: [
                {
                    label: 'Acertos',
                    data: <?= json_encode($data_correct) ?: '[]' ?>,
                    backgroundColor: '#1A428A',
                    borderRadius: 4
                },
                {
                    label: 'Erros',
                    data: <?= json_encode($data_wrong) ?: '[]' ?>,
                    backgroundColor: '#F25C27',
                    borderRadius: 4
                }
            ]
        },
        options: {
            indexAxis: 'y', // Transforma em barras horizontiaS
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { beginAtZero: true, stacked: true },
                y: { stacked: true }
            },
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });

    // Chamada IA
    function generateAIPlan() {
        const btn = document.getElementById('ai-btn');
        const mod = document.getElementById('ai-module');
        const load = document.getElementById('ai-loading');
        const cont = document.getElementById('ai-content');
        
        mod.classList.remove('hidden');
        btn.disabled = true;
        btn.classList.add('opacity-50');

        fetch('ai_suggest.php?capacity=<?= urlencode($worst_capacity) ?>')
            .then(res => res.json())
            .then(data => {
                load.classList.add('hidden');
                cont.classList.remove('hidden');
                
                if(data.error) {
                    cont.innerHTML = `<div class="text-red-500 font-bold">Erro: ${data.error}</div>`;
                } else {
                    cont.innerHTML = marked.parse(data.suggestion);
                }
            })
            .catch(err => {
                load.classList.add('hidden');
                cont.classList.remove('hidden');
                cont.innerHTML = `<div class="text-red-500 font-bold">Falha na comunicação com a API.</div>`;
            });
    }
</script>

<?php include 'includes/footer.php'; ?>
