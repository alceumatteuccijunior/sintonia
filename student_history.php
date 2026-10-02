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

// Fetch all classes for the dropdown
$stmtClasses = $pdo->query("SELECT id, name FROM classes ORDER BY name ASC");
$classes = $stmtClasses->fetchAll();

$students = [];
if ($class_id) {
    // Fetch students in the selected class
    $stmtStudents = $pdo->prepare("
        SELECT u.id, u.name 
        FROM users u 
        JOIN class_students cs ON u.id = cs.student_id 
        WHERE cs.class_id = ? AND u.role = 'student'
        ORDER BY u.name ASC
    ");
    $stmtStudents->execute([$class_id]);
    $students = $stmtStudents->fetchAll();
}

$history_data = null;
if ($student_id) {
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

    if (count($sprints) > 0) {
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
        $labels = []; // Sprint names
        $general_data = []; // Overall accuracy per sprint
        
        $module_stats = []; // module_name => [sprint_id => [correct, total]]
        $capacity_stats = []; // capacity_code => [sprint_id => [correct, total]]

        foreach ($sprints as $s) {
            $labels[] = $s['name'] . " (" . date('d/m/Y', strtotime($s['completed_at'])) . ")";
            
            // Initialize totals for this sprint
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
            
            $general_data[] = ($sprint_total > 0) ? round(($sprint_correct / $sprint_total) * 100, 1) : 0;
        }

        // Format multi-line data arrays
        $datasets_modules = [];
        $colors = ['#F25922', '#0038A8', '#10B981', '#F59E0B', '#8B5CF6', '#EC4899', '#06B6D4'];
        $cIdx = 0;
        foreach ($module_stats as $mName => $mSprints) {
            $data = [];
            foreach ($sprints as $s) {
                if (isset($mSprints[$s['id']]) && $mSprints[$s['id']]['t'] > 0) {
                    $data[] = round(($mSprints[$s['id']]['c'] / $mSprints[$s['id']]['t']) * 100, 1);
                } else {
                    $data[] = null; // No data for this module in this sprint
                }
            }
            $datasets_modules[] = [
                'label' => $mName,
                'data' => $data,
                'borderColor' => $colors[$cIdx % count($colors)],
                'backgroundColor' => $colors[$cIdx % count($colors)],
                'tension' => 0.3,
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
                    $data[] = round(($cSprints[$s['id']]['c'] / $cSprints[$s['id']]['t']) * 100, 1);
                } else {
                    $data[] = null;
                }
            }
            $datasets_capacities[] = [
                'label' => $cName,
                'data' => $data,
                'borderColor' => $colors[$cIdx % count($colors)],
                'backgroundColor' => $colors[$cIdx % count($colors)],
                'tension' => 0.3,
                'spanGaps' => true
            ];
            $cIdx++;
        }

        $history_data = [
            'labels' => $labels,
            'general' => $general_data,
            'modules' => $datasets_modules,
            'capacities' => $datasets_capacities
        ];
    } else {
        $history_data = 'empty';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Histórico do Aluno - Sintonia</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .glass-panel { background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.5); box-shadow: 0 4px 30px rgba(0, 0, 0, 0.05); }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 h-screen overflow-hidden flex">

    <!-- Efeitos de Fundo -->
    <div class="fixed inset-0 w-full h-full overflow-hidden pointer-events-none z-0">
        <div class="absolute top-[-10%] right-[-5%] w-[500px] h-[500px] bg-senai-cyan/20 rounded-full mix-blend-multiply filter blur-[80px] opacity-70 animate-blob"></div>
        <div class="absolute bottom-[-10%] left-[-10%] w-[600px] h-[600px] bg-senai-blue/10 rounded-full mix-blend-multiply filter blur-[80px] opacity-50 animate-blob animation-delay-2000"></div>
    </div>

    <?php 
    $currentPage = 'student_history';
    include 'includes/sidebar.php'; 
    ?>

    <main class="flex-1 flex flex-col relative h-full w-full z-10">
        <!-- Header -->
        <header class="h-20 glass-panel border-b border-white/60 flex items-center justify-between px-8 z-20">
            <div class="flex items-center gap-4">
                <button onclick="toggleSidebar()" class="md:hidden w-10 h-10 flex items-center justify-center bg-white/50 hover:bg-white rounded-xl shadow-sm text-slate-600 transition-all">
                    <i id="sidebar-icon" class="ph-bold ph-list text-xl"></i>
                </button>
                <div>
                    <h1 class="text-2xl font-bold text-slate-800 tracking-tight">Histórico de Evolução</h1>
                    <p class="text-sm text-slate-500 font-medium">Acompanhe o desempenho do aluno ao longo das Sprints</p>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto p-8">
            <div class="max-w-7xl mx-auto space-y-6">

                <!-- Filtros -->
                <div class="glass-panel p-6 rounded-2xl flex flex-col md:flex-row gap-4 items-end">
                    <div class="flex-1 w-full">
                        <label class="block text-sm font-bold text-slate-700 mb-2">Selecione a Turma</label>
                        <select onchange="window.location.href='?class_id='+this.value" class="w-full bg-white/50 border border-slate-200 rounded-xl px-4 py-3 text-slate-700 outline-none focus:ring-2 focus:ring-senai-blue/50 transition-all">
                            <option value="">-- Escolha uma Turma --</option>
                            <?php foreach($classes as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= $class_id == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($class_id): ?>
                    <div class="flex-1 w-full animate-fade-in">
                        <label class="block text-sm font-bold text-slate-700 mb-2">Selecione o Aluno</label>
                        <select onchange="if(this.value) window.location.href='?class_id=<?= $class_id ?>&student_id='+this.value" class="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 text-slate-700 outline-none focus:ring-2 focus:ring-senai-blue/50 transition-all font-semibold shadow-sm">
                            <option value="">-- Escolha um Aluno --</option>
                            <?php foreach($students as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= $student_id == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($history_data === 'empty'): ?>
                    <div class="glass-panel p-12 rounded-2xl text-center flex flex-col items-center gap-4">
                        <div class="w-20 h-20 bg-slate-100 rounded-full flex items-center justify-center text-slate-400">
                            <i class="ph-fill ph-ghost text-4xl"></i>
                        </div>
                        <h2 class="text-xl font-bold text-slate-700">Nenhum dado encontrado</h2>
                        <p class="text-slate-500">Este aluno ainda não concluiu nenhuma Sprint.</p>
                    </div>
                <?php elseif (is_array($history_data)): ?>
                    
                    <!-- Evolução Geral -->
                    <div class="glass-panel p-6 rounded-2xl">
                        <div class="flex items-center justify-between mb-6">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-senai-blue/10 flex items-center justify-center text-senai-blue">
                                    <i class="ph-bold ph-trend-up text-xl"></i>
                                </div>
                                <div>
                                    <h3 class="font-bold text-lg text-slate-800">Evolução Geral</h3>
                                    <p class="text-sm text-slate-500">Taxa de acerto global por Sprint</p>
                                </div>
                            </div>
                            <a href="student_history_slides.php?class_id=<?= $class_id ?>&student_id=<?= $student_id ?>" target="_blank" class="bg-senai-cyan text-white px-4 py-2.5 rounded-xl font-bold shadow-sm hover:shadow-md transition-all text-sm flex items-center gap-2">
                                <i class="ph-bold ph-presentation-chart"></i> Exportar Histórico (Slides)
                            </a>
                        </div>
                        <div class="h-[300px] w-full">
                            <canvas id="chartGeneral"></canvas>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                        <!-- Evolução por Módulo -->
                        <div class="glass-panel p-6 rounded-2xl">
                            <div class="flex items-center gap-3 mb-6">
                                <div class="w-10 h-10 rounded-xl bg-senai-orange/10 flex items-center justify-center text-senai-orange">
                                    <i class="ph-bold ph-books text-xl"></i>
                                </div>
                                <div>
                                    <h3 class="font-bold text-lg text-slate-800">Por Unidade Curricular</h3>
                                    <p class="text-sm text-slate-500">Progresso histórico em cada módulo</p>
                                </div>
                            </div>
                            <div class="h-[350px] w-full">
                                <canvas id="chartModules"></canvas>
                            </div>
                        </div>

                        <!-- Evolução por Capacidade -->
                        <div class="glass-panel p-6 rounded-2xl">
                            <div class="flex items-center gap-3 mb-6">
                                <div class="w-10 h-10 rounded-xl bg-green-500/10 flex items-center justify-center text-green-600">
                                    <i class="ph-bold ph-target text-xl"></i>
                                </div>
                                <div>
                                    <h3 class="font-bold text-lg text-slate-800">Por Capacidade Técnica</h3>
                                    <p class="text-sm text-slate-500">Progresso histórico nos fundamentos (Capacidades)</p>
                                </div>
                            </div>
                            <div class="h-[350px] w-full">
                                <canvas id="chartCapacities"></canvas>
                            </div>
                        </div>
                    </div>

                    <script>
                        const historyData = <?= json_encode($history_data) ?>;
                        
                        Chart.defaults.font.family = "'Inter', sans-serif";
                        Chart.defaults.color = '#64748b';

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
                                    borderWidth: 3,
                                    pointBackgroundColor: '#F25922',
                                    pointBorderColor: '#fff',
                                    pointBorderWidth: 2,
                                    pointRadius: 5,
                                    pointHoverRadius: 7,
                                    fill: true,
                                    tension: 0.3
                                }]
                            },
                            options: {
                                responsive: true, maintainAspectRatio: false,
                                plugins: { legend: { display: false } },
                                scales: {
                                    y: { min: 0, max: 100, ticks: { callback: v => v + '%' } },
                                    x: { grid: { display: false } }
                                }
                            }
                        });

                        // Gráfico 2: Módulos
                        new Chart(document.getElementById('chartModules'), {
                            type: 'line',
                            data: {
                                labels: historyData.labels,
                                datasets: historyData.modules
                            },
                            options: {
                                responsive: true, maintainAspectRatio: false,
                                plugins: {
                                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } }
                                },
                                scales: {
                                    y: { min: 0, max: 100, ticks: { callback: v => v + '%' } },
                                    x: { grid: { display: false } }
                                }
                            }
                        });

                        // Gráfico 3: Capacidades
                        new Chart(document.getElementById('chartCapacities'), {
                            type: 'line',
                            data: {
                                labels: historyData.labels,
                                datasets: historyData.capacities
                            },
                            options: {
                                responsive: true, maintainAspectRatio: false,
                                plugins: {
                                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } }
                                },
                                scales: {
                                    y: { min: 0, max: 100, ticks: { callback: v => v + '%' } },
                                    x: { grid: { display: false } }
                                }
                            }
                        });
                    </script>

                <?php endif; ?>

            </div>
        </div>
    </main>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'senai-blue': '#0038A8',
                        'senai-orange': '#F25922',
                        'senai-cyan': '#00C2CB',
                        'senai-dark': '#1e293b'
                    },
                    animation: {
                        'blob': 'blob 7s infinite',
                        'fade-in': 'fadeIn 0.5s ease-out'
                    },
                    keyframes: {
                        blob: {
                            '0%': { transform: 'translate(0px, 0px) scale(1)' },
                            '33%': { transform: 'translate(30px, -50px) scale(1.1)' },
                            '66%': { transform: 'translate(-20px, 20px) scale(0.9)' },
                            '100%': { transform: 'translate(0px, 0px) scale(1)' }
                        },
                        fadeIn: {
                            '0%': { opacity: '0', transform: 'translateY(10px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' }
                        }
                    }
                }
            }
        }
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const icon = document.getElementById('sidebar-icon');
            sidebar.classList.toggle('collapsed');
            
            if (sidebar.classList.contains('collapsed')) {
                icon.classList.remove('ph-x');
                icon.classList.add('ph-list');
            } else {
                icon.classList.remove('ph-list');
                icon.classList.add('ph-x');
            }
        }
    </script>
</body>
</html>
