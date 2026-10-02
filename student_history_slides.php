<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'teacher'])) {
    header("Location: index.php");
    exit;
}

require_once 'config.php';
$pdo = getPDOConnection();

$class_id = $_GET['class_id'] ?? '';
$student_id = $_GET['student_id'] ?? '';

if (!$class_id || !$student_id) {
    die("Turma ou Aluno não informados.");
}

// Fetch class info
$stmtC = $pdo->prepare("SELECT name FROM classes WHERE id = ?");
$stmtC->execute([$class_id]);
$class_name = $stmtC->fetchColumn();

// Fetch student info
$stmtU = $pdo->prepare("SELECT name FROM users WHERE id = ?");
$stmtU->execute([$student_id]);
$student_name = $stmtU->fetchColumn();

// Fetch Sprints the student participated in
$stmtSprints = $pdo->prepare("
    SELECT s.id, s.name, a.completed_at
    FROM sprint_attempts a
    JOIN sprints s ON a.sprint_id = s.id
    WHERE a.student_id = ?
    ORDER BY a.completed_at ASC
");
$stmtSprints->execute([$student_id]);
$sprints = $stmtSprints->fetchAll();

if (count($sprints) == 0) {
    die("Nenhum dado encontrado para exportação.");
}

$sprint_ids = array_column($sprints, 'id');
$inQuery = implode(',', array_fill(0, count($sprint_ids), '?'));

// Fetch all answers for this student in these sprints
$stmtAnswers = $pdo->prepare("
    SELECT 
        sa.sprint_id,
        m.name as module_name,
        q.capacity as capacity_code,
        sa.is_correct
    FROM student_answers sa
    JOIN questions q ON sa.question_id = q.id
    JOIN modules m ON q.module_id = m.id
    WHERE sa.student_id = ? AND sa.sprint_id IN ($inQuery)
");
$params = array_merge([$student_id], $sprint_ids);
$stmtAnswers->execute($params);
$answers = $stmtAnswers->fetchAll();

// Data structures for JS
$labels = [];
$general_data = [];
$sprints_summary = [];

$module_stats = []; 
$capacity_stats = []; 

// For AI processing (get stats of the last sprint)
$last_sprint_id = end($sprints)['id'];
$last_mod_perf = [];
$last_cap_perf = [];

foreach ($sprints as $s) {
    $date_str = date('d/m/Y', strtotime($s['completed_at']));
    $labels[] = $s['name'] . "\n(" . $date_str . ")";
    
    $sprint_correct = 0;
    $sprint_total = 0;

    foreach ($answers as $a) {
        if ($a['sprint_id'] == $s['id']) {
            $sprint_total++;
            if ($a['is_correct']) $sprint_correct++;
            
            // Module tracking
            if (!isset($module_stats[$a['module_name']])) $module_stats[$a['module_name']] = [];
            if (!isset($module_stats[$a['module_name']][$s['id']])) $module_stats[$a['module_name']][$s['id']] = ['c' => 0, 't' => 0];
            $module_stats[$a['module_name']][$s['id']]['t']++;
            if ($a['is_correct']) $module_stats[$a['module_name']][$s['id']]['c']++;

            // Capacity tracking
            if (!isset($capacity_stats[$a['capacity_code']])) $capacity_stats[$a['capacity_code']] = [];
            if (!isset($capacity_stats[$a['capacity_code']][$s['id']])) $capacity_stats[$a['capacity_code']][$s['id']] = ['c' => 0, 't' => 0];
            $capacity_stats[$a['capacity_code']][$s['id']]['t']++;
            if ($a['is_correct']) $capacity_stats[$a['capacity_code']][$s['id']]['c']++;
        }
    }
    
    $rate = ($sprint_total > 0) ? round(($sprint_correct / $sprint_total) * 100, 1) : 0;
    $general_data[] = $rate;

    $sprints_summary[] = [
        'name' => $s['name'],
        'date' => $date_str,
        'rate' => $rate,
        'correct' => $sprint_correct,
        'total' => $sprint_total
    ];
}

// Format multi-line data arrays
$datasets_modules = [];
$colors = ['#F25922', '#0038A8', '#10B981', '#F59E0B', '#8B5CF6', '#EC4899', '#06B6D4'];
$cIdx = 0;
foreach ($module_stats as $mName => $mSprints) {
    $data = [];
    foreach ($sprints as $s) {
        if (isset($mSprints[$s['id']]) && $mSprints[$s['id']]['t'] > 0) {
            $r = round(($mSprints[$s['id']]['c'] / $mSprints[$s['id']]['t']) * 100, 1);
            $data[] = $r;
            if ($s['id'] == $last_sprint_id) $last_mod_perf[$mName] = $r;
        } else {
            $data[] = null;
        }
    }
    $datasets_modules[] = [
        'label' => $mName,
        'data' => $data,
        'borderColor' => $colors[$cIdx % count($colors)],
        'backgroundColor' => $colors[$cIdx % count($colors)],
        'borderWidth' => 4,
        'pointRadius' => 6,
        'tension' => 0.2,
        'spanGaps' => true
    ];
    $cIdx++;
}

$datasets_capacities = [];
$cIdx = 0;
foreach ($capacity_stats as $cName => $cSprints) {
    $data = [];
    foreach ($sprints as $s) {
        if (isset($cSprints[$s['id']]) && $cSprints[$s['id']]['t'] > 0) {
            $r = round(($cSprints[$s['id']]['c'] / $cSprints[$s['id']]['t']) * 100, 1);
            $data[] = $r;
            if ($s['id'] == $last_sprint_id) $last_cap_perf[$cName] = $r;
        } else {
            $data[] = null;
        }
    }
    $datasets_capacities[] = [
        'label' => $cName,
        'data' => $data,
        'borderColor' => $colors[$cIdx % count($colors)],
        'backgroundColor' => $colors[$cIdx % count($colors)],
        'borderWidth' => 3,
        'pointRadius' => 5,
        'tension' => 0.2,
        'spanGaps' => true
    ];
    $cIdx++;
}

// Find best/worst for AI Prompt
asort($last_mod_perf);
$worst_module = !empty($last_mod_perf) ? key($last_mod_perf) . ' (' . current($last_mod_perf) . '%)' : 'N/A';
arsort($last_mod_perf);
$best_module = !empty($last_mod_perf) ? key($last_mod_perf) . ' (' . current($last_mod_perf) . '%)' : 'N/A';

asort($last_cap_perf);
$worst_capacity = !empty($last_cap_perf) ? key($last_cap_perf) . ' (' . current($last_cap_perf) . '%)' : 'N/A';
arsort($last_cap_perf);
$best_capacity = !empty($last_cap_perf) ? key($last_cap_perf) . ' (' . current($last_cap_perf) . '%)' : 'N/A';

$ai_data = [
    'student_name' => $student_name,
    'class_name' => $class_name,
    'sprints' => $sprints,
    'general' => $general_data,
    'best_module' => $best_module,
    'worst_module' => $worst_module,
    'best_capacity' => $best_capacity,
    'worst_capacity' => $worst_capacity
];

$history_data = [
    'labels' => $labels,
    'general' => $general_data,
    'modules' => $datasets_modules,
    'capacities' => $datasets_capacities
];

$slideNumber = 1;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Histórico de Evolução - <?= htmlspecialchars($student_name) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        .content-area { position: absolute; top: 130px; left: 120px; right: 120px; bottom: 80px; display: flex; flex-direction: column; z-index: 5; }
        
        .slide-title { color: #0038A8; font-size: 42px; font-weight: 800; margin-bottom: 8px; line-height: 1.1; letter-spacing: -1px; }
        .slide-subtitle { color: #F25922; font-size: 24px; font-weight: 600; margin-bottom: 24px; text-transform: uppercase; letter-spacing: 1px; }
        
        .slide-footer { position: absolute; bottom: 25px; left: 120px; right: 60px; display: flex; justify-content: space-between; color: #9CA3AF; font-size: 13px; font-weight: 600; z-index: 5; }

        .loader { border: 4px solid #f3f3f3; border-top: 4px solid #F25922; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite; display: inline-block; margin-right: 10px; vertical-align: middle; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        .dense-table th { padding: 12px 16px; font-size: 14px; }
        .dense-table td { padding: 12px 16px; font-size: 16px; border-bottom: 1px solid #f1f5f9; }
        .dense-table tr:hover { background-color: #f8fafc; }
    </style>
</head>
<body>

    <div id="loading-overlay" class="fixed inset-0 bg-slate-900/90 z-50 flex flex-col items-center justify-center text-white font-bold text-xl backdrop-blur-sm">
        <div class="loader mb-4" style="width: 60px; height: 60px; border-width: 6px;"></div>
        <p>A Inteligência Artificial está analisando o histórico do aluno...</p>
        <p class="text-slate-400 text-sm mt-2 font-normal">A tela de impressão abrirá automaticamente assim que a IA terminar.</p>
    </div>

    <!-- SLIDE 1: CAPA -->
    <div class="slide-container">
        <div class="sidebar"><div class="shape-orange"></div><div class="shape-blue"></div></div>
        <div class="logo-container"><img src="logo.png" alt="Logo SENAI" onerror="this.style.display='none'"></div>
        
        <div class="content-area justify-center items-center text-center">
            <h2 class="text-xl font-bold text-slate-400 mb-2 tracking-widest uppercase">Histórico do Aluno</h2>
            <h1 class="slide-title" style="font-size: 64px; margin-bottom: 15px;"><?= htmlspecialchars($student_name) ?></h1>
            <h2 class="slide-subtitle" style="font-size: 32px;">Turma: <?= htmlspecialchars($class_name) ?></h2>
            <div class="w-24 h-2 bg-[#F25922] mt-4 mb-6 mx-auto"></div>
            <p class="text-lg text-slate-500 font-medium">Análise de Evolução Pedagógica e Curva de Aprendizado</p>
        </div>
        
        <div class="slide-footer">
            <span>DEPARTAMENTO REGIONAL - EDUCAÇÃO PROFISSIONAL</span>
            <span><?= sprintf("%02d", $slideNumber++) ?></span>
        </div>
    </div>

    <!-- SLIDE 2: EVOLUÇÃO GERAL -->
    <div class="slide-container">
        <div class="sidebar"><div class="shape-orange"></div><div class="shape-blue"></div></div>
        <div class="logo-container"><img src="logo.png" alt="Logo SENAI" onerror="this.style.display='none'"></div>
        
        <div class="content-area">
            <h1 class="slide-title">Evolução Geral</h1>
            <h2 class="slide-subtitle">Desempenho Global (Taxa de Acerto) ao Longo das Avaliações</h2>
            
            <div class="flex-1 mt-4 relative bg-white border border-slate-100 p-4 rounded-xl shadow-sm">
                <canvas id="chartGeneral"></canvas>
            </div>
        </div>
        
        <div class="slide-footer">
            <span><?= htmlspecialchars($student_name) ?></span>
            <span><?= sprintf("%02d", $slideNumber++) ?></span>
        </div>
    </div>

    <!-- SLIDE 3: EVOLUÇÃO POR MÓDULO -->
    <div class="slide-container">
        <div class="sidebar"><div class="shape-orange"></div><div class="shape-blue"></div></div>
        <div class="logo-container"><img src="logo.png" alt="Logo SENAI" onerror="this.style.display='none'"></div>
        
        <div class="content-area">
            <h1 class="slide-title">Evolução por Unidade Curricular</h1>
            <h2 class="slide-subtitle">Progresso em cada módulo da disciplina</h2>
            
            <div class="flex-1 mt-4 relative bg-white border border-slate-100 p-4 rounded-xl shadow-sm">
                <canvas id="chartModules"></canvas>
            </div>
        </div>
        
        <div class="slide-footer">
            <span><?= htmlspecialchars($student_name) ?></span>
            <span><?= sprintf("%02d", $slideNumber++) ?></span>
        </div>
    </div>

    <!-- SLIDE 4: EVOLUÇÃO POR CAPACIDADE -->
    <div class="slide-container">
        <div class="sidebar"><div class="shape-orange"></div><div class="shape-blue"></div></div>
        <div class="logo-container"><img src="logo.png" alt="Logo SENAI" onerror="this.style.display='none'"></div>
        
        <div class="content-area">
            <h1 class="slide-title">Evolução por Capacidade Técnica</h1>
            <h2 class="slide-subtitle">Rastreamento cirúrgico dos fundamentos técnicos cobrados</h2>
            
            <div class="flex-1 mt-4 relative bg-white border border-slate-100 p-4 rounded-xl shadow-sm">
                <canvas id="chartCapacities"></canvas>
            </div>
        </div>
        
        <div class="slide-footer">
            <span><?= htmlspecialchars($student_name) ?></span>
            <span><?= sprintf("%02d", $slideNumber++) ?></span>
        </div>
    </div>

    <!-- SLIDE 5: IA INSIGHT -->
    <div class="slide-container">
        <div class="sidebar"><div class="shape-orange"></div><div class="shape-blue"></div></div>
        <div class="logo-container"><img src="logo.png" alt="Logo SENAI" onerror="this.style.display='none'"></div>
        
        <div class="content-area">
            <h1 class="slide-title flex items-center gap-3">
                Inteligência Pedagógica <svg class="w-10 h-10 text-[#0038A8]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path></svg>
            </h1>
            <h2 class="slide-subtitle">Parecer de Evolução Gerado por IA</h2>
            
            <div class="bg-gradient-to-br from-[#0038A8]/5 to-[#F25922]/5 border border-[#0038A8]/20 p-8 rounded-2xl shadow-sm h-full overflow-hidden flex flex-col justify-center relative">
                <div id="ai-content" class="text-xl text-slate-700 leading-relaxed space-y-6">
                    <div class="flex items-center text-slate-500"><div class="loader"></div> Analisando a curva de aprendizagem e gerando parecer estratégico...</div>
                </div>
            </div>
        </div>
        
        <div class="slide-footer">
            <span><?= htmlspecialchars($student_name) ?></span>
            <span><?= sprintf("%02d", $slideNumber++) ?></span>
        </div>
    </div>

    <!-- SLIDE 6: RESUMO DAS SPRINTS -->
    <div class="slide-container">
        <div class="sidebar"><div class="shape-orange"></div><div class="shape-blue"></div></div>
        <div class="logo-container"><img src="logo.png" alt="Logo SENAI" onerror="this.style.display='none'"></div>
        
        <div class="content-area">
            <h1 class="slide-title">Tabela Resumo</h1>
            <h2 class="slide-subtitle">Participações Registradas</h2>
            
            <div class="mt-4 overflow-hidden rounded-2xl border border-slate-200 shadow-sm bg-white">
                <table class="w-full text-left dense-table">
                    <thead class="bg-slate-50 text-slate-600 font-bold uppercase tracking-wider text-sm">
                        <tr>
                            <th>Data</th>
                            <th>Sprint</th>
                            <th class="text-center">Questões</th>
                            <th class="text-right">Acerto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($sprints_summary as $sum): 
                            $badgeColor = $sum['rate'] >= 70 ? 'text-green-600' : ($sum['rate'] >= 50 ? 'text-yellow-600' : 'text-red-600');
                        ?>
                        <tr>
                            <td class="font-bold text-slate-600"><?= $sum['date'] ?></td>
                            <td class="font-bold text-slate-800"><?= htmlspecialchars($sum['name']) ?></td>
                            <td class="text-center font-semibold text-[#0038A8]"><?= $sum['correct'] ?> / <?= $sum['total'] ?></td>
                            <td class="text-right font-black <?= $badgeColor ?>"><?= $sum['rate'] ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="slide-footer">
            <span><?= htmlspecialchars($student_name) ?></span>
            <span><?= sprintf("%02d", $slideNumber++) ?></span>
        </div>
    </div>

    <script>
        const historyData = <?= json_encode($history_data) ?>;
        const aiData = <?= json_encode($ai_data) ?>;
        
        // Configuração Global Chart.js (Sem Animação para PDF)
        Chart.defaults.font.family = "'Inter', sans-serif";
        Chart.defaults.color = '#64748b';
        Chart.defaults.animation = false; // <-- CRUCIAL PARA IMPRESSÃO PDF IMEDIATA
        
        const chartOptions = {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { min: 0, max: 100, ticks: { callback: v => v + '%', font: {size: 14} } },
                x: { grid: { display: false }, ticks: { font: {size: 14, weight: 'bold'} } }
            },
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 10, font: {size: 14, weight: 'bold'} } }
            }
        };

        // Gráfico 1: Geral
        new Chart(document.getElementById('chartGeneral'), {
            type: 'line',
            data: {
                labels: historyData.labels,
                datasets: [{
                    label: 'Taxa de Acerto (%)',
                    data: historyData.general,
                    borderColor: '#0038A8',
                    backgroundColor: 'rgba(0, 56, 168, 0.1)',
                    borderWidth: 5,
                    pointBackgroundColor: '#F25922',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 3,
                    pointRadius: 8,
                    fill: true,
                    tension: 0.3
                }]
            },
            options: { ...chartOptions, plugins: { legend: { display: false } } }
        });

        // Gráfico 2: Módulos
        new Chart(document.getElementById('chartModules'), {
            type: 'line',
            data: {
                labels: historyData.labels,
                datasets: historyData.modules
            },
            options: chartOptions
        });

        // Gráfico 3: Capacidades
        new Chart(document.getElementById('chartCapacities'), {
            type: 'line',
            data: {
                labels: historyData.labels,
                datasets: historyData.capacities
            },
            options: chartOptions
        });

        // Chamada à IA
        fetch('student_history_ai_ajax.php', {
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
                const formatted = data.insight.split('\n').filter(p => p.trim() !== '').map(p => `<p>${p}</p>`).join('');
                container.innerHTML = formatted;
            }
            
            // Finalizar loading e abrir impressão
            document.getElementById('loading-overlay').style.display = 'none';
            setTimeout(() => window.print(), 500); // pequeno timeout para renderização final de fontes
        })
        .catch(err => {
            document.getElementById('ai-content').innerHTML = `<div class="text-red-500 font-bold">Erro ao analisar evolução via IA.</div>`;
            document.getElementById('loading-overlay').style.display = 'none';
            setTimeout(() => window.print(), 500);
        });
    </script>
</body>
</html>
