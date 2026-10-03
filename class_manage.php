<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'teacher'])) {
    header("Location: index.php");
    exit;
}
require_once 'config.php';
$pdo = getPDOConnection(true);

$class_id = $_GET['id'] ?? null;
if (!$class_id) {
    header("Location: classes.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM classes WHERE id = ?");
$stmt->execute([$class_id]);
$class = $stmt->fetch();

if (!$class) {
    die("Turma não encontrada.");
}

$unit_id = $_SESSION['unit_id'] ?? null;
if ($unit_id && $class['unit_id'] !== $unit_id) {
    die("Acesso negado: Esta turma pertence a outra Unidade Escolar.");
}

// ==========================================
// LÓGICA DO DASHBOARD DA TURMA
// ==========================================

// 1. Fetch Sprints with attempts from students in this class
$stmtSprints = $pdo->prepare("
    SELECT DISTINCT s.id, s.name
    FROM sprints s
    JOIN sprint_attempts a ON s.id = a.sprint_id
    JOIN class_students cs ON a.student_id = cs.student_id
    WHERE cs.class_id = ?
    ORDER BY s.id ASC
");
$stmtSprints->execute([$class_id]);
$sprints = $stmtSprints->fetchAll();

$labels = [];
$general_data = [];
$datasets_modules = [];
$datasets_capacities = [];
$has_data = false;
$overall_accuracy = 0;

if (count($sprints) > 0) {
    $has_data = true;
    $sprint_ids = array_column($sprints, 'id');
    $inQuery = implode(',', array_fill(0, count($sprint_ids), '?'));
    
    // Fetch all answers for students currently in this class
    $stmtAnswers = $pdo->prepare("
        SELECT 
            sa.sprint_id,
            m.name as module_name,
            q.capacity as capacity_code,
            sa.is_correct
        FROM student_answers sa
        JOIN questions q ON sa.question_id = q.id
        JOIN modules m ON q.module_id = m.id
        JOIN class_students cs ON sa.student_id = cs.student_id
        WHERE cs.class_id = ? AND sa.sprint_id IN ($inQuery)
    ");
    $params = array_merge([$class_id], $sprint_ids);
    $stmtAnswers->execute($params);
    $answers = $stmtAnswers->fetchAll();

    $module_stats = []; 
    $capacity_stats = []; 
    $total_correct_global = 0;
    $total_answers_global = count($answers);

    foreach ($sprints as $s) {
        $labels[] = $s['name'];
        $sprint_correct = 0;
        $sprint_total = 0;

        foreach ($answers as $a) {
            if ($a['sprint_id'] == $s['id']) {
                $sprint_total++;
                if ($a['is_correct']) {
                    $sprint_correct++;
                    $total_correct_global++;
                }
                
                if (!isset($module_stats[$a['module_name']])) $module_stats[$a['module_name']] = [];
                if (!isset($module_stats[$a['module_name']][$s['id']])) $module_stats[$a['module_name']][$s['id']] = ['c' => 0, 't' => 0];
                $module_stats[$a['module_name']][$s['id']]['t']++;
                if ($a['is_correct']) $module_stats[$a['module_name']][$s['id']]['c']++;

                if (!empty($a['capacity_code'])) {
                    if (!isset($capacity_stats[$a['capacity_code']])) $capacity_stats[$a['capacity_code']] = [];
                    if (!isset($capacity_stats[$a['capacity_code']][$s['id']])) $capacity_stats[$a['capacity_code']][$s['id']] = ['c' => 0, 't' => 0];
                    $capacity_stats[$a['capacity_code']][$s['id']]['t']++;
                    if ($a['is_correct']) $capacity_stats[$a['capacity_code']][$s['id']]['c']++;
                }
            }
        }
        $general_data[] = ($sprint_total > 0) ? round(($sprint_correct / $sprint_total) * 100, 1) : 0;
    }

    if ($total_answers_global > 0) {
        $overall_accuracy = round(($total_correct_global / $total_answers_global) * 100, 1);
    }

    // Prepare chart data for modules
    $colors = ['#F25922', '#0038A8', '#10B981', '#F59E0B', '#8B5CF6', '#EC4899', '#06B6D4'];
    $cIdx = 0;
    foreach ($module_stats as $mName => $mSprints) {
        $data = [];
        foreach ($sprints as $s) {
            if (isset($mSprints[$s['id']]) && $mSprints[$s['id']]['t'] > 0) {
                $data[] = round(($mSprints[$s['id']]['c'] / $mSprints[$s['id']]['t']) * 100, 1);
            } else {
                $data[] = null;
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

    // Prepare chart data for capacities
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
}

// Chart.js dependency in header
$extraScripts = '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';

include 'includes/header.php';
?>

<div id="app-screen" class="absolute inset-0 z-40 flex w-full h-full bg-[#F8FAFC]">
    <?php
    $currentPage = 'classes';
    include 'includes/sidebar.php'; 
    ?>

    <main id="main-content" class="flex-1 flex flex-col relative h-full w-full">
        <header class="h-14 flex items-center justify-between px-4 md:px-6 absolute top-0 w-full z-20">
            <button onclick="toggleSidebar()" class="w-10 h-10 flex items-center justify-center bg-white/50 rounded-xl">
                <i id="sidebar-icon" class="ph-bold ph-list text-xl"></i>
            </button>
            <a href="classes.php" class="text-sm font-bold text-slate-600 hover:text-senai-blue transition-colors flex items-center gap-1">
                <i class="ph-bold ph-arrow-left"></i> Voltar
            </a>
        </header>

        <div class="flex-1 overflow-y-auto w-full flex flex-col pt-24 pb-20 px-4 md:px-8">
            <div class="w-full max-w-7xl mx-auto">
                <h1 class="text-3xl font-bold tracking-tight mb-2 text-slate-800 flex items-center gap-3">
                    Gerenciar Turma
                    <button onclick="openHelpModal('Gestão de Turmas', 'Gerencie as turmas e os alunos vinculados a você.<br><br><b>Funcionalidades:</b><br>- Acompanhe o desempenho global desta turma específica.<br>- Adicione, mova ou remova alunos.<br>- Clique no ícone de Raio-X na tabela de alunos para ver um relatório cirúrgico das deficiências de um aluno específico.')" class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 hover:text-senai-blue hover:bg-blue-50 transition-colors shadow-inner" title="Como Usar">
                        <i class="ph-bold ph-question text-lg"></i>
                    </button>
                </h1>
                <p class="text-slate-500 font-medium mb-8">Turma: <?= htmlspecialchars($class['name']) ?></p>
                
<?php
// Busca Alunos
$stmtStudents = $pdo->prepare("
    SELECT u.id, u.name, u.email
    FROM class_students cs 
    JOIN users u ON cs.student_id = u.id 
    WHERE cs.class_id = ?
    ORDER BY u.name ASC
");
$stmtStudents->execute([$class_id]);
$students = $stmtStudents->fetchAll();

// Remoção de Aluno
if (isset($_GET['remove_student'])) {
    $remove_id = $_GET['remove_student'];
    $stmtDel = $pdo->prepare("DELETE FROM class_students WHERE class_id = ? AND student_id = ?");
    $stmtDel->execute([$class_id, $remove_id]);
    header("Location: class_manage.php?id=" . $class_id);
    exit;
}

// Mover Aluno
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['move_student']) && isset($_POST['new_class'])) {
    $move_student = $_POST['move_student'];
    $new_class = $_POST['new_class'];
    if ($new_class) {
        $pdo->prepare("DELETE FROM class_students WHERE student_id = ?")->execute([$move_student]);
        $pdo->prepare("INSERT INTO class_students (class_id, student_id) VALUES (?, ?)")->execute([$new_class, $move_student]);
    }
    header("Location: class_manage.php?id=" . $class_id);
    exit;
}

$stmtAllClasses = $pdo->prepare("SELECT id, name FROM classes WHERE id != ? ORDER BY name");
$stmtAllClasses->execute([$class_id]);
$allClasses = $stmtAllClasses->fetchAll();

// Configurações de Risco baseadas no desempenho global
$riskColor = 'text-green-500';
$riskBg = 'bg-green-50';
$riskIcon = 'ph-check-circle';
$riskText = 'Alto Desempenho';

if ($overall_accuracy > 0 && $overall_accuracy < 50) {
    $riskColor = 'text-red-500';
    $riskBg = 'bg-red-50';
    $riskIcon = 'ph-warning-octagon';
    $riskText = 'Em Risco';
} elseif ($overall_accuracy >= 50 && $overall_accuracy < 70) {
    $riskColor = 'text-yellow-600';
    $riskBg = 'bg-yellow-50';
    $riskIcon = 'ph-warning';
    $riskText = 'Atenção';
}
?>

                <!-- DASHBOARD ANALÍTICO -->
                <div class="mb-10">
                    <h2 class="text-xl font-bold text-slate-800 mb-4 flex items-center gap-2"><i class="ph-bold ph-chart-line-up text-senai-blue"></i> Desempenho Global da Turma</h2>
                    
                    <?php if (!$has_data): ?>
                        <div class="bg-white/80 backdrop-blur-md rounded-2xl p-8 border border-white text-center shadow-sm">
                            <i class="ph-thin ph-chart-pie-slice text-5xl text-slate-300 mb-3"></i>
                            <p class="text-slate-500 font-medium">Os alunos desta turma ainda não completaram nenhuma Sprint.</p>
                        </div>
                    <?php else: ?>
                        <!-- KPIs -->
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                            <div class="bg-white/80 backdrop-blur-md rounded-2xl p-5 border border-white shadow-sm flex items-center gap-4">
                                <div class="w-12 h-12 rounded-full <?= $riskBg ?> <?= $riskColor ?> flex items-center justify-center text-2xl">
                                    <i class="ph-fill <?= $riskIcon ?>"></i>
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Status Geral</p>
                                    <p class="text-lg font-bold text-slate-700"><?= $riskText ?></p>
                                </div>
                            </div>
                            <div class="bg-white/80 backdrop-blur-md rounded-2xl p-5 border border-white shadow-sm flex items-center gap-4">
                                <div class="w-12 h-12 rounded-full bg-senai-blue/10 text-senai-blue flex items-center justify-center text-2xl">
                                    <i class="ph-fill ph-target"></i>
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Média de Acertos</p>
                                    <p class="text-2xl font-black text-slate-800"><?= $overall_accuracy ?>%</p>
                                </div>
                            </div>
                            <div class="bg-white/80 backdrop-blur-md rounded-2xl p-5 border border-white shadow-sm flex items-center gap-4">
                                <div class="w-12 h-12 rounded-full bg-senai-orange/10 text-senai-orange flex items-center justify-center text-2xl">
                                    <i class="ph-fill ph-flag-checkered"></i>
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Sprints Realizadas</p>
                                    <p class="text-2xl font-black text-slate-800"><?= count($sprints) ?></p>
                                </div>
                            </div>
                            <div class="bg-white/80 backdrop-blur-md rounded-2xl p-5 border border-white shadow-sm flex items-center gap-4">
                                <div class="w-12 h-12 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-2xl">
                                    <i class="ph-fill ph-users-three"></i>
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Alunos Engajados</p>
                                    <p class="text-2xl font-black text-slate-800"><?= count($students) ?></p>
                                </div>
                            </div>
                        </div>

                        <!-- Gráficos -->
                        <?php if (count($sprints) == 1): ?>
                            <div class="bg-indigo-50/70 border border-indigo-100 rounded-3xl p-8 text-center shadow-sm">
                                <i class="ph-fill ph-trend-up text-5xl text-indigo-300 mb-3 animate-float"></i>
                                <h3 class="text-lg font-bold text-indigo-900 mb-1">Evolução em Construção</h3>
                                <p class="text-indigo-700/80 font-medium">Esta turma realizou apenas <strong class="text-indigo-800">uma Sprint</strong> até o momento. MiaS Sprints são necessárias para gerar o histórico de gráficos e evolução.</p>
                            </div>
                        <?php else: ?>
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                                <!-- Gráfico 1: Evolução Geral -->
                                <div class="bg-white/80 backdrop-blur-md rounded-3xl p-6 border border-white shadow-sm">
                                    <h3 class="text-sm font-bold text-slate-700 mb-4 uppercase tracking-wider">Evolução de Acertos (%)</h3>
                                    <div class="relative h-[250px] w-full">
                                        <canvas id="chartGeneral"></canvas>
                                    </div>
                                </div>
                                
                                <!-- Gráfico 2: Desempenho por Unidade Curricular -->
                                <div class="bg-white/80 backdrop-blur-md rounded-3xl p-6 border border-white shadow-sm">
                                    <h3 class="text-sm font-bold text-slate-700 mb-4 uppercase tracking-wider">Desempenho por Unidade Curricular</h3>
                                    <div class="relative h-[250px] w-full">
                                        <canvas id="chartModules"></canvas>
                                    </div>
                                </div>

                                <!-- Gráfico 3: Desempenho por Capacidades (Span full width) -->
                                <div class="bg-white/80 backdrop-blur-md rounded-3xl p-6 border border-white shadow-sm lg:col-span-2">
                                    <h3 class="text-sm font-bold text-slate-700 mb-4 uppercase tracking-wider">Mapeamento de Capacidades (Pontos Fracos e Fortes)</h3>
                                    <div class="relative h-[300px] w-full">
                                        <canvas id="chartCapacities"></canvas>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- LISTA DE ALUNOS -->
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-xl font-bold text-slate-800">Alunos Matriculados (<?= count($students) ?>)</h2>
                </div>

                <div class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden">
                    <?php if (empty($students)): ?>
                        <div class="p-10 text-center">
                            <i class="ph-thin ph-users text-5xl text-slate-400 mb-4 opacity-50"></i>
                            <p class="text-slate-500 font-medium">Nenhum aluno entrou nesta turma ainda.</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="bg-slate-50 border-b border-slate-200 text-xs uppercase tracking-widest text-slate-500">
                                        <th class="px-6 py-4 font-semibold">Aluno</th>
                                        <th class="px-6 py-4 font-semibold">E-mail</th>
                                        <th class="px-6 py-4 font-semibold text-right">Ações</th>
                                    </tr>
                                </thead>
                                <tbody class="text-sm">
                                    <?php foreach($students as $s): ?>
                                    <tr class="border-b border-slate-50 hover:bg-slate-50/80 transition-colors">
                                        <td class="px-6 py-4 font-bold text-slate-700 flex items-center gap-3">
                                            <div class="w-8 h-8 rounded-full bg-senai-blue/10 text-senai-blue flex items-center justify-center font-bold">
                                                <?= substr($s['name'], 0, 1) ?>
                                            </div>
                                            <?= htmlspecialchars($s['name']) ?>
                                        </td>
                                        <td class="px-6 py-4 text-slate-500 font-medium"><?= htmlspecialchars($s['email']) ?></td>
                                        <td class="px-6 py-4 text-right flex items-center justify-end gap-2">
                                            <form action="class_manage.php?id=<?= $class_id ?>" method="POST" class="inline-block m-0">
                                                <input type="hidden" name="move_student" value="<?= $s['id'] ?>">
                                                <select name="new_class" onchange="this.form.submit()" class="bg-slate-50 border border-slate-200 text-xs px-2 py-1.5 rounded-lg text-slate-600 focus:outline-none focus:border-senai-blue font-semibold">
                                                    <option value="">Mover para...</option>
                                                    <?php foreach($allClasses as $c): ?>
                                                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </form>
                                            
                                            <a href="?id=<?= $class_id ?>&remove_student=<?= $s['id'] ?>" onclick="return confirm('Remover este aluno da turma?')" class="text-red-500 hover:text-red-700 hover:bg-red-50 p-2 rounded-lg transition-colors inline-block" title="Remover Aluno">
                                                <i class="ph-bold ph-trash text-lg"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<?php if (count($sprints) > 1): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const labels = <?= json_encode($labels) ?>;
    
    // Configurações comuns
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color = '#64748b';
    
    // 1. Gráfico Geral (Evolução)
    const ctxGeneral = document.getElementById('chartGeneral').getContext('2d');
    new Chart(ctxGeneral, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Média de Acertos (%)',
                data: <?= json_encode($general_data) ?>,
                borderColor: '#1A428A',
                backgroundColor: 'rgba(26, 66, 138, 0.1)',
                borderWidth: 3,
                pointBackgroundColor: '#1A428A',
                pointRadius: 4,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, max: 100 } }
        }
    });

    // 2. Gráfico por Módulos (Unidade Curricular)
    const ctxModules = document.getElementById('chartModules').getContext('2d');
    new Chart(ctxModules, {
        type: 'line',
        data: {
            labels: labels,
            datasets: <?= json_encode($datasets_modules) ?>
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20 } }
            },
            scales: { y: { beginAtZero: true, max: 100 } }
        }
    });

    // 3. Gráfico por Capacidades
    const ctxCapacities = document.getElementById('chartCapacities').getContext('2d');
    new Chart(ctxCapacities, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: <?= json_encode($datasets_capacities) ?>
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20 } }
            },
            scales: { y: { beginAtZero: true, max: 100 } }
        }
    });
});
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
