<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: index.php");
    exit;
}
require_once 'config.php';
$pdo = getPDOConnection(true);

$student_id = $_SESSION['user_id'];
$sprint_id = $_GET['id'] ?? null;

if (!$sprint_id) {
    header("Location: student_dashboard.php");
    exit;
}

// Verifica se a sprint existe e está ativa
$stmtSprint = $pdo->prepare("SELECT * FROM sprints WHERE id = ? AND status = 'active'");
$stmtSprint->execute([$sprint_id]);
$sprint = $stmtSprint->fetch();

if (!$sprint) {
    header("Location: student_dashboard.php");
    exit;
}

// 1. Lógica de Tentativa (Attempt) e Timer
$stmtAttempt = $pdo->prepare("SELECT * FROM sprint_attempts WHERE sprint_id = ? AND student_id = ?");
$stmtAttempt->execute([$sprint_id, $student_id]);
$attempt = $stmtAttempt->fetch();

if (!$attempt) {
    // Inicia a tentativa agora
    $stmtInsert = $pdo->prepare("INSERT INTO sprint_attempts (sprint_id, student_id, started_at) VALUES (?, ?, NOW())");
    $stmtInsert->execute([$sprint_id, $student_id]);
    
    // Busca novamente
    $stmtAttempt->execute([$sprint_id, $student_id]);
    $attempt = $stmtAttempt->fetch();
}

if ($attempt['completed_at']) {
    // Já finalizou
    header("Location: student_dashboard.php");
    exit;
}

// Verifica o tempo
$started = new DateTime($attempt['started_at']);
$now = new DateTime();
$diff_seconds = $now->getTimestamp() - $started->getTimestamp();
$time_limit_seconds = $sprint['time_limit_minutes'] * 60;
$time_remaining = $time_limit_seconds - $diff_seconds;

if ($time_remaining <= 0) {
    // Tempo esgotou, finaliza a prova
    $stmtComplete = $pdo->prepare("UPDATE sprint_attempts SET completed_at = NOW() WHERE id = ?");
    $stmtComplete->execute([$attempt['id']]);
    
    // Disparo de E-mail
    require_once 'includes/mailer.php';
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $uri = rtrim(dirname($_SERVER['REQUEST_URI']), '/\\');
    $reportLink = "$protocol://$host$uri/sprint_report_pdf.php?attempt_id=" . $attempt['id'];
    $emailBody = "Olá <strong>{$_SESSION['user_name']}</strong>,<br><br>Você acabou de finalizar a Sprint <strong>{$sprint['name']}</strong>!<br><br>Seu relatório individual de desempenho já está disponível. Clique no botão abaixo para visualizá-lo e salvá-lo em PDF.<br><br><a href='{$reportLink}' style='display:inline-block; padding:10px 20px; background-color:#4f46e5; color:white; text-decoration:none; border-radius:5px;'>Ver Devolutiva em PDF</a>";
    $stmtEmail = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $stmtEmail->execute([$student_id]);
    if ($student_email = $stmtEmail->fetchColumn()) {
        send_system_email($student_email, "Devolutiva: {$sprint['name']}", $emailBody);
    }

    header("Location: student_dashboard.php");
    exit;
}

