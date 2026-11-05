<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'teacher'])) {
    header("Location: index.php");
    exit;
}

require_once 'config.php';
$pdo = getPDOConnection(true);

$user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['user_role'] === 'admin');

// -------------------------------------------------------------
// 1. Navegação de Mês / Ano (Google Agenda Style)
// -------------------------------------------------------------
$currentYear = (int)date('Y');
$currentMonth = (int)date('n');

$year = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear;
$month = isset($_GET['month']) ? (int)$_GET['month'] : $currentMonth;

if ($month < 1) {
    $month = 12;
    $year--;
} elseif ($month > 12) {
    $month = 1;
    $year++;
}

// Mês Anterior e Próximo para os botões de navegação
$prevMonth = $month - 1;
$prevYear = $year;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}

$nextMonth = $month + 1;
$nextYear = $year;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}

$mesesNomes = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];

$viewMode = $_GET['view'] ?? 'month'; // 'month' ou 'list'

// -------------------------------------------------------------
// 2. Filtros Avançados
// -------------------------------------------------------------
$filter_regional_id = !empty($_GET['regional_id']) ? (int)$_GET['regional_id'] : null;
$filter_unit_id = !empty($_GET['unit_id']) ? (int)$_GET['unit_id'] : null;
$filter_course_id = !empty($_GET['course_id']) ? (int)$_GET['course_id'] : null;
$filter_class_id = !empty($_GET['class_id']) ? (int)$_GET['class_id'] : null;
$filter_module_id = !empty($_GET['module_id']) ? (int)$_GET['module_id'] : null;
$filter_teacher_id = !empty($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : null;
$filter_status = $_GET['status_filter'] ?? 'all';

// Dados para preencher os selects de filtros
$regionals = [];
$units = [];
$courses = [];
$teachers = [];
$modules = [];
$classes = [];

if ($is_admin) {
    $regionals = $pdo->query("SELECT id, name FROM regionals ORDER BY name ASC")->fetchAll();
    $units = $pdo->query("SELECT id, regional_id, name FROM units ORDER BY name ASC")->fetchAll();
    $teachers = $pdo->query("SELECT id, name FROM users WHERE role IN ('teacher', 'admin') ORDER BY name ASC")->fetchAll();
    $courses = $pdo->query("SELECT id, name FROM courses ORDER BY name ASC")->fetchAll();
    $modules = $pdo->query("SELECT id, course_id, name FROM modules ORDER BY name ASC")->fetchAll();
    $classes = $pdo->query("SELECT id, unit_id, course_id, name, year, semester FROM classes ORDER BY name ASC")->fetchAll();
} else {
    // Professor vê apenas o que se aplica a ele
    $stmtC = $pdo->prepare("
        SELECT DISTINCT c.id, c.name, c.year, c.semester, c.course_id 
        FROM classes c 
        JOIN sprints s ON s.class_id = c.id 
        WHERE s.teacher_id = ?
        ORDER BY c.name ASC
    ");
    $stmtC->execute([$user_id]);
    $classes = $stmtC->fetchAll();

    $stmtCo = $pdo->prepare("
        SELECT DISTINCT co.id, co.name 
        FROM courses co 
        JOIN classes c ON c.course_id = co.id 
        JOIN sprints s ON s.class_id = c.id 
        WHERE s.teacher_id = ?
        ORDER BY co.name ASC
    ");
    $stmtCo->execute([$user_id]);
    $courses = $stmtCo->fetchAll();
}

// -------------------------------------------------------------
// 3. Consulta de Sprints no Banco
// -------------------------------------------------------------
$where = ["1=1"];
$params = [];

// Restrição de Professor
if (!$is_admin) {
    $where[] = "s.teacher_id = ?";
    $params[] = $user_id;
} else {
    if ($filter_teacher_id) {
        $where[] = "s.teacher_id = ?";
        $params[] = $filter_teacher_id;
    }
    if ($filter_regional_id) {
        $where[] = "u.regional_id = ?";
        $params[] = $filter_regional_id;
    }
    if ($filter_unit_id) {
        $where[] = "c.unit_id = ?";
        $params[] = $filter_unit_id;
    }
}

if ($filter_course_id) {
    $where[] = "c.course_id = ?";
    $params[] = $filter_course_id;
}

if ($filter_class_id) {
    $where[] = "s.class_id = ?";
    $params[] = $filter_class_id;
}

if ($filter_module_id) {
    $where[] = "s.id IN (SELECT sq.sprint_id FROM sprint_questions sq JOIN questions q ON sq.question_id = q.id WHERE q.module_id = ?)";
    $params[] = $filter_module_id;
}

if ($filter_status === 'scheduled') {
    $where[] = "(s.status = 'scheduled' OR (s.start_time IS NOT NULL AND s.start_time > NOW()))";
} elseif ($filter_status === 'active') {
    $where[] = "s.status = 'active' AND (s.start_time IS NULL OR s.start_time <= NOW())";
} elseif ($filter_status === 'completed') {
    $where[] = "s.status = 'completed'";
}

$whereSql = implode(" AND ", $where);

$sqlSprints = "
    SELECT s.*, 
           c.name as class_name, c.id as class_id,
           co.name as course_name, co.id as course_id,
           u.name as unit_name, u.id as unit_id,
           r.name as regional_name, r.id as regional_id,
           prof.name as teacher_name,
           (SELECT COUNT(*) FROM sprint_questions sq WHERE sq.sprint_id = s.id) as total_questions,
           (SELECT COUNT(DISTINCT student_id) FROM sprint_attempts sa WHERE sa.sprint_id = s.id) as students_started,
           (SELECT COUNT(DISTINCT student_id) FROM class_students cs WHERE cs.class_id = s.class_id) as total_students
    FROM sprints s
    JOIN classes c ON s.class_id = c.id
    JOIN courses co ON c.course_id = co.id
    LEFT JOIN units u ON c.unit_id = u.id
    LEFT JOIN regionals r ON u.regional_id = r.id
    LEFT JOIN users prof ON s.teacher_id = prof.id
    WHERE {$whereSql}
    ORDER BY COALESCE(s.start_time, s.created_at) ASC
";

$stmtSprints = $pdo->prepare($sqlSprints);
$stmtSprints->execute($params);
$allSprints = $stmtSprints->fetchAll();

// Agrupa sprints por data (YYYY-MM-DD)
$sprintsByDate = [];
$sprintsJson = [];

foreach ($allSprints as $sp) {
    // Data de referência: start_time ou created_at
    $refDate = !empty($sp['start_time']) ? date('Y-m-d', strtotime($sp['start_time'])) : date('Y-m-d', strtotime($sp['created_at']));
    
    // Calcula estado real
    $isScheduled = (!empty($sp['start_time']) && strtotime($sp['start_time']) > time()) || $sp['status'] === 'scheduled';
    $isCompleted = ($sp['status'] === 'completed');
    
    $sp['is_scheduled_future'] = $isScheduled;
    $sp['display_status'] = $isCompleted ? 'Concluída' : ($isScheduled ? 'Agendada' : 'Ativa/Liberada');
    
    $sprintsByDate[$refDate][] = $sp;
    $sprintsJson[$sp['id']] = $sp;
}

// -------------------------------------------------------------
// 4. Estrutura do Calendário Mensal
// -------------------------------------------------------------
$firstDayOfMonth = new DateTime(sprintf('%04d-%02d-01', $year, $month));
$daysInCurrentMonth = (int)$firstDayOfMonth->format('t');
$startDayOfWeek = (int)$firstDayOfMonth->format('w'); // 0 (Domingo) a 6 (Sábado)

include 'includes/header.php';
?>

<div id="app-screen" class="absolute inset-0 z-40 flex w-full h-full">
    <!-- Fundo Vivo -->
    <div class="fixed inset-0 w-full h-full overflow-hidden pointer-events-none z-0 bg-[#F8FAFC]">
        <div class="absolute top-[-5%] right-[-5%] w-[500px] h-[500px] bg-senai-cyan/15 rounded-full mix-blend-multiply filter blur-[80px] opacity-70 animate-blob"></div>
        <div class="absolute bottom-[-10%] left-[-5%] w-[600px] h-[600px] bg-senai-blue/10 rounded-full mix-blend-multiply filter blur-[90px] opacity-60 animate-blob" style="animation-delay: -5s;"></div>
    </div>

    <?php 
    $currentPage = 'agenda';
    include 'includes/sidebar.php'; 
    ?>

    <main id="main-content" class="flex-1 flex flex-col relative h-full w-full">
        <!-- Header Flutuante Padrão do Sistema -->
        <header class="h-14 flex items-center justify-between px-4 md:px-6 absolute top-0 w-full z-20 pointer-events-none">
            <button onclick="toggleSidebar()" class="pointer-events-auto w-10 h-10 flex items-center justify-center bg-white/50 hover:bg-white rounded-xl backdrop-blur-md shadow-sm border border-white/60 transition-all active:scale-95 group text-senai-dark hover:text-senai-cyan" title="Alternar Menu">
                <i id="sidebar-icon" class="ph-bold ph-list text-xl transition-transform duration-300"></i>
            </button>
            <div class="flex-1"></div>
            
            <div class="pointer-events-auto text-[11px] font-bold text-slate-600 flex items-center gap-1.5 bg-white/70 backdrop-blur-xl border border-white/80 shadow-sm px-4 py-2 rounded-full mr-2">
                <i class="ph-fill ph-calendar text-indigo-600"></i> Agenda & Cronograma
            </div>
        </header>

        <!-- Área Rolável de Conteúdo -->
        <div id="content-scroll-area" class="flex-1 overflow-y-auto no-scrollbar w-full flex flex-col items-center pt-20 pb-20 relative z-10 px-4 md:px-8">
            <div class="w-full max-w-7xl space-y-6">

                <!-- Título da Página e Ações Rápidas -->
                <div class="flex flex-col md:flex-row md:items-center justify-between animate-slide-up gap-4">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center shadow-inner shrink-0">
                            <i class="ph-fill ph-calendar-blank text-2xl"></i>
                        </div>
                        <div>
                            <h1 class="font-bold text-slate-800 text-2xl md:text-3xl tracking-tight flex items-center gap-2">
                                Agenda de Sprints
                                <span class="text-xs font-semibold px-2.5 py-0.5 rounded-full bg-indigo-100 text-indigo-700">Cronograma</span>
                            </h1>
                            <p class="text-xs md:text-sm text-slate-500 font-medium">Planejamento, acompanhamento e governança de provas diagnósticas</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <button onclick="toggleHelpCards()" class="flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-slate-200 bg-white/80 hover:bg-white text-slate-600 text-xs font-bold shadow-sm transition-all">
                            <i class="ph-bold ph-question text-senai-blue"></i> Como Usar
                        </button>

                        <a href="sprint_create.php" class="bg-senai-blue text-white px-5 py-2.5 rounded-xl text-xs font-bold shadow-[0_4px_12px_-2px_rgba(26,66,138,0.4)] hover:shadow-lg hover:-translate-y-0.5 transition-all flex items-center gap-2">
                            <i class="ph-bold ph-plus"></i> Nova Sprint
                        </a>
                    </div>
                </div>

                <!-- Cards de Apoio / Onboarding (Expansíveis/Retráteis) -->
                <div id="help-cards-container" class="grid grid-cols-1 md:grid-cols-3 gap-4 animate-slide-up">
                    <div class="bg-gradient-to-br from-indigo-50 to-white p-5 rounded-2xl border border-indigo-100/80 shadow-sm relative overflow-hidden">
                        <div class="w-10 h-10 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center text-xl mb-3">
                            <i class="ph-fill ph-calendar-check"></i>
                        </div>
                        <h3 class="font-bold text-slate-800 text-sm mb-1">1. Agendamento Blindado</h3>
                        <p class="text-xs text-slate-500 leading-relaxed">
                            Defina uma data/hora futura ao criar a Sprint. O sistema protege as questões e impede que qualquer estudante acerte ou realize a prova antes do momento programado.
                        </p>
                    </div>

                    <div class="bg-gradient-to-br from-blue-50 to-white p-5 rounded-2xl border border-blue-100/80 shadow-sm relative overflow-hidden">
                        <div class="w-10 h-10 rounded-xl bg-blue-100 text-senai-blue flex items-center justify-center text-xl mb-3">
                            <i class="ph-fill ph-sliders-horizontal"></i>
                        </div>
                        <h3 class="font-bold text-slate-800 text-sm mb-1">2. Governança & Filtros</h3>
                        <p class="text-xs text-slate-500 leading-relaxed">
                            <?php if ($is_admin): ?>
                                Gestores podem cruzar filtros por <strong>Regional, Unidade Escolar, Curso, Turma e Docente</strong> para visualizar o mapa completo de aplicações do estado.
                            <?php else: ?>
                                Professores contam com uma visão personalizada das suas próprias turmas, podendo reagendar datas rapidamente com 1 clique no calendário.
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="bg-gradient-to-br from-slate-50 to-white p-5 rounded-2xl border border-slate-200/80 shadow-sm relative overflow-hidden">
                        <div class="w-10 h-10 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center text-xl mb-3">
                            <i class="ph-fill ph-tag"></i>
                        </div>
                        <h3 class="font-bold text-slate-800 text-sm mb-1">3. Legenda de Status</h3>
                        <div class="space-y-1.5 mt-2 text-xs">
                            <div class="flex items-center gap-2 text-indigo-700 font-semibold">
                                <span class="w-2.5 h-2.5 rounded-full bg-indigo-500"></span> Prova Agendada (Futura)
                            </div>
                            <div class="flex items-center gap-2 text-senai-blue font-semibold">
                                <span class="w-2.5 h-2.5 rounded-full bg-senai-blue"></span> Em Andamento / Ativa Hoje
                            </div>
                            <div class="flex items-center gap-2 text-green-700 font-semibold">
                                <span class="w-2.5 h-2.5 rounded-full bg-green-500"></span> Concluída / Histórico
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Barra Superior de Controle e Filtros -->
                <div class="bg-white/80 backdrop-blur-md rounded-2xl p-4 border border-white/80 shadow-sm space-y-4">
                    
                    <!-- Linha 1: Navegação Temporal e Alternância de Visualização -->
                    <div class="flex flex-col md:flex-row items-center justify-between gap-4">
                        <!-- Navegação de Mês/Ano -->
                        <div class="flex items-center gap-3">
                            <div class="flex items-center bg-slate-100 rounded-xl p-1 shadow-inner">
                                <a href="?year=<?= $prevYear ?>&month=<?= $prevMonth ?>&view=<?= $viewMode ?>&<?= http_build_query(array_filter(['regional_id'=>$filter_regional_id,'unit_id'=>$filter_unit_id,'course_id'=>$filter_course_id,'class_id'=>$filter_class_id,'module_id'=>$filter_module_id,'teacher_id'=>$filter_teacher_id,'status_filter'=>$filter_status])) ?>" 
                                   class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-600 hover:bg-white hover:text-senai-blue hover:shadow-sm transition-all" title="Mês Anterior">
                                    <i class="ph-bold ph-caret-left"></i>
                                </a>
                                <a href="?year=<?= $currentYear ?>&month=<?= $currentMonth ?>&view=<?= $viewMode ?>&<?= http_build_query(array_filter(['regional_id'=>$filter_regional_id,'unit_id'=>$filter_unit_id,'course_id'=>$filter_course_id,'class_id'=>$filter_class_id,'module_id'=>$filter_module_id,'teacher_id'=>$filter_teacher_id,'status_filter'=>$filter_status])) ?>" 
                                   class="px-3 py-1 rounded-lg text-xs font-bold text-slate-700 hover:bg-white hover:text-senai-blue transition-all">
                                    Hoje
                                </a>
                                <a href="?year=<?= $nextYear ?>&month=<?= $nextMonth ?>&view=<?= $viewMode ?>&<?= http_build_query(array_filter(['regional_id'=>$filter_regional_id,'unit_id'=>$filter_unit_id,'course_id'=>$filter_course_id,'class_id'=>$filter_class_id,'module_id'=>$filter_module_id,'teacher_id'=>$filter_teacher_id,'status_filter'=>$filter_status])) ?>" 
                                   class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-600 hover:bg-white hover:text-senai-blue hover:shadow-sm transition-all" title="Próximo Mês">
                                    <i class="ph-bold ph-caret-right"></i>
                                </a>
                            </div>

                            <h2 class="text-xl md:text-2xl font-black text-slate-800 tracking-tight flex items-center gap-2">
                                <?= $mesesNomes[$month] ?> <span class="text-slate-400 font-medium"><?= $year ?></span>
                            </h2>
                        </div>

                        <!-- Alternador de Visão (Mês x Lista) -->
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-semibold text-slate-400 mr-1 hidden sm:inline">Exibição:</span>
                            <div class="bg-slate-100 p-1 rounded-xl flex items-center shadow-inner">
                                <a href="?year=<?= $year ?>&month=<?= $month ?>&view=month&<?= http_build_query(array_filter(['regional_id'=>$filter_regional_id,'unit_id'=>$filter_unit_id,'course_id'=>$filter_course_id,'class_id'=>$filter_class_id,'module_id'=>$filter_module_id,'teacher_id'=>$filter_teacher_id,'status_filter'=>$filter_status])) ?>" 
                                   class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 <?= $viewMode === 'month' ? 'bg-white text-senai-blue shadow-sm' : 'text-slate-500 hover:text-slate-800' ?>">
                                    <i class="ph-bold ph-calendar"></i> Mês
                                </a>
                                <a href="?year=<?= $year ?>&month=<?= $month ?>&view=list&<?= http_build_query(array_filter(['regional_id'=>$filter_regional_id,'unit_id'=>$filter_unit_id,'course_id'=>$filter_course_id,'class_id'=>$filter_class_id,'module_id'=>$filter_module_id,'teacher_id'=>$filter_teacher_id,'status_filter'=>$filter_status])) ?>" 
                                   class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 <?= $viewMode === 'list' ? 'bg-white text-senai-blue shadow-sm' : 'text-slate-500 hover:text-slate-800' ?>">
                                    <i class="ph-bold ph-list-dashes"></i> Lista
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Linha 2: Filtros Detalhados -->
                    <form method="GET" action="agenda.php" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3 pt-3 border-t border-slate-100">
                        <input type="hidden" name="year" value="<?= $year ?>">
                        <input type="hidden" name="month" value="<?= $month ?>">
                        <input type="hidden" name="view" value="<?= $viewMode ?>">

                        <?php if ($is_admin): ?>
                        <!-- Filtro Regional -->
                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Regional (DR)</label>
                            <select name="regional_id" onchange="this.form.submit()" class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-xs rounded-xl px-2.5 py-2 focus:ring-1 focus:ring-senai-blue outline-none transition-all">
                                <option value="">Todas Regionais</option>
                                <?php foreach ($regionals as $r): ?>
                                    <option value="<?= $r['id'] ?>" <?= $filter_regional_id == $r['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($r['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Filtro Unidade / Escola -->
                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Unidade/Escola</label>
                            <select name="unit_id" onchange="this.form.submit()" class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-xs rounded-xl px-2.5 py-2 focus:ring-1 focus:ring-senai-blue outline-none transition-all">
                                <option value="">Todas Unidades</option>
                                <?php foreach ($units as $u): ?>
                                    <option value="<?= $u['id'] ?>" <?= $filter_unit_id == $u['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($u['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <!-- Filtro Curso -->
                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Curso</label>
                            <select name="course_id" onchange="this.form.submit()" class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-xs rounded-xl px-2.5 py-2 focus:ring-1 focus:ring-senai-blue outline-none transition-all">
                                <option value="">Todos Cursos</option>
                                <?php foreach ($courses as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= $filter_course_id == $c['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Filtro Turma -->
                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Turma</label>
                            <select name="class_id" onchange="this.form.submit()" class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-xs rounded-xl px-2.5 py-2 focus:ring-1 focus:ring-senai-blue outline-none transition-all">
                                <option value="">Todas Turmas</option>
                                <?php foreach ($classes as $cl): ?>
                                    <option value="<?= $cl['id'] ?>" <?= $filter_class_id == $cl['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cl['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ($is_admin): ?>
                        <!-- Filtro Professor -->
                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Professor</label>
                            <select name="teacher_id" onchange="this.form.submit()" class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-xs rounded-xl px-2.5 py-2 focus:ring-1 focus:ring-senai-blue outline-none transition-all">
                                <option value="">Todos Professores</option>
                                <?php foreach ($teachers as $t): ?>
                                    <option value="<?= $t['id'] ?>" <?= $filter_teacher_id == $t['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($t['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Filtro Módulo -->
                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Módulo/Unidade</label>
                            <select name="module_id" onchange="this.form.submit()" class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-xs rounded-xl px-2.5 py-2 focus:ring-1 focus:ring-senai-blue outline-none transition-all">
                                <option value="">Todos Módulos</option>
                                <?php foreach ($modules as $m): ?>
                                    <option value="<?= $m['id'] ?>" <?= $filter_module_id == $m['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($m['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <!-- Filtro Status -->
                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Status</label>
                            <select name="status_filter" onchange="this.form.submit()" class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-xs rounded-xl px-2.5 py-2 focus:ring-1 focus:ring-senai-blue outline-none transition-all">
                                <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>Todos Status</option>
                                <option value="scheduled" <?= $filter_status === 'scheduled' ? 'selected' : '' ?>>Apenas Agendadas</option>
                                <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Apenas Ativas/Hoje</option>
                                <option value="completed" <?= $filter_status === 'completed' ? 'selected' : '' ?>>Apenas Concluídas</option>
                            </select>
                        </div>
                    </form>
                </div>

                <!-- ========================================================= -->
                <!-- VISÃO 1: GRADE DO GOOGLE AGENDA (MÊS)                      -->
                <!-- ========================================================= -->
                <?php if ($viewMode === 'month'): ?>
                    <div class="bg-white rounded-3xl border border-slate-200/80 shadow-md overflow-hidden">
                        <!-- Cabeçalho dos Dias da Semana -->
                        <div class="grid grid-cols-7 border-b border-slate-200 bg-slate-50/80 text-center py-3 text-xs font-bold text-slate-500 uppercase tracking-wider">
                            <span class="text-red-500">Dom</span>
                            <span>Seg</span>
                            <span>Ter</span>
                            <span>Qua</span>
                            <span>Qui</span>
                            <span>Sex</span>
                            <span class="text-blue-500">Sáb</span>
                        </div>

                        <!-- Células dos Dias -->
                        <div class="grid grid-cols-7 auto-rows-fr divide-x divide-y divide-slate-100 bg-white">
                            <?php
                            // Dias do mês anterior para preencher a primeira semana
                            $prevMonthDays = (int)(new DateTime(sprintf('%04d-%02d-01', $prevYear, $prevMonth)))->format('t');
                            for ($i = $startDayOfWeek - 1; $i >= 0; $i--) {
                                $dayNum = $prevMonthDays - $i;
                                $dayDate = sprintf('%04d-%02d-%02d', $prevYear, $prevMonth, $dayNum);
                                ?>
                                <div class="min-h-[120px] md:min-h-[140px] p-2 bg-slate-50/40 text-slate-300 flex flex-col justify-between group">
                                    <div class="flex justify-between items-start">
                                        <span class="text-xs font-bold text-slate-300"><?= $dayNum ?></span>
                                    </div>
                                </div>
                                <?php
                            }

                            // Dias do mês atual
                            $todayStr = date('Y-m-d');
                            for ($day = 1; $day <= $daysInCurrentMonth; $day++) {
                                $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
                                $isToday = ($dateStr === $todayStr);
                                $daySprints = $sprintsByDate[$dateStr] ?? [];
                                ?>
                                <div class="min-h-[120px] md:min-h-[140px] p-2 hover:bg-slate-50/70 transition-colors flex flex-col justify-start relative group">
                                    <!-- Cabeçalho do Dia -->
                                    <div class="flex justify-between items-center mb-1.5">
                                        <span class="text-xs font-bold rounded-full w-6 h-6 flex items-center justify-center transition-all <?= $isToday ? 'bg-senai-blue text-white shadow-md' : 'text-slate-700' ?>">
                                            <?= $day ?>
                                        </span>

                                        <a href="sprint_create.php?date=<?= $dateStr ?>" class="opacity-0 group-hover:opacity-100 text-[10px] text-senai-blue bg-blue-50 hover:bg-senai-blue hover:text-white px-1.5 py-0.5 rounded transition-all flex items-center gap-0.5" title="Agendar prova neste dia">
                                            <i class="ph-bold ph-plus"></i> Prova
                                        </a>
                                    </div>

                                    <!-- Chips de Provas Agendadas no Dia -->
                                    <div class="space-y-1.5 overflow-y-auto max-h-[110px] no-scrollbar">
                                        <?php foreach ($daySprints as $s): 
                                            // Cores por status
                                            $pillColor = "bg-senai-blue/10 text-senai-blue border-senai-blue/20 hover:bg-senai-blue hover:text-white";
                                            $icon = "ph-play";
                                            if ($s['is_scheduled_future']) {
                                                $pillColor = "bg-indigo-50 text-indigo-700 border-indigo-200 hover:bg-indigo-600 hover:text-white";
                                                $icon = "ph-clock";
                                            } elseif ($s['status'] === 'completed') {
                                                $pillColor = "bg-green-50 text-green-700 border-green-200 hover:bg-green-600 hover:text-white";
                                                $icon = "ph-check";
                                            }

                                            $timeLabel = !empty($s['start_time']) ? date('H:i', strtotime($s['start_time'])) : date('H:i', strtotime($s['created_at']));
                                        ?>
                                            <button type="button" onclick="openSprintModal(<?= $s['id'] ?>)" class="w-full text-left px-2 py-1.5 rounded-lg border text-[11px] font-semibold transition-all flex items-center gap-1.5 shadow-sm truncate <?= $pillColor ?>" title="<?= htmlspecialchars($s['name']) ?> (<?= htmlspecialchars($s['class_name']) ?>)">
                                                <i class="ph-bold <?= $icon ?> shrink-0 text-[10px]"></i>
                                                <span class="font-bold shrink-0"><?= $timeLabel ?></span>
                                                <span class="truncate"><?= htmlspecialchars($s['name']) ?></span>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php
                            }

                            // Dias do próximo mês para completar a última semana
                            $totalCells = $startDayOfWeek + $daysInCurrentMonth;
                            $trailingDays = (7 - ($totalCells % 7)) % 7;
                            for ($j = 1; $j <= $trailingDays; $j++) {
                                ?>
                                <div class="min-h-[120px] md:min-h-[140px] p-2 bg-slate-50/40 text-slate-300 flex flex-col justify-between">
                                    <span class="text-xs font-bold text-slate-300"><?= $j ?></span>
                                </div>
                                <?php
                            }
                            ?>
                        </div>
                    </div>

                <!-- ========================================================= -->
                <!-- VISÃO 2: LISTA DE CRONOGRAMA                              -->
                <!-- ========================================================= -->
                <?php else: ?>
                    <div class="space-y-4">
                        <?php if (empty($allSprints)): ?>
                            <div class="bg-white/80 p-12 rounded-3xl border border-white text-center shadow-sm">
                                <i class="ph-thin ph-calendar-x text-6xl text-slate-300 mb-3"></i>
                                <h3 class="text-lg font-bold text-slate-700">Nenhuma Sprint encontrada</h3>
                                <p class="text-sm text-slate-400 max-w-md mx-auto mt-1 mb-6">Não há avaliações agendadas ou realizadas que correspondam aos filtros selecionados para o período.</p>
                                <a href="sprint_create.php" class="bg-senai-blue text-white px-6 py-2.5 rounded-xl text-xs font-bold shadow-md hover:bg-[#153673] transition-all inline-flex items-center gap-2">
                                    <i class="ph-bold ph-plus"></i> Criar Nova Sprint
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="bg-white rounded-3xl border border-slate-200/80 shadow-md divide-y divide-slate-100 overflow-hidden">
                                <?php foreach ($allSprints as $s): 
                                    $isScheduled = $s['is_scheduled_future'];
                                    $isCompleted = ($s['status'] === 'completed');
                                    
                                    $statusBadge = "bg-senai-blue/10 text-senai-blue border-senai-blue/20";
                                    if ($isScheduled) {
                                        $statusBadge = "bg-indigo-50 text-indigo-700 border-indigo-200";
                                    } elseif ($isCompleted) {
                                        $statusBadge = "bg-green-50 text-green-700 border-green-200";
                                    }

                                    $displayDate = !empty($s['start_time']) ? date('d/m/Y \à\s H:i', strtotime($s['start_time'])) : date('d/m/Y \à\s H:i', strtotime($s['created_at']));
                                ?>
                                <div class="p-5 hover:bg-slate-50/80 transition-all flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                                    <div class="flex items-start gap-4">
                                        <div class="w-12 h-12 rounded-2xl flex items-center justify-center shrink-0 shadow-inner <?= $isScheduled ? 'bg-indigo-100 text-indigo-600' : ($isCompleted ? 'bg-green-100 text-green-600' : 'bg-blue-100 text-senai-blue') ?>">
                                            <i class="ph-fill <?= $isScheduled ? 'ph-clock-countdown' : ($isCompleted ? 'ph-check-circle' : 'ph-play-circle') ?> text-2xl"></i>
                                        </div>
                                        <div>
                                            <div class="flex items-center gap-2 flex-wrap mb-1">
                                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-md border uppercase tracking-wider <?= $statusBadge ?>">
                                                    <?= $s['display_status'] ?>
                                                </span>
                                                <span class="text-xs text-slate-400 font-medium">
                                                    <i class="ph-bold ph-calendar"></i> <?= $displayDate ?>
                                                </span>
                                                <?php if (!empty($s['end_time'])): ?>
                                                    <span class="text-xs text-slate-400 font-medium">
                                                        • Limite: <?= date('d/m/Y H:i', strtotime($s['end_time'])) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <h4 class="text-base font-bold text-slate-800 hover:text-senai-blue transition-colors cursor-pointer" onclick="openSprintModal(<?= $s['id'] ?>)">
                                                <?= htmlspecialchars($s['name']) ?>
                                            </h4>
                                            <p class="text-xs text-slate-500 font-medium mt-0.5">
                                                Turma: <strong class="text-slate-700"><?= htmlspecialchars($s['class_name']) ?></strong> • 
                                                Curso: <?= htmlspecialchars($s['course_name']) ?> 
                                                <?php if (!empty($s['unit_name'])): ?>
                                                    • <span class="text-slate-400"><?= htmlspecialchars($s['unit_name']) ?></span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-3 w-full md:w-auto justify-end border-t md:border-t-0 pt-3 md:pt-0">
                                        <div class="text-right hidden sm:block mr-2">
                                            <p class="text-xs font-bold text-slate-700"><?= $s['students_started'] ?> / <?= $s['total_students'] ?> Alunos</p>
                                            <p class="text-[10px] text-slate-400"><?= $s['total_questions'] ?> Questões • <?= $s['time_limit_minutes'] ?> min</p>
                                        </div>

                                        <button onclick="openSprintModal(<?= $s['id'] ?>)" class="px-3.5 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold transition-all flex items-center gap-1.5">
                                            <i class="ph-bold ph-gear"></i> Detalhes / Reagendar
                                        </button>

                                        <a href="sprint_report.php?id=<?= $s['id'] ?>" class="px-3.5 py-2 rounded-xl bg-senai-blue text-white text-xs font-bold hover:bg-[#153673] transition-all flex items-center gap-1.5 shadow-sm">
                                            <i class="ph-bold ph-chart-line-up"></i> Relatório
                                        </a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </main>
</div>

<!-- ========================================================= -->
<!-- MODAL DE DETALHES E REAGENDAMENTO RÁPIDO                   -->
<!-- ========================================================= -->
<div id="sprintModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-xl overflow-hidden scale-95 opacity-0 transition-all duration-300 flex flex-col max-h-[90vh]" id="sprintModalContent">
        
        <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/80 shrink-0">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-senai-blue/10 text-senai-blue flex items-center justify-center font-bold">
                    <i class="ph-bold ph-calendar-check text-lg"></i>
                </div>
                <h3 class="font-bold text-base text-slate-800" id="modal-title">Detalhes da Sprint</h3>
            </div>
            <button onclick="closeSprintModal()" class="text-slate-400 hover:text-red-500 transition-colors bg-white rounded-full p-1 shadow-sm border border-slate-200">
                <i class="ph-bold ph-x text-base"></i>
            </button>
        </div>

        <div class="p-6 overflow-y-auto flex-1 space-y-5">
            <!-- Badges e Status -->
            <div class="flex items-center justify-between flex-wrap gap-2">
                <span id="modal-badge-status" class="text-xs font-bold px-3 py-1 rounded-full uppercase tracking-wider"></span>
                <span id="modal-badge-teacher" class="text-xs font-semibold text-slate-500 flex items-center gap-1"></span>
            </div>

            <!-- Dados Principais -->
            <div class="bg-slate-50 p-4 rounded-2xl border border-slate-200/80 space-y-2 text-sm">
                <p><span class="text-slate-400 font-bold text-xs uppercase tracking-wider block">Turma & Curso:</span> 
                    <strong class="text-slate-800" id="modal-class"></strong> • <span class="text-slate-600" id="modal-course"></span>
                </p>
                <p><span class="text-slate-400 font-bold text-xs uppercase tracking-wider block">Local:</span> 
                    <span class="text-slate-700" id="modal-location"></span>
                </p>
                <div class="grid grid-cols-3 gap-2 pt-2 border-t border-slate-200 text-center">
                    <div>
                        <p class="text-[10px] uppercase font-bold text-slate-400">Questões</p>
                        <p class="text-base font-black text-slate-700" id="modal-questions">-</p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase font-bold text-slate-400">Tempo Limite</p>
                        <p class="text-base font-black text-slate-700" id="modal-timelimit">-</p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase font-bold text-slate-400">Tempo Mínimo</p>
                        <p class="text-base font-black text-slate-700" id="modal-timemin">-</p>
                    </div>
                </div>
            </div>

            <!-- Formulário de Reagendamento / Alteração de Data -->
            <form id="form-reschedule" onsubmit="submitReschedule(event)" class="p-4 bg-indigo-50/50 rounded-2xl border border-indigo-100 space-y-3">
                <input type="hidden" id="modal-sprint-id" name="sprint_id" value="">
                <input type="hidden" name="action" value="reschedule">

                <h4 class="text-xs font-bold text-indigo-900 uppercase tracking-wider flex items-center gap-1.5">
                    <i class="ph-bold ph-pencil-simple"></i> Reagendar / Alterar Horário
                </h4>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 mb-1">Data e Hora de Início</label>
                        <input type="datetime-local" id="modal-input-start" name="start_time" class="w-full px-3 py-2 rounded-xl border border-indigo-200 bg-white text-xs font-semibold text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 mb-1">Data e Hora Término (Opcional)</label>
                        <input type="datetime-local" id="modal-input-end" name="end_time" class="w-full px-3 py-2 rounded-xl border border-indigo-200 bg-white text-xs font-semibold text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                    </div>
                </div>

                <div class="flex items-center justify-between pt-1">
                    <span id="reschedule-feedback" class="text-xs font-bold"></span>
                    <button type="submit" id="btn-save-reschedule" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-xl text-xs font-bold transition-all flex items-center gap-1.5 shadow-sm">
                        <i class="ph-bold ph-floppy-disk"></i> Salvar Agendamento
                    </button>
                </div>
            </form>

            <!-- Ações Diretas -->
            <div class="flex items-center gap-2 pt-2">
                <a id="modal-link-report" href="#" class="flex-1 text-center bg-senai-blue hover:bg-[#153673] text-white py-2.5 rounded-xl font-bold text-xs shadow-md transition-all flex items-center justify-center gap-1.5">
                    <i class="ph-bold ph-chart-line-up"></i> Relatório / Raio-X
                </a>
                <a id="modal-link-class" href="#" class="flex-1 text-center bg-slate-100 hover:bg-slate-200 text-slate-700 py-2.5 rounded-xl font-bold text-xs transition-all flex items-center justify-center gap-1.5">
                    <i class="ph-bold ph-users-three"></i> Ver Turma
                </a>
            </div>
        </div>
    </div>
</div>

<script>
    const sprintsDatabase = <?= json_encode($sprintsJson, JSON_INVALID_UTF8_SUBSTITUTE) ?>;

    function toggleHelpCards() {
        const container = document.getElementById('help-cards-container');
        if (container) {
            container.classList.toggle('hidden');
        }
    }

    function openSprintModal(sprintId) {
        const s = sprintsDatabase[sprintId];
        if (!s) return;

        document.getElementById('modal-sprint-id').value = s.id;
        document.getElementById('modal-title').textContent = s.name;
        document.getElementById('modal-class').textContent = s.class_name;
        document.getElementById('modal-course').textContent = s.course_name;
        document.getElementById('modal-location').textContent = (s.unit_name || 'Sede') + (s.regional_name ? ' (' + s.regional_name + ')' : '');
        document.getElementById('modal-questions').textContent = s.total_questions;
        document.getElementById('modal-timelimit').textContent = s.time_limit_minutes + ' min';
        document.getElementById('modal-timemin').textContent = s.time_min_minutes ? s.time_min_minutes + ' min' : 'Livre';
        
        document.getElementById('modal-badge-teacher').innerHTML = '<i class="ph-bold ph-user"></i> Prof: ' + (s.teacher_name || 'Não atribuído');

        const badge = document.getElementById('modal-badge-status');
        badge.className = 'text-xs font-bold px-3 py-1 rounded-full uppercase tracking-wider';
        if (s.is_scheduled_future) {
            badge.textContent = 'Agendada (Futura)';
            badge.classList.add('bg-indigo-100', 'text-indigo-700');
        } else if (s.status === 'completed') {
            badge.textContent = 'Concluída';
            badge.classList.add('bg-green-100', 'text-green-700');
        } else {
            badge.textContent = 'Ativa / Liberada';
            badge.classList.add('bg-blue-100', 'text-senai-blue');
        }

        // Inputs datetime-local
        document.getElementById('modal-input-start').value = s.start_time ? s.start_time.replace(' ', 'T').slice(0, 16) : '';
        document.getElementById('modal-input-end').value = s.end_time ? s.end_time.replace(' ', 'T').slice(0, 16) : '';

        // Links
        document.getElementById('modal-link-report').href = 'sprint_report.php?id=' + s.id;
        document.getElementById('modal-link-class').href = 'class_manage.php?id=' + s.class_id;

        document.getElementById('reschedule-feedback').textContent = '';

        // Exibe modal com animação
        const modal = document.getElementById('sprintModal');
        const content = document.getElementById('sprintModalContent');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setTimeout(() => {
            content.classList.remove('scale-95', 'opacity-0');
        }, 10);
    }

    function closeSprintModal() {
        const modal = document.getElementById('sprintModal');
        const content = document.getElementById('sprintModalContent');
        content.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }, 200);
    }

    function submitReschedule(e) {
        e.preventDefault();
        const form = document.getElementById('form-reschedule');
        const formData = new FormData(form);
        const feedback = document.getElementById('reschedule-feedback');
        const btn = document.getElementById('btn-save-reschedule');

        btn.disabled = true;
        btn.innerHTML = '<i class="ph-bold ph-spinner animate-spin"></i> Salvando...';

        fetch('agenda_ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i class="ph-bold ph-floppy-disk"></i> Salvar Agendamento';
            
            if (data.success) {
                feedback.className = 'text-xs font-bold text-green-600';
                feedback.textContent = '✔ ' + data.message;
                setTimeout(() => {
                    window.location.reload();
                }, 900);
            } else {
                feedback.className = 'text-xs font-bold text-red-500';
                feedback.textContent = '✖ ' + (data.error || 'Erro ao reagendar.');
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="ph-bold ph-floppy-disk"></i> Salvar Agendamento';
            feedback.className = 'text-xs font-bold text-red-500';
            feedback.textContent = 'Erro de comunicação.';
        });
    }
</script>

<?php include 'includes/footer.php'; ?>

