<?php
session_start();

$attempt_id = $_GET['attempt_id'] ?? '';
if (!$attempt_id) {
    die("Relatório não encontrado.");
}

// Se não estiver logado, redireciona para login e volta para cá
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php?error=" . urlencode("Por favor, faça login para ver seu relatório."));
    exit;
}

require_once 'config.php';
$pdo = getPDOConnection();

// Busca os dados da tentativa e garante segurança (só o próprio aluno ou admin/prof podem ver)
$stmt = $pdo->prepare("
    SELECT a.*, s.name as sprint_name, s.class_id, u.name as student_name
    FROM sprint_attempts a
    JOIN sprints s ON a.sprint_id = s.id
    JOIN users u ON a.student_id = u.id
    WHERE a.id = ?
");
$stmt->execute([$attempt_id]);
$attempt = $stmt->fetch();

if (!$attempt) {
    die("Relatório não encontrado.");
}

// Verifica permissão (Student só vê o próprio)
if ($_SESSION['user_role'] === 'student' && $attempt['student_id'] != $_SESSION['user_id']) {
    die("Acesso negado. Você só pode visualizar os seus próprios relatórios.");
}

// Busca as respostas
$stmtAnswers = $pdo->prepare("
    SELECT 
        m.name as module_name,
        q.capacity as capacity_code,
        sa.is_correct
    FROM student_answers sa
    JOIN questions q ON sa.question_id = q.id
    JOIN modules m ON q.module_id = m.id
    WHERE sa.student_id = ? AND sa.sprint_id = ?
");
$stmtAnswers->execute([$attempt['student_id'], $attempt['sprint_id']]);
$answers = $stmtAnswers->fetchAll();

if (empty($answers)) {
    die("Não há dados de respostas suficientes para gerar o relatório.");
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
    $cap = $a['capacity_code'];
    if (!isset($capacity_stats[$cap])) $capacity_stats[$cap] = ['c' => 0, 't' => 0, 'mod' => $mod];
    $capacity_stats[$cap]['t']++;
    if ($a['is_correct']) $capacity_stats[$cap]['c']++;
}

$score = round(($correct / $total) * 100, 1);
$date_str = date('d/m/Y \à\s H:i', strtotime($attempt['completed_at']));

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório Individual - <?= htmlspecialchars($attempt['student_name']) ?></title>
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
            margin: 20mm auto;
            padding: 20mm;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }
        
        @media print {
            body { background: white; margin: 0; }
            .a4-page {
                margin: 0; padding: 15mm;
                box-shadow: none; width: 100%; min-height: 100vh;
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

    <!-- PÁGINA A4 -->
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
                    <p class="text-slate-500 text-sm mt-1">Concluído em <?= $date_str ?></p>
                </div>
            </div>

            <!-- Identificação do Aluno -->
            <div class="iaS-bg rounded-2xl p-6 mb-8 shadow-md">
                <div class="flex items-center gap-6">
                    <div class="w-20 h-20 rounded-full bg-white/10 flex items-center justify-center border-2 border-white/20">
                        <i class="ph-fill ph-student text-4xl text-white"></i>
                    </div>
                    <div>
                        <p class="text-blue-200 text-sm font-bold uppercase tracking-widest mb-1">Aluno(a)</p>
                        <h3 class="text-2xl font-bold text-white"><?= htmlspecialchars($attempt['student_name']) ?></h3>
                        <p class="text-blue-100 mt-1 flex items-center gap-2">
                            <i class="ph-bold ph-target"></i> Sprint: <?= htmlspecialchars($attempt['sprint_name']) ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Resumo Global -->
            <div class="grid grid-cols-2 gap-6 mb-10">
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
                    $pct = round(($st['c'] / $st['t']) * 100);
                    $colorClass = $pct >= 70 ? 'bg-green-500' : ($pct >= 40 ? 'bg-orange-500' : 'bg-red-500');
                ?>
                    <div class="bg-white border border-slate-100 p-4 rounded-xl shadow-sm">
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
            
            <div class="grid grid-cols-2 gap-4">
                <?php 
                // Ordenar para mostrar as piores capacidades primeiro
                uasort($capacity_stats, function($a, $b) {
                    return ($a['c']/$a['t']) <=> ($b['c']/$b['t']);
                });
                
                foreach ($capacity_stats as $cName => $cSt): 
                    $cpct = round(($cSt['c'] / $cSt['t']) * 100);
                    $icon = $cpct >= 70 ? 'ph-check-circle text-green-500' : ($cpct >= 40 ? 'ph-warning-circle text-orange-500' : 'ph-x-circle text-red-500');
                    $bg = $cpct >= 70 ? 'bg-green-50' : ($cpct >= 40 ? 'bg-orange-50' : 'bg-red-50');
                ?>
                    <div class="<?= $bg ?> border border-white p-4 rounded-xl flex items-start gap-3">
                        <i class="ph-fill <?= $icon ?> text-xl mt-0.5 shrink-0"></i>
                        <div>
                            <p class="font-bold text-slate-800 text-sm mb-1"><?= htmlspecialchars($cName) ?></p>
                            <p class="text-xs text-slate-500 font-medium line-clamp-1" title="<?= htmlspecialchars($cSt['mod']) ?>"><?= htmlspecialchars($cSt['mod']) ?></p>
                            <div class="mt-2 text-xs font-black px-2 py-1 bg-white inline-block rounded-md text-slate-700 shadow-sm">
                                <?= $cpct ?>% Aproveitamento
                            </div>
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

</body>
</html>
