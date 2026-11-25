<?php
session_start();

$attempt_id = isset($_GET['attempt_id']) ? (int)$_GET['attempt_id'] : 0;
$sprint_id  = isset($_GET['sprint_id']) ? (int)$_GET['sprint_id'] : 0;

if (!$attempt_id && !$sprint_id) {
    die("Relatório não encontrado.");
}

// Se não estiver logado, redireciona para login
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php?error=" . urlencode("Por favor, faça login para ver seu relatório."));
    exit;
}

require_once 'config.php';
$pdo = getPDOConnection();

// Garante tabela course_capacities para leitura segura da descrição oficial
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

$is_batch_mode = ($sprint_id > 0 && !$attempt_id);
$attempts_raw = [];
$batch_sprint_info = null;

if ($is_batch_mode) {
    // Modo Unificado (Todos os alunos da turma na Sprint) — restrito a professor e admin
    if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'teacher'])) {
        die("Acesso negado.");
    }

    if ($_SESSION['user_role'] === 'admin') {
        $stmtSp = $pdo->prepare("SELECT s.*, c.name as class_name FROM sprints s LEFT JOIN classes c ON s.class_id = c.id WHERE s.id = ?");
        $stmtSp->execute([$sprint_id]);
    } else {
        $stmtSp = $pdo->prepare("SELECT s.*, c.name as class_name FROM sprints s LEFT JOIN classes c ON s.class_id = c.id WHERE s.id = ? AND s.teacher_id = ?");
        $stmtSp->execute([$sprint_id, $_SESSION['user_id']]);
    }
    $batch_sprint_info = $stmtSp->fetch();
    if (!$batch_sprint_info) {
        die("Avaliação não encontrada ou sem permissão.");
    }

    // Busca todas as tentativas dos alunos nessa sprint ordenadas por nome do aluno
    $stmtAll = $pdo->prepare("
        SELECT a.*, s.name as sprint_name, s.class_id, c.name as class_name, u.name as student_name
        FROM sprint_attempts a
        JOIN sprints s ON a.sprint_id = s.id
        LEFT JOIN classes c ON s.class_id = c.id
        JOIN users u ON a.student_id = u.id
        WHERE a.sprint_id = ?
        ORDER BY u.name ASC, a.id DESC
    ");
    $stmtAll->execute([$sprint_id]);
    $rows = $stmtAll->fetchAll();

    // Evita duplicidade caso algum aluno possua mais de um registro em sprint_attempts
    $seen_students = [];
    foreach ($rows as $row) {
        $sid = (int)$row['student_id'];
        if (isset($seen_students[$sid])) continue;
        $seen_students[$sid] = true;
        $attempts_raw[] = $row;
    }
} else {
    // Modo Individual (1 aluno via attempt_id)
    $stmt = $pdo->prepare("
        SELECT a.*, s.name as sprint_name, s.class_id, c.name as class_name, u.name as student_name
        FROM sprint_attempts a
        JOIN sprints s ON a.sprint_id = s.id
        LEFT JOIN classes c ON s.class_id = c.id
        JOIN users u ON a.student_id = u.id
        WHERE a.id = ?
    ");
    $stmt->execute([$attempt_id]);
    $single = $stmt->fetch();

    if (!$single) {
        die("Relatório não encontrado.");
    }

    if ($_SESSION['user_role'] === 'student' && $single['student_id'] != $_SESSION['user_id']) {
        die("Acesso negado. Você só pode visualizar os seus próprios relatórios.");
    }

    $attempts_raw[] = $single;
}

// Prepara o statement de respostas de cada aluno
$stmtAnswers = $pdo->prepare("
    SELECT 
        m.name as module_name,
        q.capacity as capacity_code,
        COALESCE(cc.description, mc.description) as capacity_desc,
        sa.is_correct
    FROM student_answers sa
    JOIN questions q ON sa.question_id = q.id
    JOIN modules m ON q.module_id = m.id
    LEFT JOIN course_capacities cc ON cc.course_id = m.course_id AND cc.capacity_code = q.capacity
    LEFT JOIN module_capacities mc ON mc.module_id = q.module_id AND mc.capacity_code = q.capacity
    WHERE sa.student_id = ? AND sa.sprint_id = ?
");

$student_reports = [];
foreach ($attempts_raw as $att) {
    $stmtAnswers->execute([$att['student_id'], $att['sprint_id']]);
    $answers = $stmtAnswers->fetchAll();

    if (empty($answers)) {
        continue;
    }

    $total = count($answers);
    $correct = 0;
    $module_stats = [];
    $capacity_stats = [];

    foreach ($answers as $a) {
        if ($a['is_correct']) $correct++;

        // Módulo
        $mod = $a['module_name'];
        if (!isset($module_stats[$mod])) $module_stats[$mod] = ['c' => 0, 't' => 0];
        $module_stats[$mod]['t']++;
        if ($a['is_correct']) $module_stats[$mod]['c']++;

        // Capacidade
        $cap = $a['capacity_code'] ?: 'Geral';
        $capDesc = trim((string)($a['capacity_desc'] ?? ''));
        if (!isset($capacity_stats[$cap])) {
            $capacity_stats[$cap] = ['c' => 0, 't' => 0, 'mod' => $mod, 'desc' => $capDesc];
        } elseif ($capacity_stats[$cap]['desc'] === '' && $capDesc !== '') {
            $capacity_stats[$cap]['desc'] = $capDesc;
        }
        $capacity_stats[$cap]['t']++;
        if ($a['is_correct']) $capacity_stats[$cap]['c']++;
    }

    // Ordenar para mostrar as piores capacidades primeiro
    uasort($capacity_stats, function ($a, $b) {
        return ($a['c'] / max(1, $a['t'])) <=> ($b['c'] / max(1, $b['t']));
    });

    $score = round(($correct / max(1, $total)) * 100, 1);
    if (!empty($att['completed_at'])) {
        $date_str = 'Concluído em ' . date('d/m/Y \à\s H:i', strtotime($att['completed_at']));
    } elseif (!empty($att['started_at'])) {
        $date_str = 'Iniciado em ' . date('d/m/Y \à\s H:i', strtotime($att['started_at'])) . ' (Em andamento)';
    } else {
        $date_str = 'Realizado em ' . date('d/m/Y');
    }

    $student_reports[] = [
        'attempt'        => $att,
        'total'          => $total,
        'correct'        => $correct,
        'score'          => $score,
        'date_str'       => $date_str,
        'module_stats'   => $module_stats,
        'capacity_stats' => $capacity_stats
    ];
}

if (empty($student_reports)) {
    die("Não há dados de respostas suficientes para gerar o relatório.");
}

$page_title = $is_batch_mode
    ? ('Relatório Unificado (' . count($student_reports) . ' alunos) - ' . ($batch_sprint_info['name'] ?? 'Sprint') . (!empty($batch_sprint_info['class_name']) ? ' - ' . $batch_sprint_info['class_name'] : ''))
    : ('Relatório Individual - ' . $student_reports[0]['attempt']['student_name']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <script src="https://cdn.tailwindcss.com?plugins=typography"></script>
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    
    <style>
        /* Estilos A4 para Impressão */
        @page {
            size: A4;
            margin: 0;
        }
        body {
            background-color: #cbd5e1;
            font-family: 'Inter', sans-serif;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        .a4-page {
            width: 210mm;
            min-height: 297mm;
            background: white;
            margin: 14mm auto;
            padding: 20mm;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }
        .avoid-break {
            break-inside: avoid;
            page-break-inside: avoid;
        }
        
        @media print {
            body { background: white; margin: 0; }
            .a4-page {
                margin: 0;
                padding: 15mm;
                box-shadow: none;
                width: 100%;
                min-height: auto;
                overflow: visible !important;
                page-break-after: always;
                break-after: page;
            }
            .a4-page:last-of-type {
                page-break-after: auto;
                break-after: auto;
            }
            .no-print { display: none !important; }
        }

        /* Branding iaS */
        .iaS-bg {
            background: linear-gradient(135deg, #1A428A 0%, #0d2247 100%);
            color: white;
        }
        .iaS-text-orange { color: #F25C27; }
        .iaS-bg-orange { background-color: #F25C27; }
    </style>
</head>
<body>

    <?php if ($is_batch_mode): ?>
    <!-- Barra de Controle Superior no Modo Unificado (Apenas na Tela) -->
    <div class="no-print sticky top-0 z-50 bg-slate-900/95 backdrop-blur-md text-white px-6 py-3.5 shadow-lg flex flex-wrap items-center justify-between gap-4 border-b border-slate-700">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-[#F25C27] flex items-center justify-center text-white font-black shadow">
                <i class="ph-bold ph-files text-xl"></i>
            </div>
            <div>
                <h2 class="text-sm md:text-base font-extrabold leading-tight">
                    Relatório Unificado da Turma • <?= count($student_reports) ?> Aluno(s)
                </h2>
                <p class="text-xs text-slate-300">
                    <?= htmlspecialchars($batch_sprint_info['name'] ?? '') ?>
                    <?= !empty($batch_sprint_info['class_name']) ? ' — Turma: ' . htmlspecialchars($batch_sprint_info['class_name']) : '' ?>
                </p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <button onclick="window.print()" class="bg-[#F25C27] hover:bg-orange-600 text-white px-5 py-2.5 rounded-xl font-bold text-sm flex items-center gap-2 shadow-md transition-all">
                <i class="ph-bold ph-printer text-lg"></i> Salvar Tudo em um Único PDF / Imprimir
            </button>
            <button onclick="window.close()" class="bg-slate-800 hover:bg-slate-700 text-slate-200 px-4 py-2.5 rounded-xl font-bold text-sm flex items-center gap-1.5 border border-slate-700 transition-all">
                <i class="ph-bold ph-x"></i> Fechar
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Botões Flutuantes (Apenas na Tela) -->
    <div class="no-print fixed bottom-8 right-8 flex flex-col gap-3 z-50">
        <button onclick="window.print()" class="bg-[#F25C27] hover:bg-orange-600 text-white rounded-full w-14 h-14 flex items-center justify-center shadow-[0_8px_20px_-6px_rgba(242,92,39,0.5)] hover:-translate-y-1 transition-all" title="Salvar como PDF">
            <i class="ph-bold ph-printer text-2xl"></i>
        </button>
        <?php if ($_SESSION['user_role'] === 'student'): ?>
            <a href="student_dashboard.php" class="bg-white hover:bg-slate-50 text-slate-700 rounded-full w-14 h-14 flex items-center justify-center shadow-lg hover:-translate-y-1 transition-all border border-slate-200" title="Voltar ao Início">
                <i class="ph-bold ph-house text-2xl"></i>
            </a>
        <?php else: ?>
            <button onclick="window.close()" class="bg-white hover:bg-slate-50 text-slate-700 rounded-full w-14 h-14 flex items-center justify-center shadow-lg hover:-translate-y-1 transition-all border border-slate-200" title="Fechar Relatório">
                <i class="ph-bold ph-x text-2xl"></i>
            </button>
        <?php endif; ?>
    </div>

    <?php foreach ($student_reports as $idx => $rep):
        $attempt        = $rep['attempt'];
        $total          = $rep['total'];
        $correct        = $rep['correct'];
        $score          = $rep['score'];
        $date_str       = $rep['date_str'];
        $module_stats   = $rep['module_stats'];
        $capacity_stats = $rep['capacity_stats'];
    ?>
    <!-- PÁGINA A4 DO ALUNO #<?= $idx + 1 ?> -->
    <div class="a4-page">
        <!-- Detalhes de Fundo (Apenas Visual) -->
        <div class="absolute top-[-5%] right-[-5%] w-64 h-64 bg-slate-100 rounded-full opacity-50 z-0"></div>
        <div class="absolute bottom-[-5%] left-[-5%] w-96 h-96 bg-blue-50 rounded-full opacity-50 z-0"></div>

        <div class="relative z-10">
            <!-- Cabeçalho -->
            <div class="flex items-center justify-between border-b-4 border-[#1A428A] pb-6 mb-8">
                <div>
                    <h1 class="text-4xl font-black text-[#1A428A] tracking-tight">iaS</h1>
                    <p class="text-sm font-bold tracking-widest text-[#F25C27] uppercase">Evolução em cada desafio</p>
                </div>
                <div class="text-right">
                    <h2 class="text-xl font-bold text-slate-800">Devolutiva Individual</h2>
                    <p class="text-slate-500 text-sm mt-1"><?= htmlspecialchars($date_str) ?></p>
                    <?php if ($is_batch_mode): ?>
                        <p class="text-[11px] font-bold text-slate-400 mt-0.5">Aluno <?= $idx + 1 ?> de <?= count($student_reports) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Identificação do Aluno -->
            <div class="iaS-bg rounded-2xl p-6 mb-8 shadow-md avoid-break">
                <div class="flex items-center gap-6">
                    <div class="w-20 h-20 rounded-full bg-white/10 flex items-center justify-center border-2 border-white/20 shrink-0">
                        <i class="ph-fill ph-student text-4xl text-white"></i>
                    </div>
                    <div>
                        <p class="text-blue-200 text-sm font-bold uppercase tracking-widest mb-1">
                            Aluno(a)<?= !empty($attempt['class_name']) ? ' • Turma: ' . htmlspecialchars($attempt['class_name']) : '' ?>
                        </p>
                        <h3 class="text-2xl font-bold text-white"><?= htmlspecialchars($attempt['student_name']) ?></h3>
                        <p class="text-blue-100 mt-1 flex items-center gap-2">
                            <i class="ph-bold ph-target"></i> Sprint: <?= htmlspecialchars($attempt['sprint_name']) ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Resumo Global -->
            <div class="grid grid-cols-2 gap-6 mb-10 avoid-break">
                <div class="bg-slate-50 border border-slate-200 rounded-2xl p-6 flex items-center justify-between shadow-sm">
                    <div>
                        <p class="text-slate-500 text-sm font-bold uppercase tracking-wider mb-1">Acertos</p>
                        <p class="text-3xl font-black text-slate-800"><?= $correct ?> <span class="text-lg font-medium text-slate-400">/ <?= $total ?></span></p>
                    </div>
                    <div class="w-14 h-14 rounded-full bg-green-100 text-green-600 flex items-center justify-center">
                        <i class="ph-bold ph-check-circle text-2xl"></i>
                    </div>
                </div>
                <div class="bg-slate-50 border border-slate-200 rounded-2xl p-6 flex items-center justify-between shadow-sm">
                    <div>
                        <p class="text-slate-500 text-sm font-bold uppercase tracking-wider mb-1">Aproveitamento</p>
                        <p class="text-3xl font-black <?= $score >= 70 ? 'text-green-600' : ($score >= 40 ? 'text-orange-500' : 'text-red-600') ?>"><?= $score ?>%</p>
                    </div>
                    <div class="w-14 h-14 rounded-full <?= $score >= 70 ? 'bg-green-100 text-green-600' : 'bg-orange-100 text-orange-600' ?> flex items-center justify-center">
                        <i class="ph-bold ph-chart-line-up text-2xl"></i>
                    </div>
                </div>
            </div>

            <!-- Desempenho por Módulo -->
            <h3 class="text-lg font-bold text-slate-800 mb-4 flex items-center gap-2 border-b border-slate-200 pb-2">
                <i class="ph-bold ph-books text-[#1A428A]"></i> Desempenho por Domínio (Módulo)
            </h3>
            
            <div class="space-y-4 mb-10">
                <?php foreach ($module_stats as $mName => $st): 
                    $pct = round(($st['c'] / max(1, $st['t'])) * 100);
                    $colorClass = $pct >= 70 ? 'bg-green-500' : ($pct >= 40 ? 'bg-orange-500' : 'bg-red-500');
                ?>
                    <div class="bg-white border border-slate-100 p-4 rounded-xl shadow-sm avoid-break">
                        <div class="flex justify-between items-center mb-2">
                            <h4 class="font-bold text-slate-700 truncate mr-4"><?= htmlspecialchars($mName) ?></h4>
                            <span class="font-black text-slate-800"><?= $pct ?>%</span>
                        </div>
                        <div class="w-full bg-slate-100 rounded-full h-3 overflow-hidden">
                            <div class="<?= $colorClass ?> h-3 rounded-full" style="width: <?= $pct ?>%"></div>
                        </div>
                        <p class="text-xs text-slate-400 font-medium mt-2 text-right"><?= $st['c'] ?> de <?= $st['t'] ?> acertos</p>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Desempenho por Capacidade -->
            <h3 class="text-lg font-bold text-slate-800 mb-4 flex items-center gap-2 border-b border-slate-200 pb-2">
                <i class="ph-bold ph-crosshair text-[#1A428A]"></i> Pontos de Atenção (Capacidades)
            </h3>
            
            <div class="grid grid-cols-1 gap-3.5">
                <?php foreach ($capacity_stats as $cName => $cSt): 
                    $cpct = round(($cSt['c'] / max(1, $cSt['t'])) * 100);
                    $icon = $cpct >= 70 ? 'ph-check-circle text-green-500' : ($cpct >= 40 ? 'ph-warning-circle text-orange-500' : 'ph-x-circle text-red-500');
                    $bg = $cpct >= 70 ? 'bg-green-50' : ($cpct >= 40 ? 'bg-orange-50' : 'bg-red-50');
                ?>
                    <div class="<?= $bg ?> border border-white p-4 rounded-xl flex items-start justify-between gap-4 avoid-break">
                        <div class="flex items-start gap-3 flex-1">
                            <i class="ph-fill <?= $icon ?> text-xl mt-0.5 shrink-0"></i>
                            <div class="flex-1">
                                <p class="font-bold text-slate-800 text-sm leading-snug mb-1">
                                    <span class="inline-block px-2 py-0.5 rounded bg-slate-800 text-white text-xs font-black mr-1.5"><?= htmlspecialchars($cName) ?></span>
                                    <?= !empty($cSt['desc']) ? htmlspecialchars($cSt['desc']) : '<span class="text-slate-400 italic font-normal">Capacidade avaliada</span>' ?>
                                </p>
                                <p class="text-xs text-slate-500 font-medium"><?= htmlspecialchars($cSt['mod']) ?> • <?= $cSt['c'] ?> de <?= $cSt['t'] ?> acertos</p>
                            </div>
                        </div>
                        <div class="text-xs font-black px-2.5 py-1.5 bg-white rounded-lg text-slate-700 shadow-sm shrink-0 self-center">
                            <?= $cpct ?>% Aproveitamento
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Rodapé da Página -->
            <div class="mt-12 pt-6 border-t border-slate-200 text-center text-slate-400 text-xs font-medium">
                Documento gerado automaticamente pelo Sistema iaS - Assistente Pedagógico SENAI.<br>
                <?= date('d/m/Y H:i:s') ?>
            </div>

        </div>
    </div>
    <?php endforeach; ?>

    <?php if (!empty($_GET['auto_print'])): ?>
    <script>
        window.addEventListener('load', () => {
            setTimeout(() => {
                window.print();
            }, 400);
        });
    </script>
    <?php endif; ?>

</body>
</html>