// 2. Busca as Questões da Sprint
$stmtQs = $pdo->prepare("
    SELECT sq.question_id, sq.order_num 
    FROM sprint_questions sq 
    WHERE sq.sprint_id = ? 
    ORDER BY sq.order_num
");
$stmtQs->execute([$sprint_id]);
$sprint_questions = $stmtQs->fetchAll();
$total_questions = count($sprint_questions);

if ($total_questions === 0) {
    header("Location: student_dashboard.php");
    exit;
}

// 3. Identifica a questão atual
$current_q_index = isset($_GET['q']) ? (int)$_GET['q'] - 1 : 0;
if ($current_q_index < 0 || $current_q_index >= $total_questions) {
    $current_q_index = 0;
}

$current_question_id = $sprint_questions[$current_q_index]['question_id'];
$is_last_question = ($current_q_index === $total_questions - 1);
$is_first_question = ($current_q_index === 0);

// 4. Salvar resposta (se for POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected_option = $_POST['option_id'] ?? null;
    $q_id_post = $_POST['question_id'] ?? null;
    $is_ajax = isset($_POST['ajax']);

    // Primeiro garante que a opção atual seja salva (inclusive na ultima questao)
    if ($selected_option && $q_id_post) {
        $stmtOpt = $pdo->prepare("SELECT is_correct FROM question_options WHERE id = ?");
        $stmtOpt->execute([$selected_option]);
        $is_correct = $stmtOpt->fetchColumn() ? 1 : 0;

        $stmtCheckA = $pdo->prepare("SELECT id FROM student_answers WHERE sprint_id = ? AND question_id = ? AND student_id = ?");
        $stmtCheckA->execute([$sprint_id, $q_id_post, $student_id]);
        if ($ans = $stmtCheckA->fetch()) {
            $pdo->prepare("UPDATE student_answers SET selected_option_id = ?, is_correct = ?, answered_at = NOW() WHERE id = ?")->execute([$selected_option, $is_correct, $ans['id']]);
        } else {
            $pdo->prepare("INSERT INTO student_answers (sprint_id, question_id, student_id, selected_option_id, is_correct) VALUES (?, ?, ?, ?, ?)")->execute([$sprint_id, $q_id_post, $student_id, $selected_option, $is_correct]);
        }
        
        if ($is_ajax) {
            echo json_encode(['status' => 'saved']);
            exit;
        }
    }

    if (isset($_POST['finish_sprint'])) {
        $stmtComplete = $pdo->prepare("UPDATE sprint_attempts SET completed_at = NOW() WHERE id = ?");
        $stmtComplete->execute([$attempt['id']]);
        
        // Disparo de E-mail
        require_once 'includes/mailer.php';
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        $uri = rtrim(dirname($_SERVER['REQUEST_URI']), '/\\');
        $reportLink = "$protocol://$host$uri/sprint_report_pdf.php?attempt_id=" . $attempt['id'];
        $emailBody = "Olá <strong>{$_SESSION['user_name']}</strong>,<br><br>Você acabou de finalizar a Sprint <strong>{$sprint['name']}</strong>!<br><br>Seu relatório individual de desempenho já está disponível. Clique no botão abaixo para visualizá-lo e salvá-lo em PDF.<br><br><a href='{$reportLink}' style='display:inline-block; padding:10px 20px; background-color:#4f46e5; color:white; text-decoration:none; border-radius:5px;'>Ver Devolutiva em PDF</a>";
        $stmtEmail = $pdo->prepare("SELECT email FROM users WHERE id = ?");
        $stmtEmail->execute([$student_id]);
        if ($student_email = $stmtEmail->fetchColumn()) {
            send_system_email($student_email, "Devolutiva: {$sprint['name']}", $emailBody);
        }

        header("Location: student_dashboard.php");
        exit;
    }
    
    if (!$is_ajax) {
        $next = isset($_POST['next_q']) ? $_POST['next_q'] : $current_q_index + 2;
        header("Location: sprint_solve.php?id=$sprint_id&q=$next");
        exit;
    }
}

// 5. Busca os dados da questão atual
$stmtQData = $pdo->prepare("SELECT * FROM questions WHERE id = ?");
$stmtQData->execute([$current_question_id]);
$question = $stmtQData->fetch();

$stmtOpts = $pdo->prepare("SELECT * FROM question_options WHERE question_id = ? ORDER BY id");
$stmtOpts->execute([$current_question_id]);
$options = $stmtOpts->fetchAll();

// Pega a resposta prévia se existir
$stmtSavedAns = $pdo->prepare("SELECT selected_option_id FROM student_answers WHERE sprint_id = ? AND question_id = ? AND student_id = ?");
$stmtSavedAns->execute([$sprint_id, $current_question_id, $student_id]);
$saved_option_id = $stmtSavedAns->fetchColumn();


// 6. Calcula progresso geral (Quantas respondidas)
$stmtProg = $pdo->prepare("SELECT count(DISTINCT question_id) FROM student_answers WHERE sprint_id = ? AND student_id = ?");
$stmtProg->execute([$sprint_id, $student_id]);
$answered_count = $stmtProg->fetchColumn();
$progress_percent = ($total_questions > 0) ? ($answered_count / $total_questions) * 100 : 0;

