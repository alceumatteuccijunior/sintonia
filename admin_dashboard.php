<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

require_once 'config.php';
$pdo = getPDOConnection(true);

// -------------------------
// Filtros de Busca Avançados
// -------------------------
$search = $_GET['search'] ?? '';
$teacher_id = $_GET['teacher_id'] ?? '';
$status = $_GET['status'] ?? '';
$filter_regional_id = !empty($_GET['regional_id']) ? $_GET['regional_id'] : null;
$filter_unit_id = !empty($_GET['unit_id']) ? $_GET['unit_id'] : null;
$filter_course_id = !empty($_GET['course_id']) ? $_GET['course_id'] : null;

// Construir os wheres de filtro global
$where_global_unit = "";
$where_sprints_unit = "";
$where_classes = "";
$params_global = [];

// Hierarquia: Unit > Regional
if ($filter_unit_id) {
    $where_global_unit = "WHERE unit_id = ?";
    $where_sprints_unit = "WHERE s.class_id IN (SELECT id FROM classes WHERE unit_id = ?)";
    $where_classes = "WHERE unit_id = ?";
    $params_global[] = $filter_unit_id;
} elseif ($filter_regional_id) {
    $where_global_unit = "WHERE unit_id IN (SELECT id FROM units WHERE regional_id = ?)";
    $where_sprints_unit = "WHERE s.class_id IN (SELECT id FROM classes WHERE unit_id IN (SELECT id FROM units WHERE regional_id = ?))";
    $where_classes = "WHERE unit_id IN (SELECT id FROM units WHERE regional_id = ?)";
    $params_global[] = $filter_regional_id;
}

// Se o Curso for selecionado, afunila Sprints e Classes (mantém param array structure)
$params_classes = $params_global;
if ($filter_course_id) {
    if ($where_classes) {
        $where_classes .= " AND course_id = ?";
    } else {
        $where_classes = "WHERE course_id = ?";
    }
    
    if ($where_sprints_unit) {
        $where_sprints_unit .= " AND s.class_id IN (SELECT id FROM classes WHERE course_id = ?)";
    } else {
        $where_sprints_unit = "WHERE s.class_id IN (SELECT id FROM classes WHERE course_id = ?)";
    }
    
    $params_classes[] = $filter_course_id;
}

// -------------------------
// Global Stats
// -------------------------
$stmtQs = $pdo->query("SELECT count(*) FROM questions");
$total_questions = $stmtQs->fetchColumn();

$stmtClasses = $pdo->prepare("SELECT count(*) FROM classes $where_classes");
$stmtClasses->execute($params_classes);
$total_classes = $stmtClasses->fetchColumn();

$stmtSprints = $pdo->prepare("SELECT count(*) FROM sprints s $where_sprints_unit");
$stmtSprints->execute($params_classes);
$total_sprints = $stmtSprints->fetchColumn();

$stmtUsers = $pdo->prepare("SELECT count(*) FROM users $where_global_unit");
$stmtUsers->execute($params_global);
$total_users = $stmtUsers->fetchColumn();

// -------------------------
// Filtros de Busca
// -------------------------
$search = $_GET['search'] ?? '';
$teacher_id = $_GET['teacher_id'] ?? '';
$status = $_GET['status'] ?? '';

$sql = "
    SELECT s.*, c.name as class_name, u.name as teacher_name,
    (SELECT count(*) FROM sprint_questions WHERE sprint_id = s.id) as total_questions,
    (SELECT count(DISTINCT student_id) FROM sprint_attempts WHERE sprint_id = s.id) as students_started
    FROM sprints s
    JOIN classes c ON s.class_id = c.id
    LEFT JOIN users u ON s.teacher_id = u.id
    WHERE 1=1
";
$params = [];

if ($search) {
    $sql .= " AND (s.name LIKE ? OR c.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($teacher_id) {
    $sql .= " AND s.teacher_id = ?";
    $params[] = $teacher_id;
}
if ($status) {
    $sql .= " AND s.status = ?";
    $params[] = $status;
}

