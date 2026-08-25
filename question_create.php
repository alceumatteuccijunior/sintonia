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
    $module_id = $_POST['module_id'] ?? '';
    $capacity = $_POST['capacity'] ?? '';
    $context = $_POST['context'] ?? '';
    $command = $_POST['command'] ?? '';
    $difficulty = $_POST['difficulty'] ?? 'Médio';
    $options = $_POST['options'] ?? [];
    $correct_index = $_POST['correct_index'] ?? '';
    
    if ($module_id && $capacity && $command && count($options) >= 2 && $correct_index !== '') {
        try {
            $pdo->beginTransaction();
            
            // Insert Question
            $stmtQ = $pdo->prepare("INSERT INTO questions (module_id, capacity, context, command, difficulty, teacher_id) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtQ->execute([$module_id, $capacity, $context, $command, $difficulty, $_SESSION['user_id']]);
            $question_id = $pdo->lastInsertId();
            
            // Insert Options
            $stmtO = $pdo->prepare("INSERT INTO question_options (question_id, text, is_correct) VALUES (?, ?, ?)");
            foreach ($options as $index => $text) {
                if (trim($text) !== '') {
                    $is_correct = ($index == $correct_index) ? 1 : 0;
                    $stmtO->execute([$question_id, trim($text), $is_correct]);
                }
            }
            
            $pdo->commit();
            $success = "Questão cadastrada com sucesso!";
            // Reset fields for the UI or redirect
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Erro ao salvar: " . $e->getMessage();
        }
    } else {
        $error = "Preencha os campos obrigatórios e marque uma alternativa correta.";
    }
}

// Fetch Courses and Modules to build a cascaded select or a grouped select
$stmt = $pdo->query("SELECT m.id, m.name as module_name, c.name as course_name FROM modules m JOIN courses c ON m.course_id = c.id ORDER BY c.name, m.name");
$modules = $stmt->fetchAll();

include 'includes/header.php';
?>

