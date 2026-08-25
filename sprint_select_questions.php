<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    header("Location: index.php");
    exit;
}
require_once 'config.php';
$pdo = getPDOConnection(true);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $class_id = $_POST['class_id'] ?? '';
    $name = $_POST['name'] ?? '';
    $time_limit = $_POST['time_limit_minutes'] ?? '';
    
    // Processamento Final (Salvando a Sprint)
    if (isset($_POST['save_sprint'])) {
        $selected_questions = $_POST['question_ids'] ?? [];
        
        if (empty($selected_questions)) {
            $error = "Você precisa selecionar pelo menos uma questão para a Sprint.";
        } else {
            try {
                $pdo->beginTransaction();
                
                // Insere a Sprint
                $stmt = $pdo->prepare("INSERT INTO sprints (teacher_id, class_id, name, time_limit_minutes) VALUES (?, ?, ?, ?)");
                $stmt->execute([$_SESSION['user_id'], $class_id, $name, $time_limit]);
                $sprint_id = $pdo->lastInsertId();
                
                // Insere as Questões (order_num fixo por enquanto)
                $stmtQ = $pdo->prepare("INSERT INTO sprint_questions (sprint_id, question_id, order_num) VALUES (?, ?, ?)");
                $order = 1;
                foreach ($selected_questions as $q_id) {
                    $stmtQ->execute([$sprint_id, $q_id, $order++]);
                }
                
                $pdo->commit();
                $success = "Sprint criada com sucesso! " . count($selected_questions) . " questões selecionadas.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Erro ao salvar a Sprint: " . $e->getMessage();
            }
        }
    }
    
    // Carregar dados para exibir a tela
    if ($class_id) {
        $stmtC = $pdo->prepare("SELECT * FROM classes WHERE id = ?");
        $stmtC->execute([$class_id]);
        $classe = $stmtC->fetch();
        
        if ($classe) {
            $course_id = $classe['course_id'];
            
            // Buscar todas as questões do curso desta turma
            $stmtQs = $pdo->prepare("
                SELECT q.*, m.name as module_name 
                FROM questions q 
                JOIN modules m ON q.module_id = m.id 
                WHERE m.course_id = ?
                ORDER BY m.name, q.id
            ");
            $stmtQs->execute([$course_id]);
            $questoes = $stmtQs->fetchAll();
            
            // Buscar quais questões já foram usadas para ESTA turma em sprints anteriores
            $stmtUsed = $pdo->prepare("
                SELECT DISTINCT sq.question_id 
                FROM sprint_questions sq 
                JOIN sprints s ON sq.sprint_id = s.id 
                WHERE s.class_id = ?
            ");
            $stmtUsed->execute([$class_id]);
            $used_questions = $stmtUsed->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $error = "Turma não encontrada.";
        }
    } else {
        header("Location: sprint_create.php");
        exit;
    }
    
} else {
    // Se não for POST, volta para o passo 1
    header("Location: sprint_create.php");
    exit;
}

include 'includes/header.php';
?>

<div id="app-screen" class="absolute inset-0 z-40 flex w-full h-full">
    <!-- Fundo Vivo -->
    <div class="fixed inset-0 w-full h-full overflow-hidden pointer-events-none z-0 bg-[#F8FAFC]">
        <div id="blob1" class="absolute top-[-5%] right-[-5%] w-[500px] h-[500px] bg-senai-cyan/15 rounded-full mix-blend-multiply filter blur-[80px] opacity-70 animate-blob"></div>
    </div>

    <?php
    $currentPage = 'dashboard';
    include 'includes/sidebar.php'; 
    ?>

    <main id="main-content" class="flex-1 flex flex-col relative h-full w-full">
        <header class="h-14 flex items-center justify-between px-4 md:px-6 absolute top-0 w-full z-20 pointer-events-none">
            <button onclick="toggleSidebar()" class="pointer-events-auto w-10 h-10 flex items-center justify-center bg-white/50 hover:bg-white rounded-xl backdrop-blur-md shadow-sm border border-white/60 transition-all active:scale-95 group text-senai-dark hover:text-senai-cyan">
                <i id="sidebar-icon" class="ph-bold ph-list text-xl"></i>
            </button>
            <div class="flex-1"></div>
        </header>

        <div id="content-scroll-area" class="flex-1 overflow-y-auto no-scrollbar w-full flex flex-col items-center pt-24 pb-20 relative z-10 px-4 md:px-8">
            <div class="w-full max-w-5xl">
                
                <?php if ($success): ?>
                    <div class="bg-green-50 text-green-600 p-6 rounded-2xl text-center shadow-sm animate-slide-up mb-8">
                        <i class="ph-fill ph-check-circle text-5xl mb-4"></i>
                        <h2 class="text-2xl font-bold mb-2">Pronto!</h2>
                        <p class="text-lg font-medium mb-6"><?= htmlspecialchars($success) ?></p>
                        <a href="dashboard.php" class="bg-green-600 text-white px-8 py-3 rounded-xl font-bold shadow-md hover:bg-green-700 transition-colors inline-block">Voltar ao Dashboard</a>
                    </div>
                <?php else: ?>

                <!-- Progresso Simulado -->
                <div class="flex items-center justify-center mb-8 gap-3 opacity-80">
                    <div class="flex items-center gap-2 text-senai-blue font-bold text-sm">
                        <div class="w-6 h-6 rounded-full bg-senai-blue text-white flex items-center justify-center text-xs"><i class="ph-bold ph-check"></i></div>
                        Dados Base
                    </div>
                    <div class="w-12 h-px bg-senai-blue"></div>
                    <div class="flex items-center gap-2 text-senai-blue font-bold text-sm">
                        <div class="w-6 h-6 rounded-full bg-senai-blue text-white flex items-center justify-center text-xs">2</div>
                        Questões
                    </div>
                </div>

                <div class="flex flex-col md:flex-row md:items-end justify-between mb-8 animate-slide-up gap-4">
                    <div>
                        <h1 class="text-3xl font-bold tracking-tight mb-2 text-slate-800">Selecione as Questões</h1>
                        <p class="text-slate-500 font-medium">Sprint: <strong class="text-senai-blue"><?= htmlspecialchars($name) ?></strong> • Turma: <strong><?= htmlspecialchars($classe['name']) ?></strong></p>
                    </div>
                    <div class="flex items-center gap-2 bg-white px-4 py-2 rounded-xl shadow-sm border border-slate-200">
                        <input type="checkbox" id="hideUsed" class="w-4 h-4 rounded text-senai-blue focus:ring-senai-blue border-slate-300" onchange="toggleUsedQuestions()">
                        <label for="hideUsed" class="text-sm font-bold text-slate-600 cursor-pointer">Ocultar já respondidas</label>
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="bg-red-50 text-red-500 p-4 rounded-xl text-sm font-medium mb-6 animate-fade-in border border-red-100">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="sprint_select_questions.php" class="animate-fade-in delay-200 pb-20">
                    <!-- Campos Ocultos para preservar os dados -->
                    <input type="hidden" name="class_id" value="<?= htmlspecialchars($class_id) ?>">
                    <input type="hidden" name="name" value="<?= htmlspecialchars($name) ?>">
                    <input type="hidden" name="time_limit_minutes" value="<?= htmlspecialchars($time_limit) ?>">
                    <input type="hidden" name="save_sprint" value="1">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($questoes as $q): 
                            $isUsed = in_array($q['id'], $used_questions);
                            $cardClass = $isUsed ? 'border-amber-200 bg-amber-50/30 opacity-75' : 'border-slate-200 bg-white hover:border-senai-blue/40 hover:shadow-md';
                        ?>
                            <label class="question-card rounded-2xl p-5 shadow-sm transition-all relative flex flex-col cursor-pointer border <?= $cardClass ?>" data-used="<?= $isUsed ? 'true' : 'false' ?>">
                                <div class="absolute top-4 right-4 z-10">
                                    <input type="checkbox" name="question_ids[]" value="<?= $q['id'] ?>" class="w-5 h-5 rounded text-senai-blue focus:ring-senai-blue border-slate-300 shadow-sm transition-all focus:ring-offset-0">
                                </div>
                                
                                <div class="flex justify-between items-start mb-3 pr-8">
                                    <span class="text-[10px] font-bold px-2 py-1 rounded-md bg-slate-100 text-slate-600 uppercase tracking-wider truncate max-w-[150px]">
                                        <?= htmlspecialchars($q['module_name']) ?>
                                    </span>
                                </div>
                                
                                <?php if ($isUsed): ?>
                                    <div class="mb-3">
                                        <span class="text-[10px] font-bold px-2 py-1 rounded-md bg-amber-100 text-amber-700 uppercase tracking-wider flex items-center gap-1 inline-flex">
                                            <i class="ph-fill ph-warning-circle"></i> Já respondida pela turma
                                        </span>
                                    </div>
                                <?php endif; ?>
                                
                                <p class="text-sm font-semibold text-slate-800 mb-4 line-clamp-4 flex-grow">
                                    <?= htmlspecialchars($q['command']) ?>
                                </p>
                                
                                <div class="pt-3 border-t border-slate-100 flex justify-between items-center mt-auto">
                                    <span class="text-xs font-bold text-slate-400">
                                        CAP: <?= htmlspecialchars($q['capacity']) ?>
                                    </span>
                                    <span class="text-[10px] font-bold px-2 py-1 rounded-md uppercase tracking-wider <?= $q['difficulty'] === 'Fácil' ? 'bg-green-100 text-green-700' : ($q['difficulty'] === 'Médio' ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700') ?>">
                                        <?= htmlspecialchars($q['difficulty']) ?>
                                    </span>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <!-- Floating Action Bar -->
                    <div class="fixed bottom-0 left-0 w-full bg-white/80 backdrop-blur-xl border-t border-slate-200 p-4 px-6 z-50 flex items-center justify-between md:pl-72 shadow-[0_-4px_20px_rgba(0,0,0,0.05)]">
                        <div class="text-sm font-bold text-slate-600">
                            <span id="selected-count" class="text-senai-blue text-lg">0</span> questões selecionadas
                        </div>
                        <button type="submit" class="bg-senai-blue text-white px-8 py-3 rounded-xl font-bold shadow-md hover:bg-[#153673] hover:-translate-y-0.5 transition-all flex items-center gap-2">
                            Finalizar Sprint <i class="ph-bold ph-check"></i>
                        </button>
                    </div>
                </form>

                <script>
                    function toggleUsedQuestions() {
                        const hide = document.getElementById('hideUsed').checked;
                        const cards = document.querySelectorAll('.question-card[data-used="true"]');
                        cards.forEach(card => {
                            card.style.display = hide ? 'none' : 'flex';
                        });
                    }

                    // Count selected questions
                    const checkboxes = document.querySelectorAll('input[name="question_ids[]"]');
                    const countEl = document.getElementById('selected-count');
                    checkboxes.forEach(cb => {
                        cb.addEventListener('change', () => {
                            const count = document.querySelectorAll('input[name="question_ids[]"]:checked').length;
                            countEl.textContent = count;
                            
                            // Visual feedback on card
                            const card = cb.closest('.question-card');
                            if(cb.checked) {
                                card.classList.add('ring-2', 'ring-senai-blue', 'border-transparent');
                            } else {
                                card.classList.remove('ring-2', 'ring-senai-blue', 'border-transparent');
                            }
                        });
                    });
                </script>
                
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<?php include 'includes/footer.php'; ?>