include 'includes/header.php';
?>

<div id="app-screen" class="absolute inset-0 z-40 flex flex-col w-full h-full bg-slate-50">
    
    <!-- Topbar Focada -->
    <header class="h-16 flex items-center justify-between px-6 bg-white border-b border-slate-200 shadow-sm relative z-20 sticky top-0 shrink-0">
        <div class="flex items-center gap-3">
            <span class="font-bold text-slate-800 tracking-tight hidden md:block"><?= htmlspecialchars($sprint['name']) ?></span>
            <div class="md:hidden flex items-center gap-2">
                <i class="ph-bold ph-student text-senai-orange"></i> <span class="font-bold text-slate-700">Prova</span>
            </div>
        </div>
        
        <!-- Timer UI -->
        <div class="flex items-center gap-3 bg-slate-100 px-4 py-1.5 rounded-full border border-slate-200 shadow-inner">
            <i class="ph-bold ph-timer text-slate-400" id="timer-icon"></i>
            <span id="countdown" class="font-bold text-slate-700 font-mono tracking-widest text-lg">
                --:--
            </span>
        </div>
        
        <div class="flex items-center gap-3">
            <button onclick="document.getElementById('modal-finish').classList.remove('hidden')" class="text-xs md:text-sm font-bold text-slate-500 hover:text-red-500 hover:bg-red-50 px-3 py-1.5 rounded-lg transition-colors">
                Finalizar Prova
            </button>
        </div>
    </header>

    <!-- Barra de Progresso Superior -->
    <div class="h-1.5 w-full bg-slate-200 shrink-0 relative">
        <div class="h-full bg-senai-orange transition-all duration-500 relative" style="width: <?= $progress_percent ?>%;">
            <!-- Bolinha de brilho na ponta -->
            <div class="absolute right-0 top-1/2 -translate-y-1/2 w-3 h-3 bg-white border-2 border-senai-orange rounded-full shadow-[0_0_8px_rgba(242,92,39,0.8)]"></div>
        </div>
    </div>

    <!-- Navegação de Bolinhas (Pagination) -->
    <div class="bg-white px-4 py-3 flex justify-center gap-2 overflow-x-auto shadow-sm shrink-0 border-b border-slate-100 no-scrollbar">
        <?php for($i = 0; $i < $total_questions; $i++): 
            // Checa se está respondida (simplificado, mas idealmente buscaria um array de respondidas)
            // Vou fazer um array rapido:
            static $answered_arr = null;
            if($answered_arr === null) {
                $stmtA = $pdo->prepare("SELECT question_id FROM student_answers WHERE sprint_id = ? AND student_id = ?");
                $stmtA->execute([$sprint_id, $student_id]);
                $answered_arr = $stmtA->fetchAll(PDO::FETCH_COLUMN);
            }
            $is_ans = in_array($sprint_questions[$i]['question_id'], $answered_arr);
            $is_active = ($i === $current_q_index);
            
            $btnClass = "w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold transition-all border-2 flex-shrink-0";
            if ($is_active) {
                $btnClass .= " border-senai-blue bg-white text-senai-blue shadow-md scale-110";
            } elseif ($is_ans) {
                $btnClass .= " border-senai-orange bg-senai-orange text-white";
            } else {
                $btnClass .= " border-slate-200 bg-slate-50 text-slate-400 hover:border-slate-300";
            }
        ?>
            <a href="sprint_solve.php?id=<?= $sprint_id ?>&q=<?= $i+1 ?>" id="nav-bubble-<?= $i ?>" class="<?= $btnClass ?>">
                <?= $i+1 ?>
            </a>
        <?php endfor; ?>
    </div>

    <!-- Main Content da Prova -->
    <main class="flex-1 overflow-y-auto w-full relative z-10 px-4 py-8">
        <div class="max-w-4xl mx-auto pb-24">
            
            <form method="POST" id="question-form">
                <input type="hidden" name="question_id" value="<?= $current_question_id ?>">
                <input type="hidden" name="next_q" id="next_q_input" value="<?= $current_q_index + 2 ?>">
                
                <div class="bg-white rounded-3xl shadow-sm border border-slate-200 p-6 md:p-10">
                    
                    <div class="flex items-center justify-between mb-6 border-b border-slate-100 pb-4">
                        <div class="flex items-center gap-2">
                            <span class="bg-slate-100 text-slate-500 px-3 py-1 rounded-lg text-xs font-bold uppercase tracking-widest">
                                Questão <?= $current_q_index + 1 ?>
                            </span>
                            <span id="save-status" class="<?= $saved_option_id ? 'text-green-500 bg-green-50' : 'hidden' ?> text-xs font-bold flex items-center gap-1 px-2 py-1 rounded-md transition-colors">
                                <i class="ph-bold ph-check"></i> Salva
                            </span>
                        </div>
                        <?php if (!empty($question['capacity'])): ?>
                        <div class="flex items-center gap-2" title="Capacidade Avaliada">
                            <i class="ph-bold ph-target text-senai-orange"></i>
                            <span class="text-sm font-bold text-slate-700"><?= htmlspecialchars($question['capacity']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Contexto -->
                    <?php if (!empty($question['context'])): ?>
                        <div class="prose prose-slate max-w-none text-slate-600 mb-8 p-6 bg-slate-50/50 rounded-2xl border border-slate-100 text-sm md:text-base leading-relaxed">
                            <strong class="text-slate-400 uppercase text-xs tracking-wider block mb-2">Contexto</strong>
                            <?= nl2br(htmlspecialchars($question['context'])) ?>
                        </div>
                    <?php endif; ?>

                    <!-- Comando -->
                    <div class="text-lg md:text-xl font-semibold text-slate-800 mb-8 leading-snug">
                        <?= nl2br(htmlspecialchars($question['command'])) ?>
                    </div>

                    <!-- Alternativas -->
                    <div class="space-y-4">
                        <?php foreach ($options as $index => $opt): 
                            $letter = chr(65 + $index); // A, B, C, D...
                            $checked = ($saved_option_id == $opt['id']) ? 'checked' : '';
                        ?>
                            <label class="radio-card relative flex items-start gap-4 p-5 rounded-2xl border-2 transition-all cursor-pointer group <?= $checked ? 'border-senai-orange bg-orange-50/30' : 'border-slate-200 hover:border-senai-orange/40 bg-white hover:bg-slate-50' ?>">
                                <div class="flex items-center h-6">
                                    <input type="radio" name="option_id" value="<?= $opt['id'] ?>" required <?= $checked ?> 
                                        class="w-5 h-5 text-senai-orange border-slate-300 focus:ring-senai-orange focus:ring-offset-0 transition-colors"
                                        onchange="autoSave(this.value); document.querySelectorAll('.radio-card').forEach(el => { el.classList.remove('border-senai-orange', 'bg-orange-50/30'); el.classList.add('border-slate-200'); }); this.closest('label').classList.replace('border-slate-200', 'border-senai-orange'); this.closest('label').classList.add('bg-orange-50/30');">
                                </div>
                                <div class="flex-1">
                                    <span class="absolute left-14 top-1/2 -translate-y-1/2 text-slate-300 font-bold text-4xl opacity-20 pointer-events-none"><?= $letter ?></span>
                                    <div class="text-slate-700 font-medium leading-relaxed relative z-10 pl-2">
                                        <span class="font-bold text-slate-800 mr-2"><?= $letter ?>)</span> 
                                        <?= htmlspecialchars($opt['text']) ?>
                                    </div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>

                </div>

                <!-- Footer Flutuante para Navegação -->
                <div class="fixed bottom-0 left-0 w-full bg-white/90 backdrop-blur-xl border-t border-slate-200 p-4 px-6 z-30 shadow-[0_-10px_20px_rgba(0,0,0,0.03)] flex justify-between max-w-5xl mx-auto md:left-1/2 md:-translate-x-1/2 rounded-t-2xl">
                    <?php if (!$is_first_question): ?>
                        <button type="button" onclick="document.getElementById('next_q_input').value = <?= $current_q_index ?>; document.getElementById('question-form').submit();" class="text-slate-500 hover:text-senai-blue font-bold px-4 py-2 rounded-xl transition-colors flex items-center gap-2">
                            <i class="ph-bold ph-arrow-left"></i> Anterior
                        </button>
                    <?php else: ?>
                        <div></div>
                    <?php endif; ?>
                    
                    <?php if (!$is_last_question): ?>
                        <button type="submit" class="bg-senai-blue text-white px-8 py-3 rounded-xl font-bold shadow-md hover:bg-[#153673] hover:-translate-y-0.5 transition-all flex items-center gap-2">
                            Avançar <i class="ph-bold ph-arrow-right"></i>
                        </button>
                    <?php else: ?>
                        <button type="button" onclick="document.getElementById('modal-finish').classList.remove('hidden')" class="bg-green-600 text-white px-8 py-3 rounded-xl font-bold shadow-md hover:bg-green-700 hover:-translate-y-0.5 transition-all flex items-center gap-2">
                            Finalizar Prova <i class="ph-bold ph-check"></i>
                        </button>
                    <?php endif; ?>
                </div>
            </form>
            
        </div>
    </main>