<div id="app-screen" class="absolute inset-0 z-40 flex flex-col w-full h-full bg-[#F8FAFC]">
    
    <div class="fixed inset-0 w-full h-full overflow-hidden pointer-events-none z-0">
        <div id="blob1" class="absolute top-[-5%] right-[-5%] w-[500px] h-[500px] bg-senai-cyan/15 rounded-full mix-blend-multiply filter blur-[80px] opacity-70 animate-blob"></div>
        <div id="blob3" class="absolute top-[25%] left-[25%] w-[400px] h-[400px] bg-senai-blue/10 rounded-full mix-blend-multiply filter blur-[80px] opacity-50 animate-blob"></div>
    </div>

    <?php
    $currentPage = 'questions';
    include 'includes/sidebar.php'; 
    ?>

    <main id="main-content" class="flex-1 flex flex-col relative h-full w-full">
        <header class="h-14 flex items-center justify-between px-4 md:px-6 absolute top-0 w-full z-20 pointer-events-none">
            <button onclick="toggleSidebar()" class="pointer-events-auto w-10 h-10 flex items-center justify-center bg-white/50 hover:bg-white rounded-xl backdrop-blur-md shadow-sm border border-white/60 transition-all active:scale-95 text-senai-dark hover:text-senai-cyan">
                <i id="sidebar-icon" class="ph-bold ph-list text-xl"></i>
            </button>
            <div class="flex-1"></div>
            <a href="questions.php" class="pointer-events-auto text-[11px] font-bold text-slate-600 hover:text-senai-blue flex items-center gap-1.5 bg-white/70 backdrop-blur-xl border border-white/80 shadow-sm px-4 py-2 rounded-full hover:shadow transition-all duration-300 active:scale-95">
                <i class="ph-fill ph-arrow-left text-slate-400"></i> Voltar ao Banco
            </a>
        </header>

        <div id="content-scroll-area" class="flex-1 overflow-y-auto no-scrollbar w-full flex flex-col items-center pt-24 pb-20 relative z-10 px-4 md:px-8">
            <div class="w-full max-w-3xl">
                
                <div class="mb-8 animate-slide-up text-center md:text-left">
                    <h1 class="text-3xl font-bold tracking-tight mb-2 text-slate-800">Nova Questão</h1>
                    <p class="text-slate-500 font-medium">Cadastre manualmente uma questão no formato estruturado.</p>
                </div>

                <?php if ($error): ?>
                    <div class="bg-red-50 text-red-500 p-4 rounded-xl text-sm font-medium mb-6 animate-fade-in border border-red-100">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="bg-green-50 text-green-600 p-4 rounded-xl text-sm font-medium mb-6 animate-fade-in border border-green-100 flex items-center gap-2">
                        <i class="ph-fill ph-check-circle text-lg"></i> <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="question_create.php" class="bg-white/80 backdrop-blur-md border border-white/80 rounded-3xl p-6 md:p-10 shadow-sm animate-fade-in delay-200">
                    
                    <div class="space-y-8">
                        <!-- Metadados da Questão -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div class="md:col-span-2">
                                <label class="block text-sm font-bold text-slate-700 mb-2">Unidade Curricular (Módulo)</label>
                                <select name="module_id" required class="w-full px-5 py-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-senai-blue/20 focus:border-senai-blue bg-slate-50/50 font-medium text-slate-700">
                                    <option value="" disabled selected>Selecione...</option>
                                    <?php 
                                    $current_course = '';
                                    foreach ($modules as $m): 
                                        if ($current_course !== $m['course_name']) {
                                            if ($current_course !== '') echo "</optgroup>";
                                            $current_course = $m['course_name'];
                                            echo "<optgroup label='" . htmlspecialchars($current_course) . "'>";
                                        }
                                    ?>
                                        <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['module_name']) ?></option>
                                    <?php endforeach; echo "</optgroup>"; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-2">Dificuldade</label>
                                <select name="difficulty" required class="w-full px-5 py-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-senai-blue/20 focus:border-senai-blue bg-slate-50/50 font-medium text-slate-700">
                                    <option value="Fácil">Fácil</option>
                                    <option value="Médio" selected>Médio</option>
                                    <option value="Difícil">Difícil</option>
                                </select>
                            </div>
                        </div>

                        <!-- Estrutura da Questão -->
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">Capacidade (Ex: C1, C2, C3...)</label>
                            <input type="text" name="capacity" required placeholder="Código ou nome da capacidade avaliada" class="w-full px-5 py-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-senai-blue/20 focus:border-senai-blue bg-slate-50/50 font-medium text-slate-700">
                        </div>

                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">Contexto (Opcional)</label>
                            <textarea name="context" rows="3" placeholder="Situação-problema, texto base ou introdução..." class="w-full px-5 py-4 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-senai-blue/20 focus:border-senai-blue bg-slate-50/50 font-medium text-slate-700 resize-y"></textarea>
                        </div>

                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">Comando (A pergunta em si)</label>
                            <textarea name="command" rows="3" required placeholder="Qual é o procedimento correto para..." class="w-full px-5 py-4 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-senai-blue/20 focus:border-senai-blue bg-slate-50/50 font-bold text-slate-800 resize-y"></textarea>
                        </div>

                        <!-- Alternativas -->
                        <div class="pt-6 border-t border-slate-100">
                            <h3 class="font-bold text-slate-800 mb-4 flex items-center gap-2">
                                <i class="ph-fill ph-list-dashes text-senai-orange"></i> Alternativas
                            </h3>
                            <p class="text-xs text-slate-500 mb-4">Escreva as alternativas e selecione a bolinha verde correspondente à resposta correta.</p>
                            
                            <div class="space-y-3" id="options-container">
                                <?php for ($i = 0; $i < 5; $i++): $letter = chr(65 + $i); ?>
                                    <div class="flex items-center gap-3 bg-slate-50/50 p-2 pl-4 rounded-xl border border-slate-200 hover:border-slate-300 transition-colors">
                                        <span class="font-bold text-slate-400 w-4"><?= $letter ?></span>
                                        <input type="text" name="options[<?= $i ?>]" placeholder="Texto da alternativa..." class="flex-1 bg-transparent border-none focus:ring-0 text-slate-700 font-medium py-2">
                                        <div class="pr-3 pl-3 border-l border-slate-200 flex items-center justify-center">
                                            <input type="radio" name="correct_index" value="<?= $i ?>" <?= $i === 0 ? 'required' : '' ?> class="w-5 h-5 text-green-500 border-slate-300 focus:ring-green-500 hover:scale-110 transition-transform cursor-pointer" title="Marcar como Correta">
                                        </div>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </div>

                        <div class="pt-6 mt-8 border-t border-slate-100 flex justify-end">
                            <button type="submit" class="bg-senai-blue text-white px-8 py-3.5 rounded-xl font-semibold shadow-md hover:bg-[#153673] hover:-translate-y-0.5 transition-all flex items-center gap-2">
                                <i class="ph-bold ph-floppy-disk"></i> Salvar Questão
                            </button>
                        </div>
                    </div>
                </form>

            </div>
        </div>
    </main>
</div>

<?php include 'includes/footer.php'; ?>