// Filtro Multi-tenant na listagem
if ($filter_unit_id) {
    $sql .= " AND c.unit_id = ?";
    $params[] = $filter_unit_id;
} elseif ($filter_regional_id) {
    $sql .= " AND c.unit_id IN (SELECT id FROM units WHERE regional_id = ?)";
    $params[] = $filter_regional_id;
}

if ($filter_course_id) {
    $sql .= " AND c.course_id = ?";
    $params[] = $filter_course_id;
}

$sql .= " ORDER BY s.created_at DESC";
$stmtAllSprints = $pdo->prepare($sql);
$stmtAllSprints->execute($params);
$sprints = $stmtAllSprints->fetchAll();

// Get teachers for filter dropdown (apenas da unidade filtrada, se houver)
$teacher_sql = "SELECT id, name FROM users WHERE role IN ('teacher', 'admin')";
$teacher_params = [];
if ($filter_unit_id) {
    $teacher_sql .= " AND unit_id = ?";
    $teacher_params[] = $filter_unit_id;
} elseif ($filter_regional_id) {
    $teacher_sql .= " AND unit_id IN (SELECT id FROM units WHERE regional_id = ?)";
    $teacher_params[] = $filter_regional_id;
}
$teacher_sql .= " ORDER BY name";
$stmtTeachers = $pdo->prepare($teacher_sql);
$stmtTeachers->execute($teacher_params);
$teachers = $stmtTeachers->fetchAll();

// Get Regionals and Units for Dropdowns
$stmtRegs = $pdo->query("SELECT id, name FROM regionals ORDER BY name");
$regionals = $stmtRegs->fetchAll();

$units = [];
if ($filter_regional_id) {
    $stmtUnits = $pdo->prepare("SELECT id, name FROM units WHERE regional_id = ? ORDER BY name");
    $stmtUnits->execute([$filter_regional_id]);
    $units = $stmtUnits->fetchAll();
}

// Get Courses
$stmtCourses = $pdo->query("SELECT id, name FROM courses ORDER BY name");
$courses = $stmtCourses->fetchAll();

// =========================================================
// INTELIGÊNCIA GLOBAL (GRÁFICOS AGREGADOS)
// =========================================================
$where_analytics = "WHERE 1=1";
$params_analytics = [];

if ($filter_unit_id) {
    $where_analytics .= " AND c.unit_id = ?";
    $params_analytics[] = $filter_unit_id;
} elseif ($filter_regional_id) {
    $where_analytics .= " AND c.unit_id IN (SELECT id FROM units WHERE regional_id = ?)";
    $params_analytics[] = $filter_regional_id;
}
if ($filter_course_id) {
    $where_analytics .= " AND c.course_id = ?";
    $params_analytics[] = $filter_course_id;
}

