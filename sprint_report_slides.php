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
    die("Sprint ID não informado.");
}

// 1. Dados Básicos da Sprint
if ($_SESSION['user_role'] === 'admin') {
    $stmt = $pdo->prepare("SELECT s.*, c.name as class_name FROM sprints s JOIN classes c ON s.class_id = c.id WHERE s.id = ?");
    $stmt->execute([$sprint_id]);
} else {
    $stmt = $pdo->prepare("SELECT s.*, c.name as class_name FROM sprints s JOIN classes c ON s.class_id = c.id WHERE s.id = ? AND s.teacher_id = ?");
    $stmt->execute([$sprint_id, $_SESSION['user_id']]);
}
$sprint = $stmt->fetch();
if (!$sprint) {
    die("Sprint não encontrada ou sem permissão.");
}

$stmtQCount = $pdo->prepare("SELECT COUNT(*) FROM sprint_questions WHERE sprint_id = ?");
$stmtQCount->execute([$sprint_id]);
$total_qs_possible = max(1, (int)$stmtQCount->fetchColumn());

// 2. Ranking de Alunos
$stmtRanking = $pdo->prepare("
    SELECT u.name as student_name, 
           COUNT(sa.id) as total_answered,
           SUM(IF(sa.is_correct = 1, 1, 0)) as total_correct
    FROM sprint_attempts a
    JOIN users u ON a.student_id = u.id
    LEFT JOIN student_answers sa ON sa.sprint_id = a.sprint_id AND sa.student_id = a.student_id
    WHERE a.sprint_id = ?
    GROUP BY u.id
    ORDER BY total_correct DESC, total_answered DESC
");
$stmtRanking->execute([$sprint_id]);
$ranking = $stmtRanking->fetchAll();

$ranking_processed = [];
foreach($ranking as $r) {
    $r['rate'] = ($total_qs_possible > 0) ? round(($r['total_correct'] / $total_qs_possible) * 100, 1) : 0;
    $ranking_processed[] = $r;
}
$top_students = array_slice($ranking_processed, 0, 5);
$bottom_students = array_slice(array_reverse($ranking_processed), 0, 5);

$total_students_started = count($ranking_processed);
$avg_score = 0;
$avg_percent = 0;
if ($total_students_started > 0) {
    $sum_correct = array_sum(array_column($ranking, 'total_correct'));
    $avg_score = round($sum_correct / $total_students_started, 1);
    $avg_percent = round(($avg_score / $total_qs_possible) * 100, 1);
}

// 3. Módulos
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

$modules_processed = [];
foreach ($modules_perf as $m) {
    $m['rate'] = ($m['total_answers'] > 0) ? ($m['correct_answers'] / $m['total_answers']) * 100 : 0;
    $modules_processed[] = $m;
}
usort($modules_processed, function($a, $b) { return $b['rate'] <=> $a['rate']; });
$top_modules = array_slice($modules_processed, 0, 3);
$bottom_modules = array_slice(array_reverse($modules_processed), 0, 3);

// 4. Capacidades
$stmtCap = $pdo->prepare("
    SELECT q.capacity as capacity_code, 
           mc.description as capacity_desc,
           COUNT(sa.id) as total_answers,
           SUM(IF(sa.is_correct = 1, 1, 0)) as correct_answers
    FROM sprint_questions sq
    JOIN questions q ON sq.question_id = q.id
    LEFT JOIN module_capacities mc ON mc.capacity_code = q.capacity AND mc.module_id = q.module_id
    LEFT JOIN student_answers sa ON sa.question_id = q.id AND sa.sprint_id = sq.sprint_id
    WHERE sq.sprint_id = ?
    GROUP BY q.capacity, mc.description
");
$stmtCap->execute([$sprint_id]);
$capacities = $stmtCap->fetchAll();

$capacities_processed = [];
foreach ($capacities as $c) {
    $c['rate'] = ($c['total_answers'] > 0) ? ($c['correct_answers'] / $c['total_answers']) * 100 : 0;
    $capacities_processed[] = $c;
}
usort($capacities_processed, function($a, $b) { return $b['rate'] <=> $a['rate']; });
$top_capacities = array_slice($capacities_processed, 0, 3);
$bottom_capacities = array_slice(array_reverse($capacities_processed), 0, 3);

// 5. Questões
$stmtQ = $pdo->prepare("
    SELECT q.id, q.command, q.capacity, m.name as module_name,
           COUNT(sa.id) as total_answers,
           SUM(IF(sa.is_correct = 1, 1, 0)) as correct_answers
    FROM sprint_questions sq
    JOIN questions q ON sq.question_id = q.id
    JOIN modules m ON q.module_id = m.id
    LEFT JOIN student_answers sa ON sa.question_id = q.id AND sa.sprint_id = sq.sprint_id
    WHERE sq.sprint_id = ?
    GROUP BY q.id
    ORDER BY sq.order_num ASC
");
$stmtQ->execute([$sprint_id]);
$questions_perf = $stmtQ->fetchAll();

$questions_processed = [];
foreach ($questions_perf as $q) {
    $q['rate'] = ($q['total_answers'] > 0) ? ($q['correct_answers'] / $q['total_answers']) * 100 : 0;
    $questions_processed[] = $q;
}

// Data For AI
$ai_data = [
    'sprint_name' => $sprint['name'],
    'class_name' => $sprint['class_name'],
    'avg_score' => $avg_score,
    'total_students' => $total_students_started,
    'total_questions' => $total_qs_possible,
    'worst_capacities' => $bottom_capacities,
    'best_capacities' => $top_capacities
];

$slideNumber = 1;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório Executivo - <?= htmlspecialchars($sprint['name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background-color: #e5e7eb; display: flex; flex-direction: column; align-items: center; min-height: 100vh; font-family: 'Inter', sans-serif; gap: 20px; padding: 20px 0; }

        @media print {
            body { background-color: #ffffff; display: block; padding: 0; }
            .slide-container { box-shadow: none !important; margin: 0 !important; width: 100% !important; height: 100vh !important; page-break-after: always; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            @page { size: 1280px 720px; margin: 0mm; }
            #loading-overlay { display: none !important; }
        }

        .slide-container {
            width: 1280px; height: 720px; background-color: #ffffff; position: relative; box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15); overflow: hidden;
            max-width: 100vw; max-height: 56.25vw; flex-shrink: 0;
        }

        .sidebar { position: absolute; top: 0; left: 0; width: 56px; height: 100%; z-index: 10; }
        .shape-orange { position: absolute; top: 0; left: 0; width: 100%; height: 220px; background-color: #F25922; clip-path: polygon(0 0, 100% 0, 100% 150px, 0 100%); }
        .shape-blue { position: absolute; top: 170px; left: 0; width: 100%; height: calc(100% - 170px); background-color: #0038A8; clip-path: polygon(0 70px, 100% 0, 100% 100%, 0 100%); }
        
        .logo-container { position: absolute; top: 45px; right: 60px; z-index: 20; }
        .logo-container img { height: 48px; width: auto; object-fit: contain; }

        /* Aumentando o espaço útil (bottom de 100 para 80) */
        .content-area { position: absolute; top: 130px; left: 120px; right: 120px; bottom: 80px; display: flex; flex-direction: column; z-index: 5; }
        
        /* Ajuste de tipografia para caber mais texto sem estourar */
        .slide-title { color: #0038A8; font-size: 42px; font-weight: 800; margin-bottom: 8px; line-height: 1.1; letter-spacing: -1px; }
        .slide-subtitle { color: #F25922; font-size: 24px; font-weight: 600; margin-bottom: 24px; text-transform: uppercase; letter-spacing: 1px; }
        
        .slide-footer { position: absolute; bottom: 25px; left: 120px; right: 60px; display: flex; justify-content: space-between; color: #9CA3AF; font-size: 13px; font-weight: 600; z-index: 5; }

        .metric-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; text-align: center; }
        .metric-value { font-size: 40px; font-weight: 800; color: #0038A8; line-height: 1; margin-bottom: 8px; }
        .metric-label { font-size: 14px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 1px; }

        /* Loader */
        .loader { border: 4px solid #f3f3f3; border-top: 4px solid #F25922; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite; display: inline-block; margin-right: 10px; vertical-align: middle; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        /* Ajustes de tabelas e listas para melhor densidade */
        .dense-table th { padding: 8px 12px; font-size: 12px; }
        .dense-table td { padding: 8px 12px; font-size: 14px; }
    </style>
</head>
<body>

    <div id="loading-overlay" class="fixed inset-0 bg-slate-900/90 z-50 flex flex-col items-center justify-center text-white font-bold text-xl backdrop-blur-sm">
        <div class="loader mb-4" style="width: 60px; height: 60px; border-width: 6px;"></div>
        <p>A Inteligência Artificial está analisando os dados da turma...</p>
        <p class="text-slate-400 text-sm mt-2 font-normal">A tela de impressão abrirá automaticamente assim que a IA terminar.</p>
    </div>

    <!-- SLIDE 1: CAPA -->
    <div class="slide-container">
        <div class="sidebar"><div class="shape-orange"></div><div class="shape-blue"></div></div>
        <div class="logo-container"><img src="logo.png" alt="Logo SENAI" onerror="this.style.display='none'"></div>
        
        <div class="content-area justify-center items-center text-center">
            <h2 class="text-xl font-bold text-slate-400 mb-2 tracking-widest uppercase">Relatório Executivo</h2>
            <h1 class="slide-title" style="font-size: 64px; margin-bottom: 15px;"><?= htmlspecialchars($sprint['name']) ?></h1>
            <h2 class="slide-subtitle" style="font-size: 32px;">Turma: <?= htmlspecialchars($sprint['class_name']) ?></h2>
            <div class="w-24 h-2 bg-[#F25922] mt-4 mb-6 mx-auto"></div>
            <p class="text-lg text-slate-500 font-medium">Análise de Desempenho Pedagógico</p>
        </div>
        
        <div class="slide-footer">
            <span>DEPARTAMENTO REGIONAL - EDUCAÇÃO PROFISSIONAL</span>
            <span><?= sprintf("%02d", $slideNumber++) ?></span>
        </div>
    </div>

    <!-- SLIDE 2: SAÚDE DA TURMA -->
    <div class="slide-container">
        <div class="sidebar"><div class="shape-orange"></div><div class="shape-blue"></div></div>
        <div class="logo-container"><img src="logo.png" alt="Logo SENAI" onerror="this.style.display='none'"></div>
        
        <div class="content-area">
            <h1 class="slide-title">Saúde da Turma</h1>
            <h2 class="slide-subtitle">Indicadores Gerais da Avaliação</h2>
            
            <div class="grid grid-cols-3 gap-6 mt-8">
                <div class="metric-card">
                    <div class="metric-value"><?= $avg_percent ?>%</div>
                    <div class="metric-label">Taxa Média de Acerto</div>
                </div>
                <div class="metric-card">
                    <div class="metric-value"><?= $total_students_started ?></div>
                    <div class="metric-label">Alunos Avaliados</div>
                </div>
                <div class="metric-card">
                    <div class="metric-value"><?= $total_qs_possible ?></div>
                    <div class="metric-label">Questões na Prova</div>
                </div>
            </div>
            
            <div class="mt-10 bg-blue-50 border-l-4 border-[#0038A8] p-5 rounded-r-xl">
                <h4 class="font-bold text-[#0038A8] text-lg mb-1 flex items-center gap-2">Média da Turma: <?= $avg_score ?> / <?= $total_qs_possible ?> acertos</h4>
                <p class="text-slate-600 text-sm">A métrica principal indica o aproveitamento global. Taxas abaixo de 60% exigem revisão imediata dos conceitos base.</p>
            </div>
        </div>
        
        <div class="slide-footer">
            <span><?= htmlspecialchars($sprint['name']) ?></span>
            <span><?= sprintf("%02d", $slideNumber++) ?></span>
        </div>
    </div>

    <!-- SLIDE 3: RANKING LADO A LADO -->
    <div class="slide-container">
        <div class="sidebar"><div class="shape-orange"></div><div class="shape-blue"></div></div>
        <div class="logo-container"><img src="logo.png" alt="Logo SENAI" onerror="this.style.display='none'"></div>
        
        <div class="content-area">
            <h1 class="slide-title">Desempenho dos Alunos</h1>
            <h2 class="slide-subtitle">Destaques Positivos vs Pontos de Atenção</h2>
            
            <div class="flex gap-6 mt-2">
                <!-- Top 5 -->
                <div class="flex-1">
                    <h3 class="font-bold text-green-700 uppercase text-xs mb-2 tracking-wider flex items-center gap-1"><i class="ph-bold ph-trend-up"></i> Top 5 Alunos (Positivos)</h3>
                    <div class="overflow-hidden rounded-xl border border-slate-200 shadow-sm bg-white">
                        <table class="w-full text-left dense-table">
                            <thead class="bg-slate-50 text-slate-600 font-bold uppercase tracking-wider">
                                <tr>
                                    <th class="w-12 text-center">Pos</th>
                                    <th>Aluno</th>
                                    <th class="text-center w-20">Acertos</th>
                                    <th class="text-right w-16">Taxa</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php $rank = 1; foreach($top_students as $r): ?>
                                <tr>
                                    <td class="text-center font-bold text-slate-400"><?= $rank ?></td>
                                    <td class="font-bold text-slate-800 truncate max-w-[150px]" title="<?= htmlspecialchars($r['student_name']) ?>"><?= htmlspecialchars($r['student_name']) ?></td>
                                    <td class="text-center font-semibold text-[#0038A8]"><?= $r['total_correct'] ?>/<?= $total_qs_possible ?></td>
                                    <td class="text-right font-black text-green-600"><?= $r['rate'] ?>%</td>
                                </tr>
                                <?php $rank++; endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Bottom 5 -->
                <div class="flex-1">
                    <h3 class="font-bold text-red-700 uppercase text-xs mb-2 tracking-wider flex items-center gap-1"><i class="ph-bold ph-trend-down"></i> Top 5 Alunos (Atenção)</h3>
                    <div class="overflow-hidden rounded-xl border border-slate-200 shadow-sm bg-white">
                        <table class="w-full text-left dense-table">
                            <thead class="bg-slate-50 text-slate-600 font-bold uppercase tracking-wider">
                                <tr>
                                    <th class="w-12 text-center">Pos</th>
                                    <th>Aluno</th>
                                    <th class="text-center w-20">Acertos</th>
                                    <th class="text-right w-16">Taxa</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php $rank = count($ranking_processed) - count($bottom_students) + 1; foreach($bottom_students as $r): ?>
                                <tr>
                                    <td class="text-center font-bold text-slate-400"><?= $rank ?></td>
                                    <td class="font-bold text-slate-800 truncate max-w-[150px]" title="<?= htmlspecialchars($r['student_name']) ?>"><?= htmlspecialchars($r['student_name']) ?></td>
                                    <td class="text-center font-semibold text-[#0038A8]"><?= $r['total_correct'] ?>/<?= $total_qs_possible ?></td>
                                    <td class="text-right font-black text-red-600"><?= $r['rate'] ?>%</td>
                                </tr>
                                <?php $rank++; endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="slide-footer">
            <span><?= htmlspecialchars($sprint['name']) ?></span>
            <span><?= sprintf("%02d", $slideNumber++) ?></span>
        </div>
    </div>

    <!-- SLIDE 4: MÓDULOS DESTAQUES LADO A LADO -->
    <div class="slide-container">
        <div class="sidebar"><div class="shape-orange"></div><div class="shape-blue"></div></div>
        <div class="logo-container"><img src="logo.png" alt="Logo SENAI" onerror="this.style.display='none'"></div>
        
        <div class="content-area">
            <h1 class="slide-title">Destaques por Módulo</h1>
            <h2 class="slide-subtitle">Análise Comparativa de Unidades Curriculares</h2>
            
            <div class="flex gap-6 mt-2">
                <!-- Top 3 -->
                <div class="flex-1 space-y-4">
                    <h3 class="font-bold text-green-700 uppercase text-xs tracking-wider flex items-center gap-1 border-b border-green-200 pb-2"><i class="ph-fill ph-check-circle"></i> Top 3 Módulos (Força)</h3>
                    <?php foreach($top_modules as $m): ?>
                    <div class="bg-green-50 border border-green-100 p-3 rounded-lg flex items-center justify-between">
                        <div class="font-bold text-green-900 text-sm truncate max-w-[250px]" title="<?= htmlspecialchars($m['module_name']) ?>"><?= htmlspecialchars($m['module_name']) ?></div>
                        <div class="font-black text-green-600 text-lg"><?= round($m['rate'],1) ?>%</div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Bottom 3 -->
                <div class="flex-1 space-y-4">
                    <h3 class="font-bold text-red-700 uppercase text-xs tracking-wider flex items-center gap-1 border-b border-red-200 pb-2"><i class="ph-fill ph-warning-circle"></i> Top 3 Módulos (Atenção)</h3>
                    <?php foreach($bottom_modules as $m): ?>
                    <div class="bg-red-50 border border-red-100 p-3 rounded-lg flex items-center justify-between">
                        <div class="font-bold text-red-900 text-sm truncate max-w-[250px]" title="<?= htmlspecialchars($m['module_name']) ?>"><?= htmlspecialchars($m['module_name']) ?></div>
                        <div class="font-black text-red-600 text-lg"><?= round($m['rate'],1) ?>%</div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <div class="slide-footer">
            <span><?= htmlspecialchars($sprint['name']) ?></span>
            <span><?= sprintf("%02d", $slideNumber++) ?></span>
        </div>
    </div>

    <!-- SLIDES DE TODOS OS MÓDULOS PAGINADOS (8 por slide) -->
    <?php 
    $module_chunks = array_chunk($modules_processed, 8);
    foreach($module_chunks as $chunkIndex => $chunk):
    ?>
    <div class="slide-container">
        <div class="sidebar"><div class="shape-orange"></div><div class="shape-blue"></div></div>
        <div class="logo-container"><img src="logo.png" alt="Logo SENAI" onerror="this.style.display='none'"></div>
        
        <div class="content-area">
            <h1 class="slide-title">Todas as Unidades Curriculares</h1>
            <h2 class="slide-subtitle">Desempenho Geral (Parte <?= $chunkIndex + 1 ?>)</h2>
            
            <div class="flex flex-col gap-2 mt-2">
                <?php foreach($chunk as $m): 
                    $mRate = round($m['rate'], 1);
                    $barColor = $mRate >= 70 ? 'bg-green-500' : ($mRate >= 50 ? 'bg-yellow-500' : 'bg-red-500');
                ?>
                <div class="flex items-center gap-3 bg-slate-50 px-4 py-3 rounded-lg border border-slate-100">
                    <div class="w-1/3 font-bold text-slate-700 text-sm truncate" title="<?= htmlspecialchars($m['module_name']) ?>">
                        <?= htmlspecialchars($m['module_name']) ?>
                    </div>
                    <div class="flex-1 bg-slate-200 h-4 rounded-full overflow-hidden">
                        <div class="<?= $barColor ?> h-full" style="width: <?= $mRate ?>%"></div>
                    </div>
                    <div class="w-16 text-right font-black text-sm text-slate-800">
                        <?= $mRate ?>%
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="slide-footer">
            <span><?= htmlspecialchars($sprint['name']) ?></span>
            <span><?= sprintf("%02d", $slideNumber++) ?></span>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- SLIDE: CAPACIDADES DESTAQUES LADO A LADO -->
    <div class="slide-container">
        <div class="sidebar"><div class="shape-orange"></div><div class="shape-blue"></div></div>
        <div class="logo-container"><img src="logo.png" alt="Logo SENAI" onerror="this.style.display='none'"></div>
        
        <div class="content-area">
            <h1 class="slide-title">Destaques por Capacidade</h1>
            <h2 class="slide-subtitle">Análise Comparativa de Fundamentos Técnicos</h2>
            
            <div class="flex gap-6 mt-2">
                <!-- Top 3 -->
                <div class="flex-1 space-y-4">
                    <h3 class="font-bold text-green-700 uppercase text-xs tracking-wider flex items-center gap-1 border-b border-green-200 pb-2"><i class="ph-fill ph-check-circle"></i> Top 3 Capacidades (Força)</h3>
                    <?php foreach($top_capacities as $c): ?>
                    <div class="bg-green-50 border border-green-100 p-3 rounded-lg flex items-center justify-between">
                        <div class="font-bold text-green-900 text-sm truncate max-w-[250px]" title="<?= htmlspecialchars($c['capacity_desc'] ?: $c['capacity_code']) ?>">
                            <?= htmlspecialchars($c['capacity_code']) ?>
                        </div>
                        <div class="font-black text-green-600 text-lg"><?= round($c['rate'],1) ?>%</div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Bottom 3 -->
                <div class="flex-1 space-y-4">
                    <h3 class="font-bold text-red-700 uppercase text-xs tracking-wider flex items-center gap-1 border-b border-red-200 pb-2"><i class="ph-fill ph-warning-circle"></i> Top 3 Capacidades (Atenção)</h3>
                    <?php foreach($bottom_capacities as $c): ?>
                    <div class="bg-red-50 border border-red-100 p-3 rounded-lg flex items-center justify-between">
                        <div class="font-bold text-red-900 text-sm truncate max-w-[250px]" title="<?= htmlspecialchars($c['capacity_desc'] ?: $c['capacity_code']) ?>">
                            <?= htmlspecialchars($c['capacity_code']) ?>
                        </div>
                        <div class="font-black text-red-600 text-lg"><?= round($c['rate'],1) ?>%</div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <div class="slide-footer">
            <span><?= htmlspecialchars($sprint['name']) ?></span>
            <span><?= sprintf("%02d", $slideNumber++) ?></span>
        </div>
    </div>

    <!-- SLIDES DE TODAS AS CAPACIDADES PAGINADAS (8 por slide) -->
    <?php 
    $capacity_chunks = array_chunk($capacities_processed, 8);
    foreach($capacity_chunks as $chunkIndex => $chunk):
    ?>
    <div class="slide-container">
        <div class="sidebar"><div class="shape-orange"></div><div class="shape-blue"></div></div>
        <div class="logo-container"><img src="logo.png" alt="Logo SENAI" onerror="this.style.display='none'"></div>
        
        <div class="content-area">
            <h1 class="slide-title">Todas as Capacidades Avaliadas</h1>
            <h2 class="slide-subtitle">Desempenho Geral (Parte <?= $chunkIndex + 1 ?>)</h2>
            
            <div class="flex flex-col gap-2 mt-2">
                <?php foreach($chunk as $c): 
                    $cRate = round($c['rate'], 1);
                    $barColor = $cRate >= 70 ? 'bg-green-500' : ($cRate >= 50 ? 'bg-yellow-500' : 'bg-red-500');
                ?>
                <div class="flex items-center gap-3 bg-slate-50 px-4 py-3 rounded-lg border border-slate-100">
                    <div class="w-1/3 font-bold text-slate-700 text-sm truncate" title="<?= htmlspecialchars($c['capacity_desc'] ?: $c['capacity_code']) ?>">
                        <?= htmlspecialchars($c['capacity_code'] . ($c['capacity_desc'] ? ' - ' . substr($c['capacity_desc'], 0, 40) : '')) ?>
                    </div>
                    <div class="flex-1 bg-slate-200 h-4 rounded-full overflow-hidden">
                        <div class="<?= $barColor ?> h-full" style="width: <?= $cRate ?>%"></div>
                    </div>
                    <div class="w-16 text-right font-black text-sm text-slate-800">
                        <?= $cRate ?>%
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="slide-footer">
            <span><?= htmlspecialchars($sprint['name']) ?></span>
            <span><?= sprintf("%02d", $slideNumber++) ?></span>
        </div>
    </div>
    <?php endforeach; ?>


    <!-- SLIDE: IA INSIGHT -->
    <div class="slide-container">
        <div class="sidebar"><div class="shape-orange"></div><div class="shape-blue"></div></div>
        <div class="logo-container"><img src="logo.png" alt="Logo SENAI" onerror="this.style.display='none'"></div>
        
        <div class="content-area">
            <h1 class="slide-title flex items-center gap-3">
                Inteligência Pedagógica <svg class="w-8 h-8 text-[#0038A8]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path></svg>
            </h1>
            <h2 class="slide-subtitle">Parecer Técnico Gerado por IA</h2>
            
            <div class="bg-gradient-to-br from-[#0038A8]/5 to-[#F25922]/5 border border-[#0038A8]/20 p-6 rounded-xl shadow-sm h-full overflow-hidden flex flex-col justify-center relative">
                <div id="ai-content" class="text-base text-slate-700 leading-relaxed space-y-4">
                    <div class="flex items-center text-slate-500"><div class="loader"></div> Analisando o desempenho da turma e gerando parecer estratégico...</div>
                </div>
            </div>
        </div>
        
        <div class="slide-footer">
            <span><?= htmlspecialchars($sprint['name']) ?></span>
            <span><?= sprintf("%02d", $slideNumber++) ?></span>
        </div>
    </div>

    <!-- SLIDES DE TODAS AS QUESTÕES PAGINADAS (3 por slide) -->
    <?php 
    $question_chunks = array_chunk($questions_processed, 3);
    foreach($question_chunks as $chunkIndex => $chunk):
    ?>
    <div class="slide-container">
        <div class="sidebar"><div class="shape-orange"></div><div class="shape-blue"></div></div>
        <div class="logo-container"><img src="logo.png" alt="Logo SENAI" onerror="this.style.display='none'"></div>
        
        <div class="content-area">
            <h1 class="slide-title">Desempenho por Questão</h1>
            <h2 class="slide-subtitle">Resultados Individuais (Parte <?= $chunkIndex + 1 ?>)</h2>
            
            <div class="flex flex-col gap-4 mt-2">
                <?php foreach($chunk as $q): 
                    $qRate = round($q['rate'], 1);
                    $badgeColor = $qRate >= 70 ? 'bg-green-100 text-green-800' : ($qRate >= 50 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800');
                ?>
                <div class="bg-white border border-slate-200 p-4 rounded-xl shadow-sm flex flex-col gap-2">
                    <div class="flex justify-between items-start border-b border-slate-100 pb-2">
                        <div class="flex gap-2 items-center">
                            <span class="text-[9px] font-bold px-2 py-0.5 rounded bg-slate-100 text-slate-600 uppercase">Q<?= $q['id'] ?></span>
                            <span class="text-[9px] font-bold px-2 py-0.5 rounded bg-senai-cyan/10 text-senai-cyan uppercase">CAP: <?= htmlspecialchars($q['capacity']) ?></span>
                            <span class="text-[9px] font-bold text-slate-500 uppercase max-w-[200px] truncate" title="<?= htmlspecialchars($q['module_name']) ?>"><?= htmlspecialchars($q['module_name']) ?></span>
                        </div>
                        <div class="font-black text-sm px-2 py-1 rounded <?= $badgeColor ?>">
                            <?= $qRate ?>% ACERTO
                        </div>
                    </div>
                    <p class="text-xs text-slate-700 font-medium line-clamp-3 leading-snug">
                        <?= htmlspecialchars($q['command']) ?>
                    </p>
                    <div class="text-[10px] text-slate-400 font-bold uppercase mt-1">
                        Respondida por <?= $q['total_answers'] ?> aluno(s)
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="slide-footer">
            <span><?= htmlspecialchars($sprint['name']) ?></span>
            <span><?= sprintf("%02d", $slideNumber++) ?></span>
        </div>
    </div>
    <?php endforeach; ?>

    <script>
        // Dados para enviar à IA
        const aiData = <?= json_encode($ai_data) ?>;
        
        // Chamada à IA
        fetch('sprint_slides_ai_ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(aiData)
        })
        .then(response => response.json())
        .then(data => {
            const container = document.getElementById('ai-content');
            
            if (data.error) {
                container.innerHTML = `<div class="text-red-500 font-bold border-l-4 border-red-500 pl-4">${data.error}</div>`;
            } else {
                const text = data.insight;
                const formatted = text.split('\n').filter(p => p.trim() !== '').map(p => `<p>${p}</p>`).join('');
                container.innerHTML = formatted;
            }
            
            document.getElementById('loading-overlay').style.display = 'none';
            setTimeout(() => {
                window.print();
            }, 500);
        })
        .catch(err => {
            document.getElementById('ai-content').innerHTML = `<div class="text-red-500 font-bold text-sm">Erro ao se comunicar com o servidor. O relatório pode não ter sido processado.</div>`;
            document.getElementById('loading-overlay').style.display = 'none';
        });
    </script>
</body>
</html>
