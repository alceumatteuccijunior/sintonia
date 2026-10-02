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

// Verifica se sprint pertence ao professor
$stmt = $pdo->prepare("SELECT s.*, c.name as class_name FROM sprints s JOIN classes c ON s.class_id = c.id WHERE s.id = ? AND s.teacher_id = ?");
$stmt->execute([$sprint_id, $_SESSION['user_id']]);
$sprint = $stmt->fetch();

if (!$sprint) {
    die("Sprint não encontrada ou sem permissão.");
}

// Pega todas as questões da sprint
$stmtQ = $pdo->prepare("
    SELECT q.*, sq.order_num 
    FROM sprint_questions sq
    JOIN questions q ON sq.question_id = q.id
    WHERE sq.sprint_id = ?
    ORDER BY sq.order_num ASC
");
$stmtQ->execute([$sprint_id]);
$questions = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

// Pega todas as opções
$qIds = array_column($questions, 'id');
$optionsByQ = [];
if (!empty($qIds)) {
    $placeholders = implode(',', array_fill(0, count($qIds), '?'));
    $stmtOpt = $pdo->prepare("SELECT * FROM question_options WHERE question_id IN ($placeholders) ORDER BY id ASC");
    $stmtOpt->execute($qIds);
    $allOptions = $stmtOpt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($allOptions as $opt) {
        $optionsByQ[$opt['question_id']][] = $opt;
    }
}

// Pega as descrições de capacidade
$stmtCap = $pdo->query("SELECT capacity_code, description FROM module_capacities");
$capDescriptions = [];
while ($row = $stmtCap->fetch()) {
    $capDescriptions[$row['capacity_code']] = $row['description'];
}

$questionsJson = json_encode($questions);
$optionsJson = json_encode($optionsByQ);
$capJson = json_encode($capDescriptions);

include 'includes/header.php';
?>

<div id="app-screen" class="absolute inset-0 z-40 flex flex-col w-full h-full bg-slate-900 text-white">
    <!-- Fundo Escuro para Projetor -->
    
    <!-- Topbar -->
    <header class="h-16 flex items-center justify-between px-6 bg-slate-800 border-b border-slate-700 shadow-sm relative z-20">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg bg-indigo-500 text-white flex items-center justify-center">
                <i class="ph-bold ph-projector-screen-chart"></i>
            </div>
            <span class="font-bold text-white text-lg hidden md:block">Correção com a Turma</span>
        </div>
        
        <div class="flex-1 flex justify-center text-center">
            <span class="font-bold text-slate-300 text-sm"><span class="text-indigo-400">Sprint:</span> <?= htmlspecialchars($sprint['name']) ?></span>
        </div>
        
        <div class="flex items-center gap-4">
            <a href="sprint_report.php?id=<?= $sprint_id ?>" class="text-sm font-bold text-slate-400 hover:text-white transition-colors">Fechar</a>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-1 flex flex-col relative h-full w-full overflow-hidden">
        
        <div class="flex-1 flex items-center justify-center p-4">
            <!-- Question Card -->
            <div id="question-card" class="bg-slate-800 p-8 md:p-12 rounded-3xl shadow-2xl border border-slate-700 w-full max-w-4xl min-h-[500px] flex flex-col relative animate-scale-up">
                
                <div class="flex justify-between items-start mb-6">
                    <span id="q-counter" class="text-indigo-400 font-bold text-xl tracking-tight"></span>
                    <span id="q-capacity" class="bg-slate-700 text-slate-300 px-3 py-1 rounded-lg text-xs font-bold uppercase tracking-wider"></span>
                </div>
                
                <div id="q-context" class="text-slate-400 text-sm mb-4 leading-relaxed font-medium"></div>
                <div id="q-command" class="text-white text-2xl font-bold mb-8 leading-tight"></div>
                
                <div id="q-options" class="space-y-4 flex-1">
                    <!-- Gerado via JS -->
                </div>
                
                <div class="flex justify-between mt-10 pt-6 border-t border-slate-700">
                    <button id="btn-prev" onclick="nav(-1)" class="px-6 py-3 rounded-xl font-bold bg-slate-700 hover:bg-slate-600 text-white transition-colors disabled:opacity-30 disabled:pointer-events-none flex items-center gap-2">
                        <i class="ph-bold ph-arrow-left"></i> Anterior
                    </button>
                    <button id="btn-next" onclick="nav(1)" class="px-6 py-3 rounded-xl font-bold bg-indigo-600 hover:bg-indigo-500 text-white shadow-lg shadow-indigo-500/20 transition-colors disabled:opacity-30 disabled:pointer-events-none flex items-center gap-2">
                        Próxima <i class="ph-bold ph-arrow-right"></i>
                    </button>
                </div>
            </div>
        </div>
        
    </main>
</div>

<script>
    const questions = <?= $questionsJson ?>;
    const optionsByQ = <?= $optionsJson ?>;
    const capacities = <?= $capJson ?>;
    
    let currentIndex = 0;
    
    function renderQuestion(index) {
        if (!questions[index]) return;
        const q = questions[index];
        const opts = optionsByQ[q.id] || [];
        
        document.getElementById('q-counter').innerText = `Questão ${index + 1} de ${questions.length}`;
        const capDesc = capacities[q.capacity] ? ` - ${capacities[q.capacity]}` : '';
        document.getElementById('q-capacity').innerText = q.capacity + capDesc;
        
        document.getElementById('q-context').innerText = q.context || '';
        document.getElementById('q-command').innerText = q.command || '';
        
        const optsContainer = document.getElementById('q-options');
        optsContainer.innerHTML = '';
        
        const letters = ['A', 'B', 'C', 'D', 'E'];
        
        opts.forEach((opt, i) => {
            const letter = letters[i] || '?';
            const isCorrect = parseInt(opt.is_correct) === 1;
            
            // Se for correta, pinta de verde. Se não, mantem cinza escuro.
            const baseClass = isCorrect 
                ? 'bg-green-900/40 border-green-500/50 text-green-100 shadow-[0_0_15px_rgba(34,197,94,0.15)] ring-2 ring-green-500/50' 
                : 'bg-slate-700/50 border-slate-600 text-slate-300 opacity-60';
                
            const letterClass = isCorrect
                ? 'bg-green-500 text-white shadow-md'
                : 'bg-slate-600 text-slate-400';
            
            const html = `
                <div class="p-4 rounded-2xl border ${baseClass} flex items-center gap-4 transition-all">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center font-bold flex-shrink-0 ${letterClass}">
                        ${letter}
                    </div>
                    <div class="font-medium text-lg flex-1">
                        ${opt.text}
                    </div>
                    ${isCorrect ? '<i class="ph-bold ph-check-circle text-green-400 text-2xl flex-shrink-0"></i>' : ''}
                </div>
            `;
            optsContainer.innerHTML += html;
        });
        
        document.getElementById('btn-prev').disabled = index === 0;
        document.getElementById('btn-next').disabled = index === questions.length - 1;
    }
    
    function nav(dir) {
        let next = currentIndex + dir;
        if (next >= 0 && next < questions.length) {
            currentIndex = next;
            const card = document.getElementById('question-card');
            card.classList.remove('animate-scale-up');
            void card.offsetWidth; // trigger reflow
            card.classList.add('animate-scale-up');
            renderQuestion(currentIndex);
        }
    }
    
    // Keybinds para projetor (setas do teclado ou passador de slide)
    document.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowRight' || e.key === 'PageDown') nav(1);
        if (e.key === 'ArrowLeft' || e.key === 'PageUp') nav(-1);
    });
    
    if (questions.length > 0) {
        renderQuestion(0);
    }
</script>

<?php include 'includes/footer.php'; ?>
