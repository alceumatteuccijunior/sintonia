<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

require_once 'config.php';
$pdo = getPDOConnection(true);

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

// -------------------------
// Filtros Hierárquicos e de Busca
// -------------------------
$filter_regional_id = !empty($_GET['regional_id']) ? (int)$_GET['regional_id'] : null;
$filter_unit_id     = !empty($_GET['unit_id']) ? (int)$_GET['unit_id'] : null;
$filter_course_id   = !empty($_GET['course_id']) ? (int)$_GET['course_id'] : null;
$filter_class_id    = !empty($_GET['class_id']) ? (int)$_GET['class_id'] : null;
$teacher_id         = !empty($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : null;
$status             = trim((string)($_GET['status'] ?? ''));
$search             = trim((string)($_GET['search'] ?? ''));

// Carrega dados para os filtros encadeados (Regional -> Unidade -> Curso -> Turma)
$regionals = $pdo->query("SELECT id, name FROM regionals ORDER BY name ASC")->fetchAll();
$all_units = $pdo->query("SELECT u.id, u.name, u.regional_id, r.name as regional_name FROM units u LEFT JOIN regionals r ON u.regional_id = r.id ORDER BY r.name ASC, u.name ASC")->fetchAll();

// Se uma unidade foi selecionada e a regional está diferente, sincroniza ou valida
if ($filter_unit_id && $filter_regional_id) {
    $unitMatchesReg = false;
    foreach ($all_units as $au) {
        if ((int)$au['id'] === $filter_unit_id && (int)$au['regional_id'] === $filter_regional_id) {
            $unitMatchesReg = true;
            break;
        }
    }
    if (!$unitMatchesReg) {
        $filter_unit_id = null;
    }
}

// Unidades visíveis no select (todas ou filtradas pela Regional)
$units_for_select = [];
foreach ($all_units as $au) {
    if (!$filter_regional_id || (int)$au['regional_id'] === $filter_regional_id) {
        $units_for_select[] = $au;
    }
}

// Cursos disponíveis dentro do escopo de Regional/Unidade selecionado
$sqlCourses = "
    SELECT DISTINCT co.id, co.name
    FROM courses co
    LEFT JOIN classes c ON c.course_id = co.id
    LEFT JOIN units un ON c.unit_id = un.id
    WHERE 1=1
";
$paramsCourses = [];
if ($filter_unit_id) {
    $sqlCourses .= " AND c.unit_id = ?";
    $paramsCourses[] = $filter_unit_id;
} elseif ($filter_regional_id) {
    $sqlCourses .= " AND un.regional_id = ?";
    $paramsCourses[] = $filter_regional_id;
}
$sqlCourses .= " ORDER BY co.name ASC";
$stmtCourses = $pdo->prepare($sqlCourses);
$stmtCourses->execute($paramsCourses);
$courses = $stmtCourses->fetchAll();

if ($filter_course_id) {
    $courseStillValid = false;
    foreach ($courses as $coItem) {
        if ((int)$coItem['id'] === $filter_course_id) {
            $courseStillValid = true;
            break;
        }
    }
    if (!$courseStillValid) {
        $filter_course_id = null;
    }
}

// Turmas disponíveis dentro do escopo de Regional/Unidade/Curso selecionado
$sqlClassesFilter = "
    SELECT c.id, c.name, c.year, c.semester, co.name as course_name, un.name as unit_name
    FROM classes c
    LEFT JOIN courses co ON c.course_id = co.id
    LEFT JOIN units un ON c.unit_id = un.id
    WHERE 1=1
";
$paramsClassesFilter = [];
if ($filter_unit_id) {
    $sqlClassesFilter .= " AND c.unit_id = ?";
    $paramsClassesFilter[] = $filter_unit_id;
} elseif ($filter_regional_id) {
    $sqlClassesFilter .= " AND un.regional_id = ?";
    $paramsClassesFilter[] = $filter_regional_id;
}
if ($filter_course_id) {
    $sqlClassesFilter .= " AND c.course_id = ?";
    $paramsClassesFilter[] = $filter_course_id;
}
$sqlClassesFilter .= " ORDER BY c.name ASC";
$stmtClassesFilter = $pdo->prepare($sqlClassesFilter);
$stmtClassesFilter->execute($paramsClassesFilter);
$classes_for_select = $stmtClassesFilter->fetchAll();

if ($filter_class_id) {
    $classStillValid = false;
    foreach ($classes_for_select as $clItem) {
        if ((int)$clItem['id'] === $filter_class_id) {
            $classStillValid = true;
            break;
        }
    }
    if (!$classStillValid) {
        $filter_class_id = null;
    }
}

// Professores no escopo selecionado
$teacher_sql = "SELECT id, name FROM users WHERE role IN ('teacher', 'admin')";
$teacher_params = [];
if ($filter_unit_id) {
    $teacher_sql .= " AND unit_id = ?";
    $teacher_params[] = $filter_unit_id;
} elseif ($filter_regional_id) {
    $teacher_sql .= " AND unit_id IN (SELECT id FROM units WHERE regional_id = ?)";
    $teacher_params[] = $filter_regional_id;
}
$teacher_sql .= " ORDER BY name ASC";
$stmtTeachers = $pdo->prepare($teacher_sql);
$stmtTeachers->execute($teacher_params);
$teachers = $stmtTeachers->fetchAll();

// -------------------------
// Cláusula Base Compartilhada (Sprints Oficiais + Escopo Selecionado)
// -------------------------
$where_scope = "
    WHERE s.teacher_id IS NOT NULL
      AND s.name NOT LIKE 'DUELO|%'
      AND s.name NOT LIKE 'Missão de Treinamento%'
";
$params_scope = [];

if ($filter_unit_id) {
    $where_scope .= " AND c.unit_id = ?";
    $params_scope[] = $filter_unit_id;
} elseif ($filter_regional_id) {
    $where_scope .= " AND un.regional_id = ?";
    $params_scope[] = $filter_regional_id;
}
if ($filter_course_id) {
    $where_scope .= " AND c.course_id = ?";
    $params_scope[] = $filter_course_id;
}
if ($filter_class_id) {
    $where_scope .= " AND c.id = ?";
    $params_scope[] = $filter_class_id;
}
if ($teacher_id) {
    $where_scope .= " AND s.teacher_id = ?";
    $params_scope[] = $teacher_id;
}
if ($status !== '') {
    $where_scope .= " AND s.status = ?";
    $params_scope[] = $status;
}
if ($search !== '') {
    $where_scope .= " AND (s.name LIKE ? OR c.name LIKE ? OR co.name LIKE ?)";
    $params_scope[] = "%$search%";
    $params_scope[] = "%$search%";
    $params_scope[] = "%$search%";
}

// -------------------------
// KPIs Dinâmicos do Escopo
// -------------------------
// 1. Questões no Banco (filtrado por curso se selecionado)
if ($filter_course_id) {
    $stmtQs = $pdo->prepare("SELECT COUNT(*) FROM questions q JOIN modules m ON q.module_id = m.id WHERE m.course_id = ?");
    $stmtQs->execute([$filter_course_id]);
} else {
    $stmtQs = $pdo->query("SELECT COUNT(*) FROM questions");
}
$total_questions = (int)$stmtQs->fetchColumn();

// 2. Turmas no escopo
$total_classes = count($classes_for_select);

// 3. Usuários/Alunos no escopo
if ($filter_class_id) {
    $stmtUsers = $pdo->prepare("SELECT COUNT(DISTINCT student_id) FROM class_students WHERE class_id = ?");
    $stmtUsers->execute([$filter_class_id]);
} elseif ($filter_course_id) {
    $sqlU = "SELECT COUNT(DISTINCT cs.student_id) FROM class_students cs JOIN classes c ON cs.class_id = c.id LEFT JOIN units un ON c.unit_id = un.id WHERE c.course_id = ?";
    $pU = [$filter_course_id];
    if ($filter_unit_id) { $sqlU .= " AND c.unit_id = ?"; $pU[] = $filter_unit_id; }
    elseif ($filter_regional_id) { $sqlU .= " AND un.regional_id = ?"; $pU[] = $filter_regional_id; }
    $stmtUsers = $pdo->prepare($sqlU);
    $stmtUsers->execute($pU);
} elseif ($filter_unit_id) {
    $stmtUsers = $pdo->prepare("SELECT COUNT(*) FROM users WHERE unit_id = ?");
    $stmtUsers->execute([$filter_unit_id]);
} elseif ($filter_regional_id) {
    $stmtUsers = $pdo->prepare("SELECT COUNT(*) FROM users WHERE unit_id IN (SELECT id FROM units WHERE regional_id = ?)");
    $stmtUsers->execute([$filter_regional_id]);
} else {
    $stmtUsers = $pdo->query("SELECT COUNT(*) FROM users");
}
$total_users = (int)$stmtUsers->fetchColumn();

// 4. Listagem de Sprints Oficiais no escopo
$sqlSprintsList = "
    SELECT s.*,
           c.name as class_name,
           co.name as course_name,
           un.name as unit_name,
           reg.name as regional_name,
           u.name as teacher_name,
           (SELECT COUNT(*) FROM sprint_questions WHERE sprint_id = s.id) as total_questions,
           (SELECT COUNT(DISTINCT student_id) FROM sprint_attempts WHERE sprint_id = s.id) as students_started
    FROM sprints s
    JOIN classes c ON s.class_id = c.id
    LEFT JOIN courses co ON c.course_id = co.id
    LEFT JOIN units un ON c.unit_id = un.id
    LEFT JOIN regionals reg ON un.regional_id = reg.id
    LEFT JOIN users u ON s.teacher_id = u.id
    $where_scope
    ORDER BY COALESCE(s.start_time, s.created_at) DESC
";
$stmtAllSprints = $pdo->prepare($sqlSprintsList);
$stmtAllSprints->execute($params_scope);
$sprints = $stmtAllSprints->fetchAll();
$total_sprints = count($sprints);

// =========================================================
// INTELIGÊNCIA GLOBAL & MULTI-DIMENSIONAL (GRÁFICOS)
// =========================================================

// KPI: Taxa Média Global de Acertos no Escopo (%)
$stmtAcc = $pdo->prepare("
    SELECT SUM(CASE WHEN sa.is_correct = 1 THEN 1 ELSE 0 END) as correct_answers,
           COUNT(sa.id) as total_answers,
           COUNT(DISTINCT sa.student_id) as evaluated_students
    FROM student_answers sa
    JOIN sprints s ON sa.sprint_id = s.id
    JOIN classes c ON s.class_id = c.id
    LEFT JOIN courses co ON c.course_id = co.id
    LEFT JOIN units un ON c.unit_id = un.id
    $where_scope
");
$stmtAcc->execute($params_scope);
$accData = $stmtAcc->fetch();
$total_answers_scope = (int)($accData['total_answers'] ?? 0);
$evaluated_students_scope = (int)($accData['evaluated_students'] ?? 0);
$global_accuracy = ($total_answers_scope > 0) ? round(($accData['correct_answers'] / $total_answers_scope) * 100, 1) : 0;

// Distribuição de Saúde dos Alunos no Escopo (Alto >= 70%, Atenção 50-69%, Crítico < 50%)
$stmtStudentHealth = $pdo->prepare("
    SELECT sa.student_id,
           SUM(CASE WHEN sa.is_correct = 1 THEN 1 ELSE 0 END) as correct_answers,
           COUNT(sa.id) as total_answers
    FROM student_answers sa
    JOIN sprints s ON sa.sprint_id = s.id
    JOIN classes c ON s.class_id = c.id
    LEFT JOIN courses co ON c.course_id = co.id
    LEFT JOIN units un ON c.unit_id = un.id
    $where_scope
    GROUP BY sa.student_id
");
$stmtStudentHealth->execute($params_scope);
$health_high = 0;
$health_med  = 0;
$health_low  = 0;
foreach ($stmtStudentHealth->fetchAll() as $sh) {
    if ($sh['total_answers'] <= 0) continue;
    $sPct = ($sh['correct_answers'] / $sh['total_answers']) * 100;
    if ($sPct >= 70) $health_high++;
    elseif ($sPct >= 50) $health_med++;
    else $health_low++;
}

// ---------------------------------------------------------
// GRÁFICO 1: EVOLUÇÃO HISTÓRICA (GERAL / POR CURSO / POR TURMA / POR UNIDADE)
// ---------------------------------------------------------
// 1A. Evolução por Avaliação (Sprint)
$stmtEvolSprint = $pdo->prepare("
    SELECT s.id,
           s.name as sprint_name,
           c.name as class_name,
           co.name as course_name,
           un.name as unit_name,
           COALESCE(s.start_time, s.created_at) as ref_date,
           SUM(CASE WHEN sa.is_correct = 1 THEN 1 ELSE 0 END) as correct_answers,
           COUNT(sa.id) as total_answers,
           COUNT(DISTINCT sa.student_id) as students_count
    FROM student_answers sa
    JOIN sprints s ON sa.sprint_id = s.id
    JOIN classes c ON s.class_id = c.id
    LEFT JOIN courses co ON c.course_id = co.id
    LEFT JOIN units un ON c.unit_id = un.id
    $where_scope
    GROUP BY s.id, s.name, c.name, co.name, un.name, COALESCE(s.start_time, s.created_at)
    ORDER BY ref_date ASC, s.id ASC
");
$stmtEvolSprint->execute($params_scope);
$evolSprintRows = $stmtEvolSprint->fetchAll();

// Monta séries de evolução geral e evolução multi-linha por Curso, Turma e Unidade
$evolGeneralLabels = [];
$evolGeneralValues = [];
$evolCoursesSeries = []; // course_name => [ {x: label, y: pct} ]
$evolClassesSeries = []; // class_name => [ {x: label, y: pct} ]
$evolUnitsSeries   = []; // unit_name => [ {x: label, y: pct} ]

foreach ($evolSprintRows as $er) {
    $pct = $er['total_answers'] > 0 ? round(($er['correct_answers'] / $er['total_answers']) * 100, 1) : 0;
    $datePart = !empty($er['ref_date']) ? date('d/m', strtotime($er['ref_date'])) : '';
    $shortSprint = mb_strlen($er['sprint_name'], 'UTF-8') > 24 ? mb_substr($er['sprint_name'], 0, 24, 'UTF-8') . '…' : $er['sprint_name'];
    $label = $shortSprint . ' (' . $er['class_name'] . ($datePart ? ' • ' . $datePart : '') . ')';

    $evolGeneralLabels[] = $label;
    $evolGeneralValues[] = $pct;

    $cName = $er['course_name'] ?: 'Sem Curso';
    if (!isset($evolCoursesSeries[$cName])) $evolCoursesSeries[$cName] = [];
    $evolCoursesSeries[$cName][] = ['label' => $datePart . ' - ' . $shortSprint, 'rate' => $pct];

    $clName = $er['class_name'] ?: 'Sem Turma';
    if (!isset($evolClassesSeries[$clName])) $evolClassesSeries[$clName] = [];
    $evolClassesSeries[$clName][] = ['label' => $datePart . ' - ' . $shortSprint, 'rate' => $pct];

    $uName = $er['unit_name'] ?: 'Sem Unidade';
    if (!isset($evolUnitsSeries[$uName])) $evolUnitsSeries[$uName] = [];
    $evolUnitsSeries[$uName][] = ['label' => $datePart . ' - ' . $shortSprint, 'rate' => $pct];
}

// Helper para converter séries agrupadas em datasets de evolução (Etapa 1, Etapa 2, Etapa 3...)
function buildMultiLineEvolution(array $groupedSeries, int $maxLines = 7): array {
    // Ordena pelas séries com mais avaliações
    uasort($groupedSeries, function ($a, $b) {
        return count($b) <=> count($a);
    });
    $sliced = array_slice($groupedSeries, 0, $maxLines, true);
    $maxPoints = 0;
    foreach ($sliced as $pts) {
        if (count($pts) > $maxPoints) $maxPoints = count($pts);
    }
    $labels = [];
    for ($i = 1; $i <= $maxPoints; $i++) {
        $labels[] = $i . "ª Aval.";
    }
    $palette = ['#1A428A', '#F25C27', '#06B6D4', '#10B981', '#8B5CF6', '#F59E0B', '#EC4899'];
    $datasets = [];
    $idx = 0;
    foreach ($sliced as $seriesName => $pts) {
        $dataVals = [];
        $pointTitles = [];
        for ($i = 0; $i < $maxPoints; $i++) {
            $dataVals[] = isset($pts[$i]) ? $pts[$i]['rate'] : null;
            $pointTitles[] = isset($pts[$i]) ? $pts[$i]['label'] : '';
        }
        $color = $palette[$idx % count($palette)];
        $datasets[] = [
            'label' => $seriesName,
            'data' => $dataVals,
            'pointTitles' => $pointTitles,
            'borderColor' => $color,
            'backgroundColor' => $color,
            'borderWidth' => 2.5,
            'pointRadius' => 4,
            'tension' => 0.35,
            'spanGaps' => true
        ];
        $idx++;
    }
    return ['labels' => $labels, 'datasets' => $datasets];
}

$evolByCourseData = buildMultiLineEvolution($evolCoursesSeries);
$evolByClassData  = buildMultiLineEvolution($evolClassesSeries);
$evolByUnitData   = buildMultiLineEvolution($evolUnitsSeries);

// ---------------------------------------------------------
// GRÁFICO 2: DESEMPENHO COMPARATIVO POR DIMENSÃO
// (Por Curso | Por Turma | Por Unidade Escolar | Por Regional)
// ---------------------------------------------------------
// 2A. Por Curso
$stmtByCourse = $pdo->prepare("
    SELECT COALESCE(co.name, 'Geral / Sem Curso') as label_name,
           SUM(CASE WHEN sa.is_correct = 1 THEN 1 ELSE 0 END) as correct_answers,
           COUNT(sa.id) as total_answers,
           COUNT(DISTINCT sa.student_id) as students_count,
           COUNT(DISTINCT s.id) as sprints_count
    FROM student_answers sa
    JOIN sprints s ON sa.sprint_id = s.id
    JOIN classes c ON s.class_id = c.id
    LEFT JOIN courses co ON c.course_id = co.id
    LEFT JOIN units un ON c.unit_id = un.id
    $where_scope
    GROUP BY co.id, COALESCE(co.name, 'Geral / Sem Curso')
    ORDER BY (SUM(CASE WHEN sa.is_correct = 1 THEN 1 ELSE 0 END) / COUNT(sa.id)) DESC
");
$stmtByCourse->execute($params_scope);
$perfByCourse = $stmtByCourse->fetchAll();

// 2B. Por Turma
$stmtByClass = $pdo->prepare("
    SELECT CONCAT(c.name, CASE WHEN co.name IS NOT NULL THEN CONCAT(' (', co.name, ')') ELSE '' END) as label_name,
           SUM(CASE WHEN sa.is_correct = 1 THEN 1 ELSE 0 END) as correct_answers,
           COUNT(sa.id) as total_answers,
           COUNT(DISTINCT sa.student_id) as students_count,
           COUNT(DISTINCT s.id) as sprints_count
    FROM student_answers sa
    JOIN sprints s ON sa.sprint_id = s.id
    JOIN classes c ON s.class_id = c.id
    LEFT JOIN courses co ON c.course_id = co.id
    LEFT JOIN units un ON c.unit_id = un.id
    $where_scope
    GROUP BY c.id, c.name, co.name
    ORDER BY (SUM(CASE WHEN sa.is_correct = 1 THEN 1 ELSE 0 END) / COUNT(sa.id)) DESC
");
$stmtByClass->execute($params_scope);
$perfByClass = $stmtByClass->fetchAll();

// 2C. Por Unidade Escolar
$stmtByUnit = $pdo->prepare("
    SELECT COALESCE(un.name, 'Sem Unidade Vinculada') as label_name,
           SUM(CASE WHEN sa.is_correct = 1 THEN 1 ELSE 0 END) as correct_answers,
           COUNT(sa.id) as total_answers,
           COUNT(DISTINCT sa.student_id) as students_count,
           COUNT(DISTINCT s.id) as sprints_count
    FROM student_answers sa
    JOIN sprints s ON sa.sprint_id = s.id
    JOIN classes c ON s.class_id = c.id
    LEFT JOIN courses co ON c.course_id = co.id
    LEFT JOIN units un ON c.unit_id = un.id
    $where_scope
    GROUP BY un.id, COALESCE(un.name, 'Sem Unidade Vinculada')
    ORDER BY (SUM(CASE WHEN sa.is_correct = 1 THEN 1 ELSE 0 END) / COUNT(sa.id)) DESC
");
$stmtByUnit->execute($params_scope);
$perfByUnit = $stmtByUnit->fetchAll();

// 2D. Por Regional
$stmtByReg = $pdo->prepare("
    SELECT COALESCE(reg.name, 'Sem Regional Vinculada') as label_name,
           SUM(CASE WHEN sa.is_correct = 1 THEN 1 ELSE 0 END) as correct_answers,
           COUNT(sa.id) as total_answers,
           COUNT(DISTINCT sa.student_id) as students_count,
           COUNT(DISTINCT s.id) as sprints_count
    FROM student_answers sa
    JOIN sprints s ON sa.sprint_id = s.id
    JOIN classes c ON s.class_id = c.id
    LEFT JOIN courses co ON c.course_id = co.id
    LEFT JOIN units un ON c.unit_id = un.id
    LEFT JOIN regionals reg ON un.regional_id = reg.id
    $where_scope
    GROUP BY reg.id, COALESCE(reg.name, 'Sem Regional Vinculada')
    ORDER BY (SUM(CASE WHEN sa.is_correct = 1 THEN 1 ELSE 0 END) / COUNT(sa.id)) DESC
");
$stmtByReg->execute($params_scope);
$perfByRegional = $stmtByReg->fetchAll();

function formatDimensionDataset(array $rows): array {
    $labels = [];
    $rates = [];
    $meta = [];
    foreach ($rows as $r) {
        $pct = $r['total_answers'] > 0 ? round(($r['correct_answers'] / $r['total_answers']) * 100, 1) : 0;
        $labels[] = $r['label_name'];
        $rates[]  = $pct;
        $meta[]   = [
            'students' => (int)$r['students_count'],
            'sprints'  => (int)$r['sprints_count'],
            'answers'  => (int)$r['total_answers'],
            'correct'  => (int)$r['correct_answers']
        ];
    }
    return ['labels' => $labels, 'rates' => $rates, 'meta' => $meta];
}

$dimComparisons = [
    'course'   => formatDimensionDataset($perfByCourse),
    'class'    => formatDimensionDataset($perfByClass),
    'unit'     => formatDimensionDataset($perfByUnit),
    'regional' => formatDimensionDataset($perfByRegional),
];

// Aba inicial inteligente para o Gráfico 2 conforme o filtro ativo
$defaultDimTab = 'course';
if ($filter_course_id && !$filter_class_id) {
    $defaultDimTab = 'class';
} elseif ($filter_regional_id && !$filter_unit_id && count($perfByUnit) > 1) {
    $defaultDimTab = 'course';
}

// ---------------------------------------------------------
// GRÁFICO 4: MAPEAMENTO DE CAPACIDADES DA MATRIZ SAEP
// Agrupado por Curso + Código da Capacidade (nunca mistura C1 de cursos diferentes!)
// ---------------------------------------------------------
$stmtCap = $pdo->prepare("
    SELECT co.id as course_id,
           COALESCE(co.name, m.name, 'Geral') as course_name,
           q.capacity as capacity_code,
           COALESCE(cc.description, mc.description, '') as capacity_desc,
           SUM(CASE WHEN sa.is_correct = 1 THEN 1 ELSE 0 END) as correct_answers,
           COUNT(sa.id) as total_answers
    FROM student_answers sa
    JOIN questions q ON sa.question_id = q.id
    JOIN modules m ON q.module_id = m.id
    LEFT JOIN courses co ON m.course_id = co.id
    LEFT JOIN course_capacities cc ON cc.course_id = m.course_id AND cc.capacity_code = q.capacity
    LEFT JOIN module_capacities mc ON mc.module_id = q.module_id AND mc.capacity_code = q.capacity
    JOIN sprints s ON sa.sprint_id = s.id
    JOIN classes c ON s.class_id = c.id
    LEFT JOIN units un ON c.unit_id = un.id
    $where_scope
      AND q.capacity IS NOT NULL
      AND q.capacity != ''
    GROUP BY co.id, COALESCE(co.name, m.name, 'Geral'), q.capacity, COALESCE(cc.description, mc.description, '')
    ORDER BY (SUM(CASE WHEN sa.is_correct = 1 THEN 1 ELSE 0 END) / COUNT(sa.id)) ASC
");
$stmtCap->execute($params_scope);
$capRowsRaw = $stmtCap->fetchAll();

$distinctCoursesInCaps = count(array_unique(array_column($capRowsRaw, 'course_name')));
$capacitiesAll = [];
foreach ($capRowsRaw as $cr) {
    $pct = $cr['total_answers'] > 0 ? round(($cr['correct_answers'] / $cr['total_answers']) * 100, 1) : 0;
    $code = trim((string)$cr['capacity_code']);
    $desc = trim((string)$cr['capacity_desc']);
    $cName = trim((string)$cr['course_name']);

    $prefix = ($distinctCoursesInCaps > 1 && !$filter_course_id) ? "[{$cName}] " : "";
    $shortDesc = $desc !== ''
        ? (mb_strlen($desc, 'UTF-8') > 68 ? mb_substr($desc, 0, 68, 'UTF-8') . '…' : $desc)
        : 'Sem descrição na Matriz';

    $capacitiesAll[] = [
        'short_label' => $prefix . $code . ' — ' . $shortDesc,
        'full_label'  => ($cName ? "[{$cName}] " : "") . $code . ($desc !== '' ? ' — ' . $desc : ''),
        'rate'        => $pct,
        'correct'     => (int)$cr['correct_answers'],
        'total'       => (int)$cr['total_answers']
    ];
}

// Monta texto do Escopo Ativo para exibição clara ao gestor
$scopeBadges = [];
if ($filter_regional_id) {
    foreach ($regionals as $r) { if ((int)$r['id'] === $filter_regional_id) $scopeBadges[] = "Regional: " . $r['name']; }
}
if ($filter_unit_id) {
    foreach ($all_units as $u) { if ((int)$u['id'] === $filter_unit_id) $scopeBadges[] = "Unidade: " . $u['name']; }
}
if ($filter_course_id) {
    foreach ($courses as $c) { if ((int)$c['id'] === $filter_course_id) $scopeBadges[] = "Curso: " . $c['name']; }
}
if ($filter_class_id) {
    foreach ($classes_for_select as $cl) { if ((int)$cl['id'] === $filter_class_id) $scopeBadges[] = "Turma: " . $cl['name']; }
}
if (empty($scopeBadges)) {
    $scopeBadges[] = "Visão Global (Todas as Regionais, Unidades, Cursos e Turmas)";
}

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
                <div class="mb-8 animate-slide-up flex flex-col md:flex-row justify-between items-center md:items-end gap-4 text-center md:text-left">
                    <div>
                        <h1 class="text-3xl md:text-4xl font-bold tracking-tight mb-2 drop-shadow-sm text-slate-800 flex items-center justify-center md:justify-start gap-3">
                            Visão Global
                            <button onclick="openHelpModal('Visão Global (Admin)', 'Esta tela oferece uma visão panorâmica e estratégica de toda a instituição.<br><br><b>O que você pode fazer aqui:</b><br>- Filtrar e cruzar indicadores por Regional, Unidade Escolar, Curso Técnico e Turma.<br>- Comparar a evolução e o desempenho entre Cursos, Unidades, Turmas e Regionais.<br>- Mapear as Capacidades Oficiais da Matriz SAEP mais críticas e mais consolidadas.')" class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 hover:text-senai-blue hover:bg-blue-50 transition-colors shadow-inner" title="Como Usar">
                                <i class="ph-bold ph-question text-lg"></i>
                            </button>
                        </h1>
                        <div class="flex flex-wrap items-center justify-center md:justify-start gap-2 mt-1">
                            <?php foreach ($scopeBadges as $sb): ?>
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-senai-blue/10 text-senai-blue border border-senai-blue/20">
                                    <i class="ph-fill ph-funnel"></i> <?= htmlspecialchars($sb) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <a href="admin_system_map.php" class="bg-white/90 hover:bg-senai-blue hover:text-white border border-slate-200/80 text-senai-blue px-4 py-2.5 rounded-xl font-bold text-xs shadow-sm hover:shadow-md transition-all flex items-center gap-2 shrink-0">
                        <i class="ph-bold ph-tree-structure text-base"></i> Mapa do Sistema &amp; Guia Master
                    </a>
                </div>

                <!-- Painel de KPIs Globais (5 Cards Dinâmicos) -->
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4 w-full mb-8">
                    <div class="bg-white/75 backdrop-blur-md border border-white/80 p-5 rounded-2xl shadow-sm hover:shadow-md transition-all group">
                        <div class="w-10 h-10 rounded-full bg-emerald-500/10 flex items-center justify-center text-emerald-600 mb-3 group-hover:scale-110 transition-transform">
                            <i class="ph-fill ph-chart-line-up text-xl"></i>
                        </div>
                        <h3 class="text-3xl font-extrabold <?= $global_accuracy >= 70 ? 'text-emerald-600' : ($global_accuracy >= 50 ? 'text-amber-600' : 'text-slate-800') ?> mb-1"><?= $global_accuracy ?>%</h3>
                        <p class="text-[11px] text-slate-500 font-bold uppercase tracking-wider">Média de Acertos</p>
                    </div>

                    <div class="bg-white/75 backdrop-blur-md border border-white/80 p-5 rounded-2xl shadow-sm hover:shadow-md transition-all group">
                        <div class="w-10 h-10 rounded-full bg-indigo-500/10 flex items-center justify-center text-indigo-500 mb-3 group-hover:scale-110 transition-transform">
                            <i class="ph-fill ph-users text-xl"></i>
                        </div>
                        <h3 class="text-3xl font-bold text-slate-800 mb-1"><?= $total_users ?></h3>
                        <p class="text-[11px] text-slate-500 font-bold uppercase tracking-wider"><?= ($filter_course_id || $filter_class_id) ? 'Alunos no Escopo' : 'Usuários no Escopo' ?></p>
                    </div>

                    <div class="bg-white/75 backdrop-blur-md border border-white/80 p-5 rounded-2xl shadow-sm hover:shadow-md transition-all group">
                        <div class="w-10 h-10 rounded-full bg-senai-orange/10 flex items-center justify-center text-senai-orange mb-3 group-hover:scale-110 transition-transform">
                            <i class="ph-fill ph-graduation-cap text-xl"></i>
                        </div>
                        <h3 class="text-3xl font-bold text-slate-800 mb-1"><?= $total_classes ?></h3>
                        <p class="text-[11px] text-slate-500 font-bold uppercase tracking-wider">Turmas no Escopo</p>
                    </div>

                    <div class="bg-white/75 backdrop-blur-md border border-white/80 p-5 rounded-2xl shadow-sm hover:shadow-md transition-all group">
                        <div class="w-10 h-10 rounded-full bg-senai-blue/10 flex items-center justify-center text-senai-blue mb-3 group-hover:scale-110 transition-transform">
                            <i class="ph-fill ph-books text-xl"></i>
                        </div>
                        <h3 class="text-3xl font-bold text-slate-800 mb-1"><?= $total_questions ?></h3>
                        <p class="text-[11px] text-slate-500 font-bold uppercase tracking-wider">Questões no Banco</p>
                    </div>

                    <div class="bg-white/75 backdrop-blur-md border border-white/80 p-5 rounded-2xl shadow-sm hover:shadow-md transition-all group col-span-2 md:col-span-1">
                        <div class="w-10 h-10 rounded-full bg-senai-cyan/10 flex items-center justify-center text-senai-cyan mb-3 group-hover:scale-110 transition-transform">
                            <i class="ph-fill ph-target text-xl"></i>
                        </div>
                        <h3 class="text-3xl font-bold text-slate-800 mb-1"><?= $total_sprints ?></h3>
                        <p class="text-[11px] text-slate-500 font-bold uppercase tracking-wider">Diagnósticas Oficiais</p>
                    </div>
                </div>

                <!-- Filtros Hierárquicos Encadeados -->
                <div class="glass-panel p-6 mb-8 flex flex-col gap-4 animate-slide-up delay-200">
                    <form method="GET" id="adminFiltersForm" class="flex flex-col gap-4">
                        
                        <!-- Linha 1: Drill-down Hierárquico (Regional -> Unidade -> Curso -> Turma) -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 bg-slate-50/70 p-4 rounded-2xl border border-slate-100">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">1. Regional (DR)</label>
                                <select name="regional_id" onchange="document.getElementById('adminFiltersForm').submit();" class="w-full px-3.5 py-2.5 bg-white border border-slate-200 rounded-xl text-xs md:text-sm focus:outline-none focus:border-senai-blue transition-all font-semibold text-slate-700 shadow-sm">
                                    <option value="">Todas as Regionais (Nacional)</option>
                                    <?php foreach($regionals as $r): ?>
                                        <option value="<?= $r['id'] ?>" <?= $filter_regional_id == $r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">2. Unidade Escolar</label>
                                <select name="unit_id" onchange="document.getElementById('adminFiltersForm').submit();" class="w-full px-3.5 py-2.5 bg-white border border-slate-200 rounded-xl text-xs md:text-sm focus:outline-none focus:border-senai-blue transition-all font-semibold text-slate-700 shadow-sm">
                                    <option value="">Todas as Unidades<?= $filter_regional_id ? ' da Regional' : '' ?></option>
                                    <?php foreach($units_for_select as $u): ?>
                                        <option value="<?= $u['id'] ?>" <?= $filter_unit_id == $u['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($u['name']) ?><?= (!$filter_regional_id && !empty($u['regional_name'])) ? ' (' . htmlspecialchars($u['regional_name']) . ')' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">3. Curso Técnico</label>
                                <select name="course_id" onchange="document.getElementById('adminFiltersForm').submit();" class="w-full px-3.5 py-2.5 bg-white border border-slate-200 rounded-xl text-xs md:text-sm focus:outline-none focus:border-senai-blue transition-all font-semibold text-slate-700 shadow-sm">
                                    <option value="">Todos os Cursos</option>
                                    <?php foreach($courses as $c): ?>
                                        <option value="<?= $c['id'] ?>" <?= $filter_course_id == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">4. Turma</label>
                                <select name="class_id" onchange="document.getElementById('adminFiltersForm').submit();" class="w-full px-3.5 py-2.5 bg-white border border-slate-200 rounded-xl text-xs md:text-sm focus:outline-none focus:border-senai-blue transition-all font-semibold text-slate-700 shadow-sm">
                                    <option value="">Todas as Turmas (<?= count($classes_for_select) ?>)</option>
                                    <?php foreach($classes_for_select as $cl): ?>
                                        <option value="<?= $cl['id'] ?>" <?= $filter_class_id == $cl['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cl['name']) ?><?= (!$filter_course_id && !empty($cl['course_name'])) ? ' — ' . htmlspecialchars($cl['course_name']) : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Linha 2: Busca Específica -->
                        <div class="flex flex-wrap md:flex-nowrap gap-3">
                            <div class="relative flex-1 min-w-[200px]">
                                <i class="ph ph-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Buscar por Prova, Turma ou Curso..." class="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-senai-blue focus:ring-1 focus:ring-senai-blue transition-all">
                            </div>
                            
                            <select name="teacher_id" onchange="document.getElementById('adminFiltersForm').submit();" class="px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-senai-blue transition-all min-w-[160px]">
                                <option value="">Todos os Professores</option>
                                <?php foreach($teachers as $t): ?>
                                    <option value="<?= $t['id'] ?>" <?= $teacher_id == $t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                                <?php endforeach; ?>
                            </select>

                            <select name="status" onchange="document.getElementById('adminFiltersForm').submit();" class="px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-senai-blue transition-all">
                                <option value="">Qualquer Status</option>
                                <option value="scheduled" <?= $status === 'scheduled' ? 'selected' : '' ?>>Agendada</option>
                                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Ativa</option>
                                <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Finalizada</option>
                                <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Rascunho</option>
                            </select>

                            <button type="submit" class="px-6 py-2.5 bg-slate-800 text-white rounded-xl font-bold text-sm hover:bg-slate-700 transition-colors">
                                Filtrar
                            </button>
                            <?php if($search || $teacher_id || $status || $filter_regional_id || $filter_unit_id || $filter_course_id || $filter_class_id): ?>
                                <a href="admin_dashboard.php" class="px-4 py-2.5 bg-red-50 text-red-600 border border-red-200 rounded-xl font-bold text-sm hover:bg-red-100 transition-colors flex items-center gap-1">
                                    <i class="ph-bold ph-x"></i> Limpar
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- GRÁFICOS AGREGADOS (INTELIGÊNCIA GLOBAL ADAPTADA À NOVA ESTRUTURA) -->
                <?php if ($total_answers_scope > 0): ?>
                    <div class="mb-10 animate-slide-up delay-300">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-4">
                            <div class="flex items-center gap-2">
                                <i class="ph-bold ph-chart-polar text-senai-blue text-2xl"></i>
                                <h2 class="text-2xl font-bold text-slate-800">Inteligência Pedagógica &amp; Performance Analítica</h2>
                            </div>
                            <span class="text-xs font-semibold text-slate-500">
                                Baseado em <strong><?= number_format($total_answers_scope, 0, ',', '.') ?></strong> respostas de <strong><?= $evaluated_students_scope ?></strong> aluno(s) avaliado(s)
                            </span>
                        </div>
                        
                        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
                            
                            <!-- GRÁFICO 1: EVOLUÇÃO HISTÓRICA (GERAL / POR CURSO / POR TURMA / POR UNIDADE) -->
                            <div class="bg-white/85 backdrop-blur-md rounded-3xl p-6 border border-white shadow-sm lg:col-span-7 flex flex-col justify-between">
                                <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                                    <div>
                                        <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider flex items-center gap-1.5">
                                            <i class="ph-bold ph-trend-up text-senai-blue"></i> Evolução de Desempenho (%)
                                        </h3>
                                        <p class="text-[11px] text-slate-400 font-medium">Acompanhe a progressão das diagnósticas por avaliação, curso, turma ou unidade</p>
                                    </div>
                                    <div class="inline-flex bg-slate-100 p-1 rounded-xl gap-1">
                                        <button type="button" onclick="switchEvolMode('general')" id="btn-evol-general" class="px-2.5 py-1 rounded-lg text-[11px] font-bold bg-white text-senai-blue shadow-sm transition-all">Por Avaliação</button>
                                        <button type="button" onclick="switchEvolMode('course')" id="btn-evol-course" class="px-2.5 py-1 rounded-lg text-[11px] font-bold text-slate-600 hover:text-slate-800 transition-all">Por Curso</button>
                                        <button type="button" onclick="switchEvolMode('class')" id="btn-evol-class" class="px-2.5 py-1 rounded-lg text-[11px] font-bold text-slate-600 hover:text-slate-800 transition-all">Por Turma</button>
                                        <button type="button" onclick="switchEvolMode('unit')" id="btn-evol-unit" class="px-2.5 py-1 rounded-lg text-[11px] font-bold text-slate-600 hover:text-slate-800 transition-all">Por Unidade</button>
                                    </div>
                                </div>
                                <div class="relative h-[280px] w-full">
                                    <canvas id="chartEvol"></canvas>
                                </div>
                            </div>

                            <!-- GRÁFICO 3: SAÚDE / DISTRIBUIÇÃO DE PROFICIÊNCIA DOS ALUNOS -->
                            <div class="bg-white/85 backdrop-blur-md rounded-3xl p-6 border border-white shadow-sm lg:col-span-5 flex flex-col justify-between">
                                <div>
                                    <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider flex items-center gap-1.5">
                                        <i class="ph-bold ph-heartbeat text-senai-orange"></i> Saúde Pedagógica dos Alunos
                                    </h3>
                                    <p class="text-[11px] text-slate-400 font-medium mb-4">Distribuição por faixa de aproveitamento no escopo atual</p>
                                </div>
                                <div class="relative h-[200px] w-full flex items-center justify-center">
                                    <canvas id="chartHealth"></canvas>
                                </div>
                                <div class="grid grid-cols-3 gap-2 mt-4 pt-3 border-t border-slate-100 text-center">
                                    <div class="bg-emerald-50/80 border border-emerald-100 rounded-xl p-2">
                                        <span class="block text-lg font-black text-emerald-600"><?= $health_high ?></span>
                                        <span class="text-[10px] font-bold text-emerald-800 uppercase">Consolidado (&ge;70%)</span>
                                    </div>
                                    <div class="bg-amber-50/80 border border-amber-100 rounded-xl p-2">
                                        <span class="block text-lg font-black text-amber-600"><?= $health_med ?></span>
                                        <span class="text-[10px] font-bold text-amber-800 uppercase">Atenção (50-69%)</span>
                                    </div>
                                    <div class="bg-red-50/80 border border-red-100 rounded-xl p-2">
                                        <span class="block text-lg font-black text-red-600"><?= $health_low ?></span>
                                        <span class="text-[10px] font-bold text-red-800 uppercase">Crítico (&lt;50%)</span>
                                    </div>
                                </div>
                            </div>

                            <!-- GRÁFICO 2: DESEMPENHO COMPARATIVO (POR CURSO / TURMA / UNIDADE / REGIONAL) -->
                            <div class="bg-white/85 backdrop-blur-md rounded-3xl p-6 border border-white shadow-sm lg:col-span-12">
                                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                                    <div>
                                        <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider flex items-center gap-1.5">
                                            <i class="ph-bold ph-chart-bar-horizontal text-senai-orange"></i> Comparativo de Desempenho por Dimensão (%)
                                        </h3>
                                        <p class="text-[11px] text-slate-400 font-medium">Alterne entre Curso, Turma, Unidade Escolar ou Regional para comparar o aproveitamento médio</p>
                                    </div>
                                    <div class="inline-flex bg-slate-100 p-1 rounded-xl gap-1 flex-wrap">
                                        <button type="button" onclick="switchDimChart('course')" id="btn-dim-course" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all">Por Curso (<?= count($perfByCourse) ?>)</button>
                                        <button type="button" onclick="switchDimChart('class')" id="btn-dim-class" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all">Por Turma (<?= count($perfByClass) ?>)</button>
                                        <button type="button" onclick="switchDimChart('unit')" id="btn-dim-unit" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all">Por Unidade / Escola (<?= count($perfByUnit) ?>)</button>
                                        <button type="button" onclick="switchDimChart('regional')" id="btn-dim-regional" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all">Por Regional (<?= count($perfByRegional) ?>)</button>
                                    </div>
                                </div>
                                <div id="dim-chart-wrapper" class="relative w-full" style="height: 280px;">
                                    <canvas id="chartDim"></canvas>
                                </div>
                            </div>

                            <!-- GRÁFICO 4: MAPEAMENTO DE CAPACIDADES DA MATRIZ SAEP (POR CURSO + CAPACIDADE) -->
                            <div class="bg-white/85 backdrop-blur-md rounded-3xl p-6 border border-white shadow-sm lg:col-span-12">
                                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                                    <div>
                                        <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider flex items-center gap-1.5">
                                            <i class="ph-bold ph-crosshair text-senai-cyan"></i> Desempenho por Capacidade Oficial da Matriz SAEP (%)
                                        </h3>
                                        <p class="text-[11px] text-slate-400 font-medium">Capacidades separadas por Curso Técnico com código oficial (C1, C2...) e descrição da Matriz</p>
                                    </div>
                                    <div class="inline-flex bg-slate-100 p-1 rounded-xl gap-1">
                                        <button type="button" onclick="switchCapFilter('worst')" id="btn-cap-worst" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-white text-senai-blue shadow-sm transition-all">Top 15 Mais Críticas</button>
                                        <button type="button" onclick="switchCapFilter('best')" id="btn-cap-best" class="px-3 py-1.5 rounded-lg text-xs font-bold text-slate-600 hover:text-slate-800 transition-all">Top 15 Mais Consolidadas</button>
                                        <button type="button" onclick="switchCapFilter('all')" id="btn-cap-all" class="px-3 py-1.5 rounded-lg text-xs font-bold text-slate-600 hover:text-slate-800 transition-all">Ver Todas (<?= count($capacitiesAll) ?>)</button>
                                    </div>
                                </div>
                                <div id="cap-chart-wrapper" class="relative w-full" style="height: 380px;">
                                    <canvas id="chartCap"></canvas>
                                </div>
                            </div>

                        </div>
                    </div>
                <?php else: ?>
                    <div class="mb-10 bg-white/80 backdrop-blur-md border border-white rounded-3xl p-8 text-center shadow-sm">
                        <i class="ph-duotone ph-chart-bar text-4xl text-slate-300 mb-2"></i>
                        <h3 class="text-base font-bold text-slate-700">Ainda não há respostas computadas para o filtro selecionado</h3>
                        <p class="text-xs text-slate-500 mt-1">Assim que os alunos realizarem as avaliações diagnósticas neste escopo, os gráficos comparativos e de evolução aparecerão aqui.</p>
                    </div>
                <?php endif; ?>
                
                <!-- Banco Global de Sprints -->
                <div class="animate-slide-up delay-200">
                    <div class="bg-white/70 backdrop-blur-md border border-white/80 rounded-3xl p-6 shadow-sm overflow-hidden">
                        
                        <h2 class="text-xl font-bold text-slate-800 mb-6 flex items-center gap-2">
                            <i class="ph-fill ph-list-dashes text-senai-blue"></i> Avaliações Diagnósticas (<?= $total_sprints ?>)
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
                                            <th class="pb-3 font-semibold">Turma / Curso / Unidade</th>
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
                                                <div class="text-xs text-slate-400 font-medium">
                                                    <?= !empty($s['start_time']) ? 'Agendada: ' . date('d/m/Y H:i', strtotime($s['start_time'])) : 'Criada em: ' . date('d/m/Y', strtotime($s['created_at'])) ?>
                                                </div>
                                            </td>
                                            <td class="py-4">
                                                <div class="font-bold text-slate-700"><?= htmlspecialchars($s['class_name']) ?></div>
                                                <div class="text-xs text-slate-400 font-medium">
                                                    <?= htmlspecialchars($s['course_name'] ?? 'Sem Curso') ?>
                                                    <?= !empty($s['unit_name']) ? ' • ' . htmlspecialchars($s['unit_name']) : '' ?>
                                                </div>
                                            </td>
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
                                                    <span class="bg-green-100 text-green-700 px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase shadow-sm">Ativa</span>
                                                <?php elseif($s['status'] === 'scheduled'): ?>
                                                    <span class="bg-amber-100 text-amber-800 px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase shadow-sm">Agendada</span>
                                                <?php elseif($s['status'] === 'completed'): ?>
                                                    <span class="bg-indigo-100 text-indigo-700 px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase shadow-sm">Finalizada</span>
                                                <?php else: ?>
                                                    <span class="bg-slate-100 text-slate-600 px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase shadow-sm">Rascunho</span>
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

<?php if ($total_answers_scope > 0): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color = '#64748b';

    // =========================================================
    // 1. GRÁFICO DE EVOLUÇÃO (Por Avaliação / Curso / Turma / Unidade)
    // =========================================================
    const evolGeneral = {
        labels: <?= json_encode($evolGeneralLabels, JSON_UNESCAPED_UNICODE) ?>,
        values: <?= json_encode($evolGeneralValues) ?>
    };
    const evolMulti = {
        course: <?= json_encode($evolByCourseData, JSON_UNESCAPED_UNICODE) ?>,
        class:  <?= json_encode($evolByClassData, JSON_UNESCAPED_UNICODE) ?>,
        unit:   <?= json_encode($evolByUnitData, JSON_UNESCAPED_UNICODE) ?>
    };

    let chartEvolInstance = null;

    window.switchEvolMode = function(mode) {
        ['general', 'course', 'class', 'unit'].forEach(m => {
            const btn = document.getElementById('btn-evol-' + m);
            if (!btn) return;
            if (m === mode) {
                btn.className = 'px-2.5 py-1 rounded-lg text-[11px] font-bold bg-white text-senai-blue shadow-sm transition-all';
            } else {
                btn.className = 'px-2.5 py-1 rounded-lg text-[11px] font-bold text-slate-600 hover:text-slate-800 transition-all';
            }
        });

        if (chartEvolInstance) {
            chartEvolInstance.destroy();
        }

        const ctx = document.getElementById('chartEvol').getContext('2d');
        if (mode === 'general') {
            chartEvolInstance = new Chart(ctx, {
                type: evolGeneral.labels.length === 1 ? 'bar' : 'line',
                data: {
                    labels: evolGeneral.labels,
                    datasets: [{
                        label: 'Média de Acertos (%)',
                        data: evolGeneral.values,
                        borderColor: '#1A428A',
                        backgroundColor: evolGeneral.labels.length === 1 ? '#1A428A' : 'rgba(26, 66, 138, 0.12)',
                        borderWidth: 3,
                        pointBackgroundColor: '#1A428A',
                        pointRadius: 5,
                        fill: true,
                        tension: 0.35,
                        borderRadius: 8,
                        maxBarThickness: 64
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => ` Aproveitamento: ${ctx.parsed.y}%`
                            }
                        }
                    },
                    scales: {
                        y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } }
                    }
                }
            });
        } else {
            const series = evolMulti[mode] || { labels: [], datasets: [] };
            chartEvolInstance = new Chart(ctx, {
                type: (series.labels.length === 1) ? 'bar' : 'line',
                data: {
                    labels: series.labels,
                    datasets: series.datasets.map(ds => ({
                        ...ds,
                        borderRadius: 6,
                        maxBarThickness: 48
                    }))
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11, weight: 'bold' } } },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => {
                                    const extra = ctx.dataset.pointTitles && ctx.dataset.pointTitles[ctx.dataIndex]
                                        ? ` (${ctx.dataset.pointTitles[ctx.dataIndex]})`
                                        : '';
                                    return ` ${ctx.dataset.label}: ${ctx.parsed.y}%${extra}`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } }
                    }
                }
            });
        }
    };

    switchEvolMode('general');

    // =========================================================
    // 2. GRÁFICO DE SAÚDE DOS ALUNOS (Rosca Semafórica)
    // =========================================================
    new Chart(document.getElementById('chartHealth').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: ['Consolidado (>= 70%)', 'Em Atenção (50% a 69%)', 'Em Risco (< 50%)'],
            datasets: [{
                data: [<?= (int)$health_high ?>, <?= (int)$health_med ?>, <?= (int)$health_low ?>],
                backgroundColor: ['#10B981', '#F59E0B', '#EF4444'],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '66%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => ` ${ctx.label}: ${ctx.parsed} aluno(s)`
                    }
                }
            }
        }
    });

    // =========================================================
    // 3. GRÁFICO COMPARATIVO POR DIMENSÃO (Curso / Turma / Unidade / Regional)
    // =========================================================
    const dimDatasets = <?= json_encode($dimComparisons, JSON_UNESCAPED_UNICODE) ?>;
    let chartDimInstance = null;

    window.switchDimChart = function(dimKey) {
        ['course', 'class', 'unit', 'regional'].forEach(k => {
            const btn = document.getElementById('btn-dim-' + k);
            if (!btn) return;
            if (k === dimKey) {
                btn.className = 'px-3 py-1.5 rounded-lg text-xs font-bold bg-white text-senai-blue shadow-sm transition-all';
            } else {
                btn.className = 'px-3 py-1.5 rounded-lg text-xs font-bold text-slate-600 hover:text-slate-800 transition-all';
            }
        });

        const d = dimDatasets[dimKey] || { labels: [], rates: [], meta: [] };
        const wrapper = document.getElementById('dim-chart-wrapper');
        const dynamicHeight = Math.max(240, d.labels.length * 42 + 60);
        wrapper.style.height = dynamicHeight + 'px';

        if (chartDimInstance) {
            chartDimInstance.destroy();
        }

        const colors = d.rates.map(r => r >= 70 ? '#10B981' : (r >= 50 ? '#F59E0B' : '#EF4444'));

        chartDimInstance = new Chart(document.getElementById('chartDim').getContext('2d'), {
            type: 'bar',
            data: {
                labels: d.labels,
                datasets: [{
                    label: 'Taxa Média de Acerto (%)',
                    data: d.rates,
                    backgroundColor: colors,
                    borderRadius: 6,
                    maxBarThickness: 28
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const m = d.meta[ctx.dataIndex] || {};
                                return ` Aproveitamento: ${ctx.parsed.x}% (${m.students || 0} alunos em ${m.sprints || 0} diagnóstica(s))`;
                            }
                        }
                    }
                },
                scales: {
                    x: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } },
                    y: { ticks: { font: { size: 11, weight: 'bold' } } }
                }
            }
        });
    };

    switchDimChart('<?= $defaultDimTab ?>');

    // =========================================================
    // 4. GRÁFICO DE CAPACIDADES OFICIAIS DA MATRIZ SAEP
    // =========================================================
    const allCapacities = <?= json_encode($capacitiesAll, JSON_UNESCAPED_UNICODE) ?>;
    let chartCapInstance = null;

    window.switchCapFilter = function(mode) {
        ['worst', 'best', 'all'].forEach(m => {
            const btn = document.getElementById('btn-cap-' + m);
            if (!btn) return;
            if (m === mode) {
                btn.className = 'px-3 py-1.5 rounded-lg text-xs font-bold bg-white text-senai-blue shadow-sm transition-all';
            } else {
                btn.className = 'px-3 py-1.5 rounded-lg text-xs font-bold text-slate-600 hover:text-slate-800 transition-all';
            }
        });

        let list = [...allCapacities];
        if (mode === 'worst') {
            list.sort((a, b) => a.rate - b.rate);
            list = list.slice(0, 15);
        } else if (mode === 'best') {
            list.sort((a, b) => b.rate - a.rate);
            list = list.slice(0, 15);
        } else {
            list.sort((a, b) => a.rate - b.rate);
        }

        const wrapper = document.getElementById('cap-chart-wrapper');
        const dynamicHeight = Math.max(280, list.length * 36 + 60);
        wrapper.style.height = dynamicHeight + 'px';

        if (chartCapInstance) {
            chartCapInstance.destroy();
        }

        const barColors = list.map(item => item.rate >= 70 ? '#10B981' : (item.rate >= 50 ? '#F59E0B' : '#EF4444'));

        chartCapInstance = new Chart(document.getElementById('chartCap').getContext('2d'), {
            type: 'bar',
            data: {
                labels: list.map(i => i.short_label),
                datasets: [{
                    label: 'Taxa de Acerto (%)',
                    data: list.map(i => i.rate),
                    backgroundColor: barColors,
                    borderRadius: 6,
                    maxBarThickness: 24
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: (items) => {
                                const idx = items[0]?.dataIndex;
                                return (idx !== undefined && list[idx]) ? list[idx].full_label : '';
                            },
                            label: (ctx) => {
                                const item = list[ctx.dataIndex];
                                return ` Aproveitamento: ${ctx.parsed.x}% (${item.correct}/${item.total} acertos)`;
                            }
                        }
                    }
                },
                scales: {
                    x: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } },
                    y: { ticks: { font: { size: 11, weight: 'bold' } } }
                }
            }
        });
    };

    switchCapFilter('worst');
});
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