</div>

<!-- Modal Finalizar -->
<div id="modal-finish" class="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl p-8 max-w-sm w-full text-center shadow-2xl scale-100 animate-slide-up">
        <div class="w-20 h-20 bg-green-100 text-green-500 rounded-full flex items-center justify-center mx-auto mb-6">
            <i class="ph-fill ph-check-circle text-4xl"></i>
        </div>
        <h2 class="text-2xl font-bold text-slate-800 mb-2">Entregar Prova?</h2>
        <p class="text-slate-500 font-medium text-sm mb-8">Você respondeu <strong class="text-slate-700"><span id="answered-count-text"><?= $answered_count ?></span> de <?= $total_questions ?></strong> questões. Tem certeza que deseja finalizar? Não será possível alterar as respostas depois.</p>
        
        <form method="POST" action="sprint_solve.php?id=<?= $sprint_id ?>" class="flex flex-col gap-3">
            <input type="hidden" name="finish_sprint" value="1">
            <!-- Salva a última selecionada caso ele clique finalizar na própria questão -->
            <button type="button" onclick="document.getElementById('modal-finish').classList.add('hidden')" class="w-full py-3 rounded-xl font-bold text-slate-500 bg-slate-100 hover:bg-slate-200 transition-colors">Voltar para Prova</button>
            <button type="submit" onclick="submitFinal(event)" class="w-full py-3 rounded-xl font-bold text-white bg-green-500 shadow-md hover:bg-green-600 transition-colors">Sim, Entregar Prova</button>
        </form>
    </div>