// KPI: Desempenho Global (Accuracy %)
$stmtAcc = $pdo->prepare("
    SELECT SUM(CASE WHEN sa.is_correct = 1 THEN 1 ELSE 0 END) as correct_answers, COUNT(sa.id) as total_answers
    FROM student_answers sa
    JOIN sprints s ON sa.sprint_id = s.id
    JOIN classes c ON s.class_id = c.id
    $where_analytics
");
$stmtAcc->execute($params_analytics);
$accData = $stmtAcc->fetch();
$global_accuracy = ($accData && $accData['total_answers'] > 0) ? round(($accData['correct_answers'] / $accData['total_answers']) * 100, 1) : 0;

// Gráfico 1: Evolução por Sprint
$stmtEvol = $pdo->prepare("
    SELECT s.name as sprint_name,
           SUM(CASE WHEN sa.is_correct = 1 THEN 1 ELSE 0 END) as correct_answers,
           COUNT(sa.id) as total_answers
    FROM student_answers sa
    JOIN sprints s ON sa.sprint_id = s.id
    JOIN classes c ON s.class_id = c.id
    $where_analytics
    GROUP BY s.name
    ORDER BY MIN(s.created_at) ASC
");
$stmtEvol->execute($params_analytics);
$evolData = $stmtEvol->fetchAll();

// Gráfico 2: Desempenho por Módulo
$stmtMod = $pdo->prepare("
    SELECT m.name as module_name,
           SUM(CASE WHEN sa.is_correct = 1 THEN 1 ELSE 0 END) as correct_answers,
           COUNT(sa.id) as total_answers
    FROM student_answers sa
    JOIN questions q ON sa.question_id = q.id
    JOIN modules m ON q.module_id = m.id
    JOIN sprints s ON sa.sprint_id = s.id
    JOIN classes c ON s.class_id = c.id
    $where_analytics
    GROUP BY m.name
    ORDER BY (SUM(CASE WHEN sa.is_correct = 1 THEN 1 ELSE 0 END)/COUNT(sa.id)) DESC
");
$stmtMod->execute($params_analytics);
$modData = $stmtMod->fetchAll();

// Gráfico 3: Desempenho por Capacidade
$stmtCap = $pdo->prepare("
    SELECT q.capacity as capacity_code,
           SUM(CASE WHEN sa.is_correct = 1 THEN 1 ELSE 0 END) as correct_answers,
           COUNT(sa.id) as total_answers
    FROM student_answers sa
    JOIN questions q ON sa.question_id = q.id
    JOIN sprints s ON sa.sprint_id = s.id
    JOIN classes c ON s.class_id = c.id
    $where_analytics AND q.capacity IS NOT NULL AND q.capacity != ''
    GROUP BY q.capacity
    ORDER BY (SUM(CASE WHEN sa.is_correct = 1 THEN 1 ELSE 0 END)/COUNT(sa.id)) ASC
    LIMIT 15
");
$stmtCap->execute($params_analytics);
$capData = $stmtCap->fetchAll();

$extraScripts = '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';

$currentPage = 'admin_dashboard';
include 'includes/header.php';
?>

<div id="app-screen" class="absolute inset-0 z-40 flex w-full h-full bg-[#F8FAFC]">
    
    <!-- Fundo Vivo com Parallax -->
    <div class="fixed inset-0 w-full h-full overflow-hidden pointer-events-none z-0">
        <div class="absolute top-[-5%] right-[-5%] w-[500px] h-[500px] bg-senai-cyan/15 rounded-full mix-blend-multiply filter blur-[80px] opacity-70 animate-blob transition-transform duration-1000 ease-out"></div>
        <div class="absolute bottom-[-5%] left-[-5%] w-[600px] h-[600px] bg-senai-orange/10 rounded-full mix-blend-multiply filter blur-[90px] opacity-60 animate-blob transition-transform duration-1000 ease-out" style="animation-delay: -5s;"></div>
    </div>

    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <main id="main-content" class="flex-1 flex flex-col relative h-full w-full">
        
        <header class="h-14 flex items-center justify-between px-4 md:px-6 absolute top-0 w-full z-20 pointer-events-none">
            <button onclick="toggleSidebar()" class="pointer-events-auto w-10 h-10 flex items-center justify-center bg-white/50 hover:bg-white rounded-xl backdrop-blur-md shadow-sm border border-white/60 transition-all active:scale-95 text-senai-dark">
                <i id="sidebar-icon" class="ph-bold ph-list text-xl transition-transform duration-300"></i>
            </button>
            <div class="flex-1"></div>
            
            <div class="pointer-events-auto text-[11px] font-bold text-slate-600 flex items-center gap-1.5 bg-white/70 backdrop-blur-xl border border-white/80 shadow-sm px-4 py-2 rounded-full mr-2">
                <i class="ph-fill ph-shield-star text-senai-orange"></i> Painel de Controle
            </div>
        </header>

        <!-- Area de Scroll Dinâmica -->
        <div id="content-scroll-area" class="flex-1 overflow-y-auto no-scrollbar w-full flex flex-col items-center pt-24 pb-20 relative z-10 px-4 md:px-8">
            
            <div class="w-full max-w-6xl">
                <div class="mb-10 animate-slide-up text-center md:text-left">
                    <h1 class="text-3xl md:text-4xl font-bold tracking-tight mb-2 drop-shadow-sm text-slate-800 flex items-center gap-3">
                        Visão Global
                        <button onclick="openHelpModal('Visão Global (Admin)', 'Esta tela oferece uma visão panorâmica e estratégica de toda a instituição.<br><br><b>O que você pode fazer aqui:</b><br>- Acompanhar a taxa de acertos (Accuracy) global<br>- Visualizar gráficos de evolução das avaliações ao longo do tempo<br>- Analisar o desempenho de forma granular usando os filtros por Unidades, Regionais e Cursos.')" class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 hover:text-senai-blue hover:bg-blue-50 transition-colors shadow-inner" title="Como Usar">
                            <i class="ph-bold ph-question text-lg"></i>
                        </button>
                    </h1>
                    <p class="text-base text-slate-500 font-medium opacity-0 animate-fade-in delay-200">
                        Monitoramento pedagógico de todas as turmas, provas e professores.
                    </p>
                </div>

                <!-- Painel de KPIs Globais -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 w-full mb-10">
                    <div class="bg-white/60 backdrop-blur-md border border-white/80 p-5 rounded-2xl shadow-sm hover:shadow-md transition-all group">
                        <div class="w-10 h-10 rounded-full bg-indigo-500/10 flex items-center justify-center text-indigo-500 mb-3 group-hover:scale-110 transition-transform">
                            <i class="ph-fill ph-users text-xl"></i>
                        </div>
                        <h3 class="text-3xl font-bold text-slate-800 mb-1"><?= $total_users ?></h3>
                        <p class="text-[11px] text-slate-500 font-bold uppercase tracking-wider">Usuários no Sistema</p>
                    </div>

                    <div class="bg-white/60 backdrop-blur-md border border-white/80 p-5 rounded-2xl shadow-sm hover:shadow-md transition-all group">
                        <div class="w-10 h-10 rounded-full bg-senai-orange/10 flex items-center justify-center text-senai-orange mb-3 group-hover:scale-110 transition-transform">
                            <i class="ph-fill ph-graduation-cap text-xl"></i>
                        </div>
                        <h3 class="text-3xl font-bold text-slate-800 mb-1"><?= $total_classes ?></h3>
                        <p class="text-[11px] text-slate-500 font-bold uppercase tracking-wider">Turmas Ativas</p>
                    </div>

                    <div class="bg-white/60 backdrop-blur-md border border-white/80 p-5 rounded-2xl shadow-sm hover:shadow-md transition-all group">
                        <div class="w-10 h-10 rounded-full bg-senai-blue/10 flex items-center justify-center text-senai-blue mb-3 group-hover:scale-110 transition-transform">
                            <i class="ph-fill ph-books text-xl"></i>
                        </div>
                        <h3 class="text-3xl font-bold text-slate-800 mb-1"><?= $total_questions ?></h3>
                        <p class="text-[11px] text-slate-500 font-bold uppercase tracking-wider">Questões no Banco</p>
                    </div>

                    <div class="bg-white/60 backdrop-blur-md border border-white/80 p-5 rounded-2xl shadow-sm hover:shadow-md transition-all group">
                        <div class="w-10 h-10 rounded-full bg-senai-cyan/10 flex items-center justify-center text-senai-cyan mb-3 group-hover:scale-110 transition-transform">
                            <i class="ph-fill ph-target text-xl"></i>
                        </div>
                        <h3 class="text-3xl font-bold text-slate-800 mb-1"><?= $total_sprints ?></h3>
                        <p class="text-[11px] text-slate-500 font-bold uppercase tracking-wider">Provas Geradas</p>
                    </div>
                </div>

                <!-- Filtros para o Banco de Sprints -->
                <div class="glass-panel p-6 mb-8 flex flex-col gap-4 animate-slide-up delay-200">
                    <form method="GET" id="adminFiltersForm" class="flex flex-col gap-4">
                        
                        <!-- Top Row: Geographic Multi-tenant Filters -->
                        <div class="flex flex-wrap md:flex-nowrap gap-3 bg-slate-50/50 p-4 rounded-2xl border border-slate-100">
                            <div class="flex-1 min-w-[200px]">
                                <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Regional (DR)</label>
                                <select name="regional_id" onchange="document.getElementById('adminFiltersForm').submit();" class="w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-senai-blue transition-all font-medium text-slate-700 shadow-sm">
                                    <option value="">Todas as Regionais (Visão Nacional)</option>
                                    <?php foreach($regionals as $r): ?>
                                        <option value="<?= $r['id'] ?>" <?= $filter_regional_id == $r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="flex-1 min-w-[200px]">
                                <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Unidade Escolar</label>
                                <select name="unit_id" onchange="document.getElementById('adminFiltersForm').submit();" <?= empty($units) ? 'disabled' : '' ?> class="w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-senai-blue transition-all font-medium text-slate-700 shadow-sm disabled:opacity-50 disabled:bg-slate-50">
                                    <option value="">Todas as Unidades</option>
                                    <?php foreach($units as $u): ?>
                                        <option value="<?= $u['id'] ?>" <?= $filter_unit_id == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="flex-1 min-w-[200px]">
                                <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Curso</label>
                                <select name="course_id" onchange="document.getElementById('adminFiltersForm').submit();" class="search-select w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-senai-blue transition-all font-medium text-slate-700 shadow-sm">
                                    <option value="">Todos os Cursos</option>
                                    <?php foreach($courses as $c): ?>
                                        <option value="<?= $c['id'] ?>" <?= $filter_course_id == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Bottom Row: Specific Search -->
                        <div class="flex flex-wrap md:flex-nowrap gap-3">
                            <div class="relative flex-1 min-w-[200px]">
                                <i class="ph ph-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Buscar por Prova ou Turma..." class="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-senai-blue focus:ring-1 focus:ring-senai-blue transition-all">
                            </div>
                            
                            <select name="teacher_id" class="px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-senai-blue transition-all min-w-[150px]">
                                <option value="">Todos os Professores</option>
                                <?php foreach($teachers as $t): ?>
                                    <option value="<?= $t['id'] ?>" <?= $teacher_id == $t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                                <?php endforeach; ?>
                            </select>

                            <select name="status" class="px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-senai-blue transition-all">
                                <option value="">Qualquer Status</option>
                                <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Rascunho</option>
                                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Ativa</option>
                                <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Finalizada</option>
                            </select>

                            <button type="submit" class="px-6 py-2.5 bg-slate-800 text-white rounded-xl font-bold text-sm hover:bg-slate-700 transition-colors">
                                Buscar
                            </button>
                            <?php if($search || $teacher_id || $status || $filter_regional_id || $filter_unit_id): ?>
                                <a href="admin_dashboard.php" class="px-4 py-2.5 bg-slate-100 text-slate-500 rounded-xl font-bold text-sm hover:bg-slate-200 transition-colors flex items-center">
                                    Limpar
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- GRÁFICOS AGREGADOS (INTELIGÊNCIA GLOBAL) -->
                <?php if ($global_accuracy > 0): ?>
                    <div class="mb-10 animate-slide-up delay-300">
                        <div class="flex items-center gap-2 mb-4">
                            <i class="ph-bold ph-chart-polar text-senai-blue text-2xl"></i>
                            <h2 class="text-2xl font-bold text-slate-800">Performance Analítica</h2>
                        </div>
                        
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <!-- Gráfico 1: Evolução Geral -->
                            <div class="bg-white/80 backdrop-blur-md rounded-3xl p-6 border border-white shadow-sm">
                                <h3 class="text-sm font-bold text-slate-700 mb-4 uppercase tracking-wider">Evolução Histórica (%)</h3>
                                <?php if(count($evolData) > 1): ?>
                                <div class="relative h-[250px] w-full">
                                    <canvas id="chartEvol"></canvas>
                                </div>
                                <?php else: ?>
                                <div class="bg-indigo-50/70 border border-indigo-100 rounded-3xl p-8 text-center h-[250px] flex flex-col justify-center items-center">
                                    <i class="ph-fill ph-trend-up text-4xl text-indigo-300 mb-2 animate-float"></i>
                                    <p class="text-indigo-700/80 font-medium text-sm">Dados insuficientes para histórico.<br>São necessárias mais Sprints concluídas.</p>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Gráfico 2: Desempenho por Unidade Curricular -->
                            <div class="bg-white/80 backdrop-blur-md rounded-3xl p-6 border border-white shadow-sm">
                                <h3 class="text-sm font-bold text-slate-700 mb-4 uppercase tracking-wider">Desempenho por Unidade Curricular</h3>
                                <div class="relative h-[250px] w-full">
                                    <canvas id="chartMod"></canvas>
                                </div>
                            </div>

                            <!-- Gráfico 3: Desempenho por Capacidades (Span full width) -->
                            <div class="bg-white/80 backdrop-blur-md rounded-3xl p-6 border border-white shadow-sm lg:col-span-2">
                                <h3 class="text-sm font-bold text-slate-700 mb-4 uppercase tracking-wider">Mapeamento de Habilidades e Capacidades</h3>
                                <div class="relative h-[350px] w-full">
                                    <canvas id="chartCap"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Banco Global de Sprints -->
                <div class="animate-slide-up delay-200">
                    <div class="bg-white/70 backdrop-blur-md border border-white/80 rounded-3xl p-6 shadow-sm overflow-hidden">
                        
                        <h2 class="text-xl font-bold text-slate-800 mb-6 flex items-center gap-2">
                            <i class="ph-fill ph-list-dashes text-senai-blue"></i> Todas as Sprints do Sistema
                        </h2>

                        <?php if(empty($sprints)): ?>
                            <div class="text-center py-10 text-slate-500">
                                <i class="ph-thin ph-file-dashed text-5xl mb-3 opacity-50"></i>
                                <p class="text-lg">Nenhuma avaliação encontrada com estes filtros.</p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left border-collapse">
                                    <thead>
                                        <tr class="border-b border-slate-100 text-xs uppercase tracking-widest text-slate-400">
                                            <th class="pb-3 font-semibold">Avaliação</th>
                                            <th class="pb-3 font-semibold">Turma</th>
                                            <th class="pb-3 font-semibold">Responsável</th>
                                            <th class="pb-3 font-semibold text-center">Status</th>
                                            <th class="pb-3 font-semibold text-center">Adesão</th>
                                            <th class="pb-3 font-semibold text-right">Ação</th>
                                        </tr>
                                    </thead>
                                    <tbody class="text-sm">
                                        <?php foreach($sprints as $s): ?>
                                        <tr class="border-b border-slate-50 hover:bg-slate-50/50 transition-colors group">
                                            <td class="py-4">
                                                <div class="font-bold text-slate-700"><?= htmlspecialchars($s['name']) ?></div>
                                                <div class="text-xs text-slate-400 font-medium">Criado em: <?= date('d/m/Y', strtotime($s['created_at'])) ?></div>
                                            </td>
                                            <td class="py-4 font-bold text-slate-500"><?= htmlspecialchars($s['class_name']) ?></td>
                                            <td class="py-4">
                                                <div class="flex items-center gap-2">
                                                    <div class="w-6 h-6 rounded-full bg-slate-200 flex items-center justify-center text-[10px] font-bold text-slate-600">
                                                        <?= substr($s['teacher_name'] ?? 'U', 0, 1) ?>
                                                    </div>
                                                    <span class="font-medium text-slate-600"><?= htmlspecialchars($s['teacher_name'] ?? 'Usuário Deletado') ?></span>
                                                </div>
                                            </td>
                                            <td class="py-4 text-center">
                                                <?php if($s['status'] === 'active'): ?>
                                                    <span class="bg-green-100 text-green-700 px-2 py-1.5 rounded-lg text-[10px] font-bold uppercase shadow-sm">Ativa</span>
                                                <?php elseif($s['status'] === 'completed'): ?>
                                                    <span class="bg-indigo-100 text-indigo-700 px-2 py-1.5 rounded-lg text-[10px] font-bold uppercase shadow-sm">Finalizada</span>
                                                <?php else: ?>
                                                    <span class="bg-slate-100 text-slate-600 px-2 py-1.5 rounded-lg text-[10px] font-bold uppercase shadow-sm">Rascunho</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-4 text-center font-bold text-senai-blue">
                                                <?= $s['students_started'] ?> <span class="text-xs text-slate-400 font-medium ml-1">alunos</span>
                                            </td>
                                            <td class="py-4 text-right">
                                                <?php if ($s['status'] !== 'draft'): ?>
                                                <a href="sprint_report.php?id=<?= $s['id'] ?>" class="inline-flex items-center gap-1.5 bg-slate-100 hover:bg-senai-blue text-slate-600 hover:text-white px-3 py-1.5 rounded-lg font-bold transition-all text-xs" title="Ver Relatório">
                                                    <i class="ph-bold ph-chart-bar"></i> Relatório
                                                </a>
                                                <?php else: ?>
                                                    <span class="text-xs text-slate-400 italic">Aguardando Início</span>
                                                <?php endif; ?>
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
        </div>
    </main>
</div>

<?php if ($global_accuracy > 0): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color = '#64748b';
    
    // Preparar Dados (Evolução)
    <?php if(count($evolData) > 1): ?>
    const evolLabels = <?= json_encode(array_column($evolData, 'sprint_name')) ?>;
    const evolData = <?= json_encode(array_map(function($d) { return round(($d['correct_answers'] / $d['total_answers']) * 100, 1); }, $evolData)) ?>;
    
    new Chart(document.getElementById('chartEvol').getContext('2d'), {
        type: 'line',
        data: {
            labels: evolLabels,
            datasets: [{
                label: 'Média Global (%)',
                data: evolData,
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
    <?php endif; ?>

    // Preparar Dados (Módulos)
    const modLabels = <?= json_encode(array_column($modData, 'module_name')) ?>;
    const modData = <?= json_encode(array_map(function($d) { return round(($d['correct_answers'] / $d['total_answers']) * 100, 1); }, $modData)) ?>;
    
    new Chart(document.getElementById('chartMod').getContext('2d'), {
        type: 'bar',
        data: {
            labels: modLabels,
            datasets: [{
                label: 'Média de Acertos (%)',
                data: modData,
                backgroundColor: '#F25922',
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, max: 100 } }
        }
    });

    // Preparar Dados (Capacidades)
    const capLabels = <?= json_encode(array_column($capData, 'capacity_code')) ?>;
    const capData = <?= json_encode(array_map(function($d) { return round(($d['correct_answers'] / $d['total_answers']) * 100, 1); }, $capData)) ?>;
    
    new Chart(document.getElementById('chartCap').getContext('2d'), {
        type: 'bar', // Pode ser radar, mas bar é mais legível para muitas opções
        data: {
            labels: capLabels,
            datasets: [{
                label: 'Média de Acertos (%)',
                data: capData,
                backgroundColor: '#06B6D4',
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, max: 100 } }
        }
    });
});
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
