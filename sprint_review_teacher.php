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

// Verifica se sprint pertence ao professor (Administradores têm acesso irrestrito)
if ($_SESSION['user_role'] === 'admin') {
    $stmt = $pdo->prepare("
        SELECT s.*, c.name as class_name, co.name as course_name
        FROM sprints s
        JOIN classes c ON s.class_id = c.id
        LEFT JOIN courses co ON c.course_id = co.id
        WHERE s.id = ?
    ");
    $stmt->execute([$sprint_id]);
} else {
    $stmt = $pdo->prepare("
        SELECT s.*, c.name as class_name, co.name as course_name
        FROM sprints s
        JOIN classes c ON s.class_id = c.id
        LEFT JOIN courses co ON c.course_id = co.id
        WHERE s.id = ? AND s.teacher_id = ?
    ");
    $stmt->execute([$sprint_id, $_SESSION['user_id']]);
}
$sprint = $stmt->fetch();

if (!$sprint) {
    die("Sprint não encontrada ou sem permissão.");
}

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

// Pega todas as questões da sprint com módulo e descrição oficial da capacidade
$stmtQ = $pdo->prepare("
    SELECT q.*,
           sq.order_num,
           m.name as module_name,
           COALESCE(cc.description, mc.description) as capacity_desc
    FROM sprint_questions sq
    JOIN questions q ON sq.question_id = q.id
    LEFT JOIN modules m ON q.module_id = m.id
    LEFT JOIN course_capacities cc ON cc.course_id = m.course_id AND cc.capacity_code = q.capacity
    LEFT JOIN module_capacities mc ON mc.module_id = q.module_id AND mc.capacity_code = q.capacity
    WHERE sq.sprint_id = ?
    ORDER BY sq.order_num ASC
");
$stmtQ->execute([$sprint_id]);
$questions = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas de respostas dos alunos por alternativa nesta sprint
$stmtStats = $pdo->prepare("
    SELECT question_id, selected_option_id, is_correct, COUNT(*) as chosen_count
    FROM student_answers
    WHERE sprint_id = ?
    GROUP BY question_id, selected_option_id, is_correct
");
$stmtStats->execute([$sprint_id]);
$statsRows = $stmtStats->fetchAll(PDO::FETCH_ASSOC);

$optionVotesMap = [];   // [question_id][option_id] => count
$questionStatsMap = []; // [question_id] => ['total' => X, 'correct' => Y]
foreach ($statsRows as $sr) {
    $qid = (int)$sr['question_id'];
    $oid = (int)$sr['selected_option_id'];
    $cnt = (int)$sr['chosen_count'];
    $optionVotesMap[$qid][$oid] = ($optionVotesMap[$qid][$oid] ?? 0) + $cnt;
    if (!isset($questionStatsMap[$qid])) {
        $questionStatsMap[$qid] = ['total' => 0, 'correct' => 0];
    }
    $questionStatsMap[$qid]['total'] += $cnt;
    if ((int)$sr['is_correct'] === 1) {
        $questionStatsMap[$qid]['correct'] += $cnt;
    }
}

// Pega todas as alternativas das questões
$qIds = array_column($questions, 'id');
$optionsByQ = [];
if (!empty($qIds)) {
    $placeholders = implode(',', array_fill(0, count($qIds), '?'));
    $stmtOpt = $pdo->prepare("SELECT * FROM question_options WHERE question_id IN ($placeholders) ORDER BY id ASC");
    $stmtOpt->execute($qIds);
    $allOptions = $stmtOpt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allOptions as $opt) {
        $qid = (int)$opt['question_id'];
        $oid = (int)$opt['id'];
        $qTotal = $questionStatsMap[$qid]['total'] ?? 0;
        $votes = $optionVotesMap[$qid][$oid] ?? 0;
        $opt['votes'] = $votes;
        $opt['vote_pct'] = $qTotal > 0 ? round(($votes / $qTotal) * 100, 1) : 0;
        $optionsByQ[$qid][] = $opt;
    }
}

// Enriquece cada questão com os dados de desempenho da turma
foreach ($questions as &$qItem) {
    $qid = (int)$qItem['id'];
    $qTotal = $questionStatsMap[$qid]['total'] ?? 0;
    $qCorr  = $questionStatsMap[$qid]['correct'] ?? 0;
    $qItem['total_answers']   = $qTotal;
    $qItem['correct_answers'] = $qCorr;
    $qItem['accuracy_rate']   = $qTotal > 0 ? round(($qCorr / $qTotal) * 100, 1) : null;
}
unset($qItem);

$questionsJson = json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
$optionsJson   = json_encode($optionsByQ, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

include 'includes/header.php';
?>

<div id="app-screen" class="fixed inset-0 z-40 flex flex-col w-full h-full bg-slate-950 text-white select-none">

    <!-- Topbar Principal do Modo Projetor -->
    <header class="min-h-[64px] py-2.5 px-4 md:px-6 bg-slate-900 border-b border-slate-800 shadow-md flex flex-wrap items-center justify-between gap-3 shrink-0 z-30">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-indigo-600 text-white flex items-center justify-center shadow-md shadow-indigo-500/20">
                <i class="ph-bold ph-projector-screen-chart text-xl"></i>
            </div>
            <div>
                <h1 class="font-extrabold text-white text-sm md:text-base leading-tight">Modo Projetor • Correção com a Turma</h1>
                <p class="text-xs text-slate-400 font-medium">
                    <span class="text-indigo-400 font-bold"><?= htmlspecialchars($sprint['name']) ?></span>
                    • Turma: <span class="text-slate-300 font-semibold"><?= htmlspecialchars($sprint['class_name']) ?></span>
                </p>
            </div>
        </div>

        <!-- Controles de Projeção (Revelar Gabarito, Tamanho da Fonte, Tela Cheia, Fechar) -->
        <div class="flex flex-wrap items-center gap-2">
            <!-- Toggle Revelar/Ocultar Gabarito -->
            <button type="button" id="btn-toggle-reveal" onclick="toggleRevealMode()" class="px-3.5 py-2 rounded-xl font-bold text-xs flex items-center gap-2 transition-all bg-emerald-600 hover:bg-emerald-500 text-white shadow-sm" title="Atalho: Barra de Espaço">
                <i id="icon-reveal" class="ph-bold ph-eye text-sm"></i>
                <span id="text-reveal">Gabarito Visível</span>
            </button>

            <!-- Controle de Fonte (A- / A+) -->
            <div class="inline-flex items-center bg-slate-800 border border-slate-700 rounded-xl p-1">
                <button type="button" onclick="changeFontScale(-1)" class="px-2.5 py-1 rounded-lg text-xs font-extrabold text-slate-300 hover:text-white hover:bg-slate-700 transition-colors" title="Diminuir fonte">
                    A-
                </button>
                <span id="font-scale-label" class="px-2 text-[11px] font-bold text-indigo-400">100%</span>
                <button type="button" onclick="changeFontScale(1)" class="px-2.5 py-1 rounded-lg text-xs font-extrabold text-slate-300 hover:text-white hover:bg-slate-700 transition-colors" title="Aumentar fonte para projeção">
                    A+
                </button>
            </div>

            <!-- Botão Tela Cheia -->
            <button type="button" onclick="toggleProjectorFullscreen()" class="p-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-300 hover:text-white transition-colors" title="Alternar Tela Cheia (F11)">
                <i id="fullscreen-icon" class="ph-bold ph-corners-out text-base"></i>
            </button>

            <!-- Botão Voltar ao Relatório -->
            <a href="sprint_report.php?id=<?= $sprint_id ?>" class="px-3.5 py-2 rounded-xl bg-slate-800 hover:bg-red-600/90 border border-slate-700 hover:border-red-500 text-slate-300 hover:text-white text-xs font-bold transition-all flex items-center gap-1.5">
                <i class="ph-bold ph-x"></i> Fechar
            </a>
        </div>
    </header>

    <!-- Barra de Navegação Rápida por Questões (Bolinhas 1..N com Semáforo de Desempenho) -->
    <div class="bg-slate-900/90 border-b border-slate-800/80 px-4 py-2.5 flex items-center gap-2 overflow-x-auto shrink-0 z-20">
        <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mr-1 shrink-0">Ir para:</span>
        <div id="question-nav-bubbles" class="flex items-center gap-1.5">
            <!-- Gerado via JS -->
        </div>
    </div>

    <!-- Área Central com Scroll Fluido (DESBLOQUEADO) -->
    <main id="projector-scroll-area" class="flex-1 overflow-y-auto w-full px-4 md:px-8 py-6 md:py-8 scroll-smooth select-text">
        <div class="max-w-5xl mx-auto pb-16">

            <?php if (empty($questions)): ?>
                <div class="bg-slate-900 border border-slate-800 rounded-3xl p-12 text-center">
                    <p class="text-slate-400 font-bold text-lg">Nenhuma questão encontrada nesta avaliação.</p>
                </div>
            <?php else: ?>
                <!-- Card da Questão Projetada -->
                <div id="question-card" class="bg-slate-900 border border-slate-800 rounded-3xl shadow-2xl p-6 md:p-10 transition-all">

                    <!-- Linha 1: Número da Questão, Código SAEP, Módulo, Dificuldade e Taxa da Turma -->
                    <div class="flex flex-wrap items-center justify-between gap-3 pb-5 mb-5 border-b border-slate-800">
                        <div class="flex flex-wrap items-center gap-2.5">
                            <span id="q-counter" class="px-3.5 py-1.5 rounded-xl bg-indigo-600 text-white font-black text-base md:text-lg tracking-tight shadow-sm"></span>
                            <span id="q-ext-code" class="hidden px-2.5 py-1 rounded-lg bg-sky-950/90 text-sky-300 border border-sky-800 text-xs font-extrabold"></span>
                            <span id="q-module" class="px-3 py-1 rounded-lg bg-slate-800 text-slate-300 border border-slate-700 text-xs font-bold uppercase tracking-wider"></span>
                            <span id="q-difficulty" class="px-3 py-1 rounded-lg text-xs font-extrabold uppercase tracking-wider"></span>
                        </div>

                        <!-- Indicador de Taxa de Acerto da Turma nesta Questão -->
                        <div id="q-class-accuracy-badge" class="px-3.5 py-1.5 rounded-xl text-xs md:text-sm font-extrabold flex items-center gap-2 border"></div>
                    </div>

                    <!-- Linha 2: Faixa da Capacidade Oficial da Matriz SAEP -->
                    <div id="q-capacity-box" class="mb-6 p-4 rounded-2xl bg-indigo-950/50 border border-indigo-500/30 flex items-start gap-3">
                        <div class="w-8 h-8 rounded-xl bg-indigo-500/20 text-indigo-300 flex items-center justify-center shrink-0 mt-0.5">
                            <i class="ph-fill ph-target text-lg"></i>
                        </div>
                        <div class="flex-1">
                            <span class="text-[10px] font-extrabold uppercase tracking-widest text-indigo-400 block mb-0.5">Capacidade Oficial Avaliada</span>
                            <p id="q-capacity-text" class="text-sm md:text-base font-bold text-indigo-100 leading-snug"></p>
                        </div>
                    </div>

                    <!-- Linha 3: Contexto / Texto-Base (Oculto automaticamente quando vazio) -->
                    <div id="q-context-box" class="mb-6 p-5 md:p-6 rounded-2xl bg-slate-800/75 border-l-4 border-indigo-400 border-t border-r border-b border-slate-700/70 hidden">
                        <span class="text-[11px] font-extrabold uppercase tracking-widest text-indigo-400 block mb-2 flex items-center gap-1.5">
                            <i class="ph-bold ph-article"></i> Contexto / Texto-Base
                        </span>
                        <div id="q-context" class="text-slate-200 font-medium leading-relaxed whitespace-pre-line"></div>
                    </div>

                    <!-- Linha 4: Imagem de Apoio do Enunciado (com Clique para Ampliar) -->
                    <div id="q-image-container" class="mb-6 p-4 md:p-6 bg-slate-950/70 rounded-2xl border border-slate-800 text-center hidden">
                        <div class="flex items-center justify-between text-xs text-slate-400 font-bold mb-3 px-1">
                            <span class="flex items-center gap-1.5 text-indigo-300">
                                <i class="ph-bold ph-image"></i> Figura / Diagrama de Apoio
                            </span>
                            <button type="button" onclick="openProjectorLightbox(document.getElementById('q-image').src)" class="px-3 py-1 rounded-lg bg-indigo-600/20 hover:bg-indigo-600/40 text-indigo-300 border border-indigo-500/30 text-xs font-bold flex items-center gap-1.5 transition-colors">
                                <i class="ph-bold ph-magnifying-glass-plus"></i> Ampliar Imagem no Projetor
                            </button>
                        </div>
                        <img id="q-image" src="#" alt="Imagem de apoio" onclick="openProjectorLightbox(this.src)" class="max-h-[420px] w-auto mx-auto rounded-xl border border-slate-700 shadow-lg object-contain bg-white p-2 cursor-zoom-in hover:opacity-95 transition-opacity">
                    </div>

                    <!-- Linha 5: Comando / Pergunta da Questão -->
                    <div class="mb-7">
                        <span class="text-[11px] font-extrabold uppercase tracking-widest text-amber-400 block mb-2 flex items-center gap-1.5">
                            <i class="ph-fill ph-chat-circle-text"></i> Comando da Questão
                        </span>
                        <div id="q-command" class="text-white font-extrabold leading-snug whitespace-pre-line"></div>
                    </div>

                    <!-- Linha 6: Alternativas -->
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[11px] font-extrabold uppercase tracking-widest text-slate-400">Alternativas</span>
                            <span id="reveal-hint-label" class="text-xs font-bold text-slate-400"></span>
                        </div>
                        <div id="q-options" class="space-y-3.5">
                            <!-- Gerado via JS -->
                        </div>
                    </div>

                </div>
            <?php endif; ?>

        </div>
    </main>

    <!-- Barra Inferior Fixa de Navegação -->
    <footer class="h-16 px-4 md:px-8 bg-slate-900/95 backdrop-blur-md border-t border-slate-800 flex items-center justify-between gap-4 shrink-0 z-30">
        <button id="btn-prev" onclick="nav(-1)" class="px-5 py-2.5 rounded-xl font-bold text-sm bg-slate-800 hover:bg-slate-700 border border-slate-700 text-white transition-all disabled:opacity-30 disabled:pointer-events-none flex items-center gap-2">
            <i class="ph-bold ph-arrow-left"></i> Anterior <span class="hidden sm:inline text-xs text-slate-400 font-normal">(←)</span>
        </button>

        <div class="flex items-center gap-3 text-xs text-slate-400 font-semibold">
            <button type="button" onclick="toggleRevealMode()" class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 font-bold transition-colors flex items-center gap-2">
                <i class="ph-bold ph-eye"></i> Alternar Gabarito <span class="hidden md:inline text-[11px] text-slate-400">(Espaço)</span>
            </button>
        </div>

        <button id="btn-next" onclick="nav(1)" class="px-6 py-2.5 rounded-xl font-bold text-sm bg-indigo-600 hover:bg-indigo-500 text-white shadow-lg shadow-indigo-600/20 transition-all disabled:opacity-30 disabled:pointer-events-none flex items-center gap-2">
            Próxima <span class="hidden sm:inline text-xs text-indigo-200 font-normal">(→)</span> <i class="ph-bold ph-arrow-right"></i>
        </button>
    </footer>

</div>

<!-- Lightbox Modal para Ampliar Imagens no Projetor -->
<div id="projectorLightbox" class="fixed inset-0 z-[80] bg-black/90 backdrop-blur-md hidden items-center justify-center p-4 cursor-zoom-out" onclick="closeProjectorLightbox()">
    <div class="relative max-w-6xl max-h-[94vh] flex items-center justify-center" onclick="event.stopPropagation()">
        <img id="projector-lightbox-img" src="#" alt="Imagem Ampliada" class="max-h-[90vh] max-w-[92vw] rounded-2xl shadow-2xl border border-white/20 object-contain bg-white p-3">
        <button type="button" onclick="closeProjectorLightbox()" class="absolute -top-4 -right-4 w-11 h-11 rounded-full bg-white text-slate-900 shadow-xl flex items-center justify-center hover:bg-slate-200 transition-colors" title="Fechar (Esc)">
            <i class="ph-bold ph-x text-xl"></i>
        </button>
    </div>
</div>

<script>
    const questions = <?= $questionsJson ?: '[]' ?>;
    const optionsByQ = <?= $optionsJson ?: '{}' ?>;

    let currentIndex = 0;
    let isAnswerRevealed = true; // Padrão: gabarito visível (professor pode ocultar com 1 clique ou Espaço)
    let fontScaleStep = 0; // -1 (90%), 0 (100%), 1 (115%), 2 (130%)
    const fontScales = [
        { label: '90%',  ctx: 'text-sm',  cmd: 'text-lg md:text-xl',  opt: 'text-sm md:text-base' },
        { label: '100%', ctx: 'text-base md:text-lg', cmd: 'text-xl md:text-2xl', opt: 'text-base md:text-lg' },
        { label: '115%', ctx: 'text-lg md:text-xl',   cmd: 'text-2xl md:text-3xl', opt: 'text-lg md:text-xl' },
        { label: '130%', ctx: 'text-xl md:text-2xl',  cmd: 'text-3xl md:text-4xl', opt: 'text-xl md:text-2xl' }
    ];
    let currentScaleIdx = 1; // 100%

    function escapeHtml(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function buildNavBubbles() {
        const container = document.getElementById('question-nav-bubbles');
        if (!container) return;
        container.innerHTML = '';

        questions.forEach((q, idx) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.onclick = () => goToQuestion(idx);

            const isActive = (idx === currentIndex);
            const rate = q.accuracy_rate;
            let colorClass = 'bg-slate-800 text-slate-300 border-slate-700 hover:border-slate-500';

            if (rate !== null && rate !== undefined) {
                if (rate >= 70) {
                    colorClass = 'bg-emerald-950/80 text-emerald-300 border-emerald-700/70 hover:bg-emerald-900';
                } else if (rate >= 50) {
                    colorClass = 'bg-amber-950/80 text-amber-300 border-amber-700/70 hover:bg-amber-900';
                } else {
                    colorClass = 'bg-red-950/80 text-red-300 border-red-700/70 hover:bg-red-900';
                }
            }

            if (isActive) {
                btn.className = `w-9 h-9 rounded-xl font-black text-xs flex items-center justify-center shrink-0 transition-all bg-indigo-600 text-white border-2 border-white shadow-md scale-110`;
            } else {
                btn.className = `w-8 h-8 rounded-xl font-bold text-xs flex items-center justify-center shrink-0 border transition-all ${colorClass}`;
            }

            btn.title = rate !== null ? `Questão ${idx + 1} (${rate}% de acerto na turma)` : `Questão ${idx + 1}`;
            btn.textContent = idx + 1;
            container.appendChild(btn);
        });
    }

    function applyTypographyScale() {
        const scale = fontScales[currentScaleIdx];
        const labelEl = document.getElementById('font-scale-label');
        if (labelEl) labelEl.textContent = scale.label;

        const ctxEl = document.getElementById('q-context');
        if (ctxEl) ctxEl.className = `text-slate-100 font-medium leading-relaxed whitespace-pre-line ${scale.ctx}`;

        const cmdEl = document.getElementById('q-command');
        if (cmdEl) cmdEl.className = `text-white font-extrabold leading-snug whitespace-pre-line ${scale.cmd}`;

        document.querySelectorAll('.proj-opt-text').forEach(el => {
            el.className = `proj-opt-text font-semibold leading-relaxed whitespace-pre-line ${scale.opt}`;
        });
    }

    function changeFontScale(delta) {
        const nextIdx = currentScaleIdx + delta;
        if (nextIdx >= 0 && nextIdx < fontScales.length) {
            currentScaleIdx = nextIdx;
            applyTypographyScale();
        }
    }

    function toggleRevealMode() {
        isAnswerRevealed = !isAnswerRevealed;
        const btn = document.getElementById('btn-toggle-reveal');
        const icon = document.getElementById('icon-reveal');
        const txt = document.getElementById('text-reveal');

        if (isAnswerRevealed) {
            btn.className = 'px-3.5 py-2 rounded-xl font-bold text-xs flex items-center gap-2 transition-all bg-emerald-600 hover:bg-emerald-500 text-white shadow-sm';
            icon.className = 'ph-bold ph-eye text-sm';
            txt.textContent = 'Gabarito Visível';
        } else {
            btn.className = 'px-3.5 py-2 rounded-xl font-bold text-xs flex items-center gap-2 transition-all bg-amber-600 hover:bg-amber-500 text-white shadow-sm';
            icon.className = 'ph-bold ph-eye-slash text-sm';
            txt.textContent = 'Gabarito Oculto (Discussão)';
        }
        renderQuestion(currentIndex, false);
    }

    function renderQuestion(index, scrollToTop = true) {
        if (!questions[index]) return;
        const q = questions[index];
        const opts = optionsByQ[q.id] || [];

        buildNavBubbles();

        document.getElementById('q-counter').textContent = `Questão ${index + 1} de ${questions.length}`;

        // Código externo SAEP (se houver)
        const extEl = document.getElementById('q-ext-code');
        if (q.external_code && String(q.external_code).trim() !== '') {
            extEl.textContent = q.external_code;
            extEl.classList.remove('hidden');
        } else {
            extEl.classList.add('hidden');
        }

        // Módulo
        const modEl = document.getElementById('q-module');
        modEl.textContent = q.module_name || 'Geral';

        // Dificuldade
        const diffEl = document.getElementById('q-difficulty');
        const diff = q.difficulty || 'Médio';
        diffEl.textContent = diff;
        if (diff === 'Fácil') {
            diffEl.className = 'px-3 py-1 rounded-lg text-xs font-extrabold uppercase tracking-wider bg-emerald-950 text-emerald-300 border border-emerald-700/60';
        } else if (diff === 'Difícil') {
            diffEl.className = 'px-3 py-1 rounded-lg text-xs font-extrabold uppercase tracking-wider bg-red-950 text-red-300 border border-red-700/60';
        } else {
            diffEl.className = 'px-3 py-1 rounded-lg text-xs font-extrabold uppercase tracking-wider bg-amber-950 text-amber-300 border border-amber-700/60';
        }

        // Taxa de acerto da turma na questão
        const accBadge = document.getElementById('q-class-accuracy-badge');
        if (q.total_answers > 0 && isAnswerRevealed) {
            const rate = q.accuracy_rate;
            let accColor = 'bg-emerald-950/90 text-emerald-300 border-emerald-700';
            let accIcon = 'ph-check-circle';
            if (rate < 50) {
                accColor = 'bg-red-950/90 text-red-300 border-red-700';
                accIcon = 'ph-warning-circle';
            } else if (rate < 70) {
                accColor = 'bg-amber-950/90 text-amber-300 border-amber-700';
                accIcon = 'ph-info';
            }
            accBadge.className = `px-3.5 py-1.5 rounded-xl text-xs md:text-sm font-extrabold flex items-center gap-2 border ${accColor}`;
            accBadge.innerHTML = `<i class="ph-fill ${accIcon} text-base"></i> <span>Turma: ${rate}% de acerto (${q.correct_answers}/${q.total_answers})</span>`;
            accBadge.classList.remove('hidden');
        } else if (q.total_answers > 0 && !isAnswerRevealed) {
            accBadge.className = 'px-3.5 py-1.5 rounded-xl text-xs font-bold flex items-center gap-2 border bg-slate-800 text-slate-400 border-slate-700';
            accBadge.innerHTML = `<i class="ph-bold ph-users"></i> <span>${q.total_answers} resposta(s) na turma</span>`;
            accBadge.classList.remove('hidden');
        } else {
            accBadge.classList.add('hidden');
        }

        // Capacidade Oficial + Descrição
        const capBox = document.getElementById('q-capacity-box');
        const capText = document.getElementById('q-capacity-text');
        const capCode = (q.capacity || '').trim();
        const capDesc = (q.capacity_desc || '').trim();
        if (capCode !== '' || capDesc !== '') {
            capText.innerHTML = `<span class="inline-block px-2.5 py-0.5 rounded-lg bg-indigo-600 text-white text-xs font-black mr-2">${escapeHtml(capCode || 'CAP')}</span>${escapeHtml(capDesc || 'Capacidade técnica avaliada')}`;
            capBox.classList.remove('hidden');
        } else {
            capBox.classList.add('hidden');
        }

        // Contexto / Texto-Base
        const ctxBox = document.getElementById('q-context-box');
        const ctxEl = document.getElementById('q-context');
        const ctxVal = (q.context || '').trim();
        if (ctxVal !== '') {
            ctxEl.textContent = ctxVal;
            ctxBox.classList.remove('hidden');
        } else {
            ctxEl.textContent = '';
            ctxBox.classList.add('hidden');
        }

        // Comando
        document.getElementById('q-command').textContent = (q.command || '').trim();

        // Imagem do Enunciado
        const imgContainer = document.getElementById('q-image-container');
        const imgEl = document.getElementById('q-image');
        if (q.image_url && String(q.image_url).trim() !== '') {
            imgEl.src = q.image_url;
            imgContainer.classList.remove('hidden');
        } else {
            imgEl.src = '#';
            imgContainer.classList.add('hidden');
        }

        // Identifica qual foi o principal distrator (alternativa errada mais marcada)
        let topWrongOptionId = null;
        let topWrongVotes = 0;
        opts.forEach(o => {
            if (parseInt(o.is_correct) !== 1 && (o.votes || 0) > topWrongVotes) {
                topWrongVotes = o.votes;
                topWrongOptionId = o.id;
            }
        });

        const hintLabel = document.getElementById('reveal-hint-label');
        if (hintLabel) {
            hintLabel.textContent = isAnswerRevealed
                ? 'Gabarito e estatísticas da turma exibidos abaixo'
                : 'Modo discussão ativo — pressione Espaço ou clique em "Gabarito Visível" para revelar a resposta';
        }

        // Renderiza Alternativas
        const optsContainer = document.getElementById('q-options');
        optsContainer.innerHTML = '';
        const letters = ['A', 'B', 'C', 'D', 'E', 'F'];
        const scale = fontScales[currentScaleIdx];

        opts.forEach((opt, i) => {
            const letter = letters[i] || '?';
            const isCorrect = parseInt(opt.is_correct) === 1;
            const isTopDistractor = (isAnswerRevealed && !isCorrect && opt.id === topWrongOptionId && topWrongVotes > 0);
            const votePct = opt.vote_pct || 0;
            const votes = opt.votes || 0;

            let cardStyle = 'bg-slate-800/80 border-slate-700 text-slate-200 hover:border-slate-600';
            let letterStyle = 'bg-slate-700 text-slate-300 border border-slate-600';
            let barColor = 'bg-slate-600';

            if (isAnswerRevealed) {
                if (isCorrect) {
                    cardStyle = 'bg-emerald-950/60 border-2 border-emerald-500 text-emerald-50 shadow-[0_0_25px_rgba(16,185,129,0.18)]';
                    letterStyle = 'bg-emerald-500 text-white shadow-md';
                    barColor = 'bg-emerald-500';
                } else if (isTopDistractor) {
                    cardStyle = 'bg-red-950/35 border border-red-500/60 text-slate-200';
                    letterStyle = 'bg-red-900/80 text-red-200 border border-red-700';
                    barColor = 'bg-red-500';
                } else {
                    cardStyle = 'bg-slate-800/45 border border-slate-700/80 text-slate-300 opacity-85';
                    letterStyle = 'bg-slate-700 text-slate-400';
                }
            }

            const optTextHtml = opt.text ? `<div class="proj-opt-text font-semibold leading-relaxed whitespace-pre-line ${scale.opt}">${escapeHtml(opt.text)}</div>` : '';
            const optImgHtml = opt.image_url ? `
                <div class="mt-3 flex flex-col items-start gap-1.5">
                    <img src="${escapeHtml(opt.image_url)}" alt="Alternativa ${letter}" onclick="openProjectorLightbox('${escapeHtml(opt.image_url)}')" class="max-h-60 rounded-xl border border-slate-600 bg-white p-2 object-contain cursor-zoom-in hover:opacity-95 transition-opacity">
                    <button type="button" onclick="openProjectorLightbox('${escapeHtml(opt.image_url)}')" class="text-xs font-bold text-indigo-300 hover:text-indigo-200 flex items-center gap-1">
                        <i class="ph-bold ph-magnifying-glass-plus"></i> Ampliar imagem da alternativa ${letter}
                    </button>
                </div>
            ` : '';

            const statsHtml = (isAnswerRevealed && q.total_answers > 0) ? `
                <div class="mt-3 pt-2.5 border-t ${isCorrect ? 'border-emerald-800/60' : 'border-slate-700/60'} flex items-center gap-3">
                    <div class="flex-1 h-2 bg-slate-900/80 rounded-full overflow-hidden">
                        <div class="h-full ${barColor} rounded-full transition-all duration-500" style="width: ${votePct}%"></div>
                    </div>
                    <span class="text-xs font-extrabold ${isCorrect ? 'text-emerald-300' : (isTopDistractor ? 'text-red-300' : 'text-slate-400')} shrink-0">
                        ${votes} aluno(s) (${votePct}%)
                    </span>
                </div>
            ` : '';

            const statusTagsHtml = isAnswerRevealed ? `
                <div class="flex items-center gap-2 shrink-0">
                    ${isCorrect ? '<span class="px-3 py-1 rounded-xl bg-emerald-500 text-white text-xs font-black uppercase tracking-wider flex items-center gap-1 shadow"><i class="ph-bold ph-check-circle text-sm"></i> Correta</span>' : ''}
                    ${isTopDistractor ? '<span class="px-2.5 py-1 rounded-xl bg-red-500/20 border border-red-500/50 text-red-300 text-[11px] font-extrabold flex items-center gap-1"><i class="ph-fill ph-warning"></i> Distrator mais marcado</span>' : ''}
                </div>
            ` : '';

            optsContainer.innerHTML += `
                <div class="p-4 md:p-5 rounded-2xl border transition-all ${cardStyle}">
                    <div class="flex items-start gap-4">
                        <div class="w-10 h-10 md:w-11 md:h-11 rounded-xl flex items-center justify-center font-black text-base md:text-lg shrink-0 ${letterStyle}">
                            ${letter}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="flex-1 min-w-[200px]">
                                    ${optTextHtml}
                                    ${optImgHtml}
                                </div>
                                ${statusTagsHtml}
                            </div>
                            ${statsHtml}
                        </div>
                    </div>
                </div>
            `;
        });

        applyTypographyScale();

        document.getElementById('btn-prev').disabled = (index === 0);
        document.getElementById('btn-next').disabled = (index === questions.length - 1);

        if (scrollToTop) {
            const scrollArea = document.getElementById('projector-scroll-area');
            if (scrollArea) {
                scrollArea.scrollTo({ top: 0, behavior: 'smooth' });
            }
        }
    }

    function goToQuestion(idx) {
        if (idx >= 0 && idx < questions.length) {
            currentIndex = idx;
            renderQuestion(currentIndex, true);
        }
    }

    function nav(dir) {
        const next = currentIndex + dir;
        if (next >= 0 && next < questions.length) {
            currentIndex = next;
            renderQuestion(currentIndex, true);
        }
    }

    // Lightbox de Imagens no Projetor
    function openProjectorLightbox(src) {
        if (!src || src === '#' || src.endsWith('#')) return;
        document.getElementById('projector-lightbox-img').src = src;
        const lb = document.getElementById('projectorLightbox');
        lb.classList.remove('hidden');
        lb.classList.add('flex');
    }

    function closeProjectorLightbox() {
        const lb = document.getElementById('projectorLightbox');
        lb.classList.add('hidden');
        lb.classList.remove('flex');
    }

    // Tela Cheia
    function toggleProjectorFullscreen() {
        const icon = document.getElementById('fullscreen-icon');
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen().catch(() => {});
            if (icon) icon.className = 'ph-bold ph-corners-in text-base';
        } else {
            document.exitFullscreen().catch(() => {});
            if (icon) icon.className = 'ph-bold ph-corners-out text-base';
        }
    }

    // Atalhos de teclado para projetor / passador de slides
    document.addEventListener('keydown', (e) => {
        const lb = document.getElementById('projectorLightbox');
        const isLbOpen = lb && !lb.classList.contains('hidden');

        if (e.key === 'Escape' && isLbOpen) {
            closeProjectorLightbox();
            return;
        }
        if (e.key === 'ArrowRight' || e.key === 'PageDown') {
            e.preventDefault();
            nav(1);
        } else if (e.key === 'ArrowLeft' || e.key === 'PageUp') {
            e.preventDefault();
            nav(-1);
        } else if (e.key === ' ' || e.code === 'Space') {
            // Barra de espaço alterna exibição do gabarito
            if (document.activeElement && ['INPUT', 'TEXTAREA', 'BUTTON'].includes(document.activeElement.tagName)) return;
            e.preventDefault();
            toggleRevealMode();
        }
    });

    if (questions.length > 0) {
        renderQuestion(0, false);
    }
</script>

<?php include 'includes/footer.php'; ?>