</div>

<!-- Modal Anti-Cola -->
<div id="modal-anticheat" class="fixed inset-0 z-[60] bg-slate-900/80 backdrop-blur-md hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl p-8 max-w-sm w-full text-center shadow-2xl scale-100 animate-slide-up border-4 border-red-500">
        <div class="w-20 h-20 bg-red-100 text-red-600 rounded-full flex items-center justify-center mx-auto mb-6">
            <i class="ph-fill ph-warning-circle text-5xl animate-pulse"></i>
        </div>
        <h2 class="text-2xl font-bold text-slate-800 mb-2">Atenção!</h2>
        <p class="text-slate-600 font-medium text-sm mb-4">Foi detectado que você saiu da aba ou janela da prova.</p>
        <p class="text-red-500 font-bold mb-8 bg-red-50 py-2 rounded-lg">
            Aviso <span id="warning-count">0</span> de 3
        </p>
        <button type="button" onclick="document.getElementById('modal-anticheat').classList.add('hidden')" class="w-full py-3 rounded-xl font-bold text-white bg-senai-blue shadow-md hover:bg-[#153673] transition-colors">Entendi, voltar para prova</button>
    </div>
</div>

<script>
    let isCurrentQuestionAnswered = <?= $saved_option_id ? 'true' : 'false' ?>;

    // Sistema Auto-Save (Ajax)
    function autoSave(optionId) {
        const qId = <?= $current_question_id ?>;
        const formData = new FormData();
        formData.append('ajax', '1');
        formData.append('question_id', qId);
        formData.append('option_id', optionId);

        // UI Feedback: Mostra que está salvando
        const statusBadge = document.getElementById('save-status');
        if (statusBadge) {
            statusBadge.innerHTML = '<i class="ph-bold ph-spinner animate-spin"></i> Salvando...';
            statusBadge.classList.replace('text-green-500', 'text-slate-400');
            statusBadge.classList.replace('bg-green-50', 'bg-slate-100');
            statusBadge.classList.remove('hidden');
        }

        fetch('sprint_solve.php?id=<?= $sprint_id ?>&q=<?= $current_q_index + 1 ?>', {
            method: 'POST',
            body: formData
        }).then(res => res.json()).then(data => {
            if (data.status === 'saved' && statusBadge) {
                // UI Feedback: Salvo
                statusBadge.innerHTML = '<i class="ph-bold ph-check"></i> Salva';
                statusBadge.classList.replace('text-slate-400', 'text-green-500');
                statusBadge.classList.replace('bg-slate-100', 'bg-green-50');
                
                // Colore a bolinha de navegação no topo
                const navBubble = document.getElementById('nav-bubble-<?= $current_q_index ?>');
                if (navBubble) {
                    navBubble.classList.remove('border-senai-blue', 'text-senai-blue', 'bg-white', 'border-slate-200', 'text-slate-400', 'bg-slate-50');
                    navBubble.classList.add('border-senai-orange', 'bg-senai-orange', 'text-white');
                }

                // Incrementa contador do modal de finalização de forma inteligente (apenas 1 vez)
                if (!isCurrentQuestionAnswered) {
                    isCurrentQuestionAnswered = true;
                    const countText = document.getElementById('answered-count-text');
                    if (countText) {
                        countText.textContent = parseInt(countText.textContent) + 1;
                    }
                }
            }
        });
    }

    // Se ele clicar no modal de finalizar, precisamos capturar o formulário principal para salvar a ultima resposta junto
    function submitFinal(e) {
        if(e) e.preventDefault();
        const mainForm = document.getElementById('question-form');
        // Adiciona um input hidden de finalizar
        const finishInput = document.createElement('input');
        finishInput.type = 'hidden';
        finishInput.name = 'finish_sprint';
        finishInput.value = '1';
        mainForm.appendChild(finishInput);
        mainForm.submit();
    }

    // Timer Logic
    const timeRemainingSeconds = <?= $time_remaining ?>;
    let secondsLeft = timeRemainingSeconds;
    const countdownEl = document.getElementById('countdown');
    const timerIcon = document.getElementById('timer-icon');

    const timer = setInterval(() => {
        secondsLeft--;
        
        if (secondsLeft <= 0) {
            clearInterval(timer);
            countdownEl.textContent = "00:00";
            submitFinal();
            return;
        }

        const mins = Math.floor(secondsLeft / 60);
        const secs = secondsLeft % 60;
        
        countdownEl.textContent = `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
        
        // Alert colors
        if (secondsLeft < 300) { // last 5 minutes
            countdownEl.classList.remove('text-slate-700');
            countdownEl.classList.add('text-red-500', 'animate-pulse');
            timerIcon.classList.remove('text-slate-400');
            timerIcon.classList.add('text-red-500');
        }
    }, 1000);

    // Sistema Anti-Cola
    const sprintId = <?= $sprint_id ?>;
    const storageKey = `sprint_warnings_${sprintId}`;
    let warningCount = parseInt(sessionStorage.getItem(storageKey) || "0");
    let isUnloading = false;

    // Detecta quando a página está sendo descarregada (mudando de questão) para não contar como trapaça
    window.addEventListener('beforeunload', () => {
        isUnloading = true;
    });

    window.addEventListener('blur', () => {
        // Ignora se a prova já acabou ou se a página está mudando de questão
        if(secondsLeft <= 0 || isUnloading) return;
        
        warningCount++;
        sessionStorage.setItem(storageKey, warningCount);
        
        if (warningCount >= 3) {
            alert("Você excedeu o limite de saídas da tela. Sua prova será finalizada agora.");
            submitFinal();
        } else {
            document.getElementById('warning-count').textContent = warningCount;
            document.getElementById('modal-anticheat').classList.remove('hidden');
        }
    });
</script>

<?php include 'includes/footer.php'; ?>
