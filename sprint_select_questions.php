<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'teacher'])) {
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
    $time_min = (!empty($_POST['time_min_minutes'])) ? (int)$_POST['time_min_minutes'] : null;
    $teacher_id = $_POST['teacher_id'] ?? $_SESSION['user_id'];
    
    // Processamento Final (Salvando a Sprint)
    if (isset($_POST['save_sprint'])) {
        $selected_questions = $_POST['question_ids'] ?? [];
        
        if (empty($selected_questions)) {
            $error = "Você precisa selecionar pelo menos uma questão para a Sprint.";
        } else {
            try {
                // AUTO-FIX Tabela antiga (adiciona order_num se não existir)
                try {
                    $pdo->exec("ALTER TABLE sprint_questions ADD COLUMN order_num INT");
                } catch (Exception $e) {}

                $pdo->beginTransaction();
                
                // Insere a Sprint como 'active' para que os alunos já possam ver
                $stmt = $pdo->prepare("INSERT INTO sprints (teacher_id, class_id, name, time_limit_minutes, time_min_minutes, status) VALUES (?, ?, ?, ?, ?, 'active')");
                $stmt->execute([$teacher_id, $class_id, $name, $time_limit, $time_min]);
                $sprint_id = $pdo->lastInsertId();
                
                // Insere as Questões
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
                WHERE m.course_id = ? AND (q.is_active = 1 OR q.is_active IS NULL)
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

            // Coletar dados para os filtros e carregar as alternativas
            $modulos_unicos = [];
            $capacidades_unicas = [];
            $questoes_json = [];

            foreach ($questoes as &$q) {
                $modulos_unicos[$q['module_id']] = $q['module_name'];
                if (!empty($q['capacity'])) {
                    $capacidades_unicas[$q['capacity']] = $q['capacity'];
                }

                // Buscar alternativas desta questão
                $stmtOpt = $pdo->prepare("SELECT text, is_correct FROM question_options WHERE question_id = ?");
                $stmtOpt->execute([$q['id']]);
                $q['options'] = $stmtOpt->fetchAll();

                // Salvar no dicionário JSON para o Javascript (Modal)
                $questoes_json[$q['id']] = $q;
            }
            unset($q);

            asort($modulos_unicos);
            ksort($capacidades_unicas);

        } else {
            $error = "Turma não encontrada.";
        }
    } else {
        header("Location: sprint_create.php");
        exit;
    }
    
} else {
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
                <div class="flex items-center justify-center mb-8 gap-3 opacity-80 hidden md:flex">
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

                <div class="flex flex-col mb-6 animate-slide-up">
                    <h1 class="text-3xl font-bold tracking-tight mb-2 text-slate-800 flex items-center gap-3">
                        Selecione as Questões
                        <button onclick="openHelpModal('Selecionar Questões (Passo 2)', 'Neste segundo passo, escolha quais questões comporão a avaliação.<br><br><b>Como montar a prova:</b><br>- Você pode usar a barra lateral para filtrar questões por Competência ou Módulo.<br>- Marque as caixas de seleção (checkboxes) das questões desejadas.<br>- Questões que esta turma já respondeu em outras provas estarão marcadas para evitar repetição.<br>- Quando terminar, clique em <b>Finalizar e Salvar Sprint</b> no painel lateral.')" class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 hover:text-senai-blue hover:bg-blue-50 transition-colors shadow-inner" title="Como Usar">
                            <i class="ph-bold ph-question text-lg"></i>
                        </button>
                    </h1>
                    <p class="text-slate-500 font-medium">Sprint: <strong class="text-senai-blue"><?= htmlspecialchars($name) ?></strong> • Turma: <strong><?= htmlspecialchars($classe['name']) ?></strong></p>
                </div>

                <?php if ($error): ?>
                    <div class="bg-red-50 text-red-500 p-4 rounded-xl text-sm font-medium mb-6 animate-fade-in border border-red-100">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                
                <!-- Barra de Filtros -->
                <div class="bg-white/80 backdrop-blur-md border border-slate-200 rounded-2xl p-4 shadow-sm mb-6 animate-fade-in sticky top-0 z-30">
                    <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
                        <div class="md:col-span-1">
                            <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Módulo/Unidade</label>
                            <select id="filter-module" class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-lg px-3 py-2 focus:ring-1 focus:ring-senai-blue outline-none transition-all">
                                <option value="all">Todos os módulos</option>
                                <?php foreach($modulos_unicos as $id => $mName): ?>
                                    <option value="<?= $id ?>"><?= htmlspecialchars($mName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="md:col-span-1">
                            <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Capacidade</label>
                            <select id="filter-capacity" class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-lg px-3 py-2 focus:ring-1 focus:ring-senai-blue outline-none transition-all">
                                <option value="all">Todas as capacidades</option>
                                <?php foreach($capacidades_unicas as $cap): ?>
                                    <option value="<?= htmlspecialchars($cap) ?>"><?= htmlspecialchars($cap) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="md:col-span-1">
                            <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Lote/Tag</label>
                            <select id="filter-tag" class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-lg px-3 py-2 focus:ring-1 focus:ring-senai-blue outline-none transition-all">
                                <option value="all">Todas as tags</option>
                                <?php foreach($tags_unicas as $tag): ?>
                                    <option value="<?= htmlspecialchars($tag) ?>"><?= htmlspecialchars($tag) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="md:col-span-1">
                            <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Dificuldade</label>
                            <select id="filter-difficulty" class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-lg px-3 py-2 focus:ring-1 focus:ring-senai-blue outline-none transition-all">
                                <option value="all">Todas as dificuldades</option>
                                <option value="Fácil">Fácil</option>
                                <option value="Médio">Médio</option>
                                <option value="Difícil">Difícil</option>
                            </select>
                        </div>
                        <div class="md:col-span-1 flex items-end">
                            <label class="flex items-center gap-2 bg-slate-50 hover:bg-slate-100 px-3 py-2 rounded-lg border border-slate-200 cursor-pointer w-full transition-colors h-[38px]">
                                <input type="checkbox" id="filter-used" class="w-4 h-4 rounded text-senai-blue focus:ring-senai-blue border-slate-300">
                                <span class="text-[10px] font-bold text-slate-600 uppercase tracking-wider">Ocultar usadas</span>
                            </label>
                        </div>
                    </div>
                </div>

                <form method="POST" action="sprint_select_questions.php" class="animate-fade-in delay-200 pb-20">
                    <input type="hidden" name="class_id" value="<?= htmlspecialchars($class_id) ?>">
                    <input type="hidden" name="name" value="<?= htmlspecialchars($name) ?>">
                    <input type="hidden" name="time_limit_minutes" value="<?= htmlspecialchars($time_limit) ?>">
                    <input type="hidden" name="time_min_minutes" value="<?= htmlspecialchars($_POST['time_min_minutes'] ?? '') ?>">
                    <input type="hidden" name="teacher_id" value="<?= htmlspecialchars($teacher_id) ?>">
                    <input type="hidden" name="save_sprint" value="1">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" id="questions-grid">
                        <?php foreach ($questoes as $q): 
                            $isUsed = in_array($q['id'], $used_questions);
                            $cardClass = $isUsed ? 'border-amber-200 bg-amber-50/40 opacity-80' : 'border-slate-200 bg-white hover:border-senai-blue/40 hover:shadow-md';
                            
                            $diffColor = 'bg-slate-100 text-slate-600';
                            if ($q['difficulty'] === 'Fácil') $diffColor = 'bg-green-100 text-green-700';
                            if ($q['difficulty'] === 'Médio') $diffColor = 'bg-yellow-100 text-yellow-700';
                            if ($q['difficulty'] === 'Difícil') $diffColor = 'bg-red-100 text-red-700';
                        ?>
                            <div class="question-card rounded-2xl shadow-sm transition-all relative flex flex-col border <?= $cardClass ?>" 
                                 data-used="<?= $isUsed ? 'true' : 'false' ?>"
                                 data-module="<?= $q['module_id'] ?>"
                                 data-capacity="<?= htmlspecialchars($q['capacity']) ?>"
                                 data-tag="<?= htmlspecialchars($q['import_tag'] ?? '') ?>"
                                 data-difficulty="<?= htmlspecialchars($q['difficulty']) ?>">
                                
                                <label class="p-5 flex flex-col h-full cursor-pointer">
                                    <div class="absolute top-4 right-4 z-10">
                                        <input type="checkbox" name="question_ids[]" value="<?= $q['id'] ?>" class="w-5 h-5 rounded text-senai-blue focus:ring-senai-blue border-slate-300 shadow-sm transition-all focus:ring-offset-0 checkbox-select">
                                    </div>
                                    
                                    <div class="flex justify-between items-start mb-3 pr-8">
                                        <span class="text-[10px] font-bold px-2 py-1 rounded-md bg-slate-100 text-slate-600 uppercase tracking-wider truncate max-w-[150px]" title="<?= htmlspecialchars($q['module_name']) ?>">
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
                                    
                                    <p class="text-sm font-semibold text-slate-800 mb-4 line-clamp-4 flex-grow" title="<?= htmlspecialchars($q['command']) ?>">
                                        <?= htmlspecialchars($q['command']) ?>
                                    </p>
                                    
                                    <div class="pt-3 border-t border-slate-100 flex justify-between items-center mt-auto">
                                        <div class="flex items-center gap-1">
                                            <span class="text-[10px] font-bold px-2 py-1 rounded-md bg-senai-cyan/10 text-senai-cyan uppercase tracking-wider">
                                                CAP: <?= htmlspecialchars($q['capacity']) ?>
                                            </span>
                                            <span class="text-[10px] font-bold px-2 py-1 rounded-md <?= $diffColor ?> uppercase tracking-wider">
                                                <?= htmlspecialchars($q['difficulty']) ?>
                                            </span>
                                        </div>
                                    </div>
                                </label>
                                
                                <button type="button" onclick="showQuestionDetails(<?= $q['id'] ?>)" class="absolute bottom-4 right-4 text-senai-blue bg-blue-50 hover:bg-senai-blue hover:text-white px-2 py-1 rounded text-xs font-bold transition-colors z-20">
                                    Detalhes
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div id="no-results" class="hidden text-center py-10 bg-white/50 backdrop-blur-sm rounded-xl border border-slate-200 mt-4">
                        <i class="ph-thin ph-magnifying-glass text-4xl text-slate-400 mb-2"></i>
                        <p class="text-slate-500 font-medium">Nenhuma questão corresponde aos filtros aplicados.</p>
                    </div>

                    <!-- Floating Action Bar -->
                    <div class="fixed bottom-0 left-0 w-full bg-white/80 backdrop-blur-xl border-t border-slate-200 p-4 px-6 z-40 flex items-center justify-between md:pl-72 shadow-[0_-4px_20px_rgba(0,0,0,0.05)]">
                        <div class="text-sm font-bold text-slate-600">
                            <span id="selected-count" class="text-senai-blue text-lg">0</span> questões selecionadas
                        </div>
                        <button type="submit" class="bg-senai-blue text-white px-8 py-3 rounded-xl font-bold shadow-md hover:bg-[#153673] hover:-translate-y-0.5 transition-all flex items-center gap-2">
                            Finalizar Sprint <i class="ph-bold ph-check"></i>
                        </button>
                    </div>
                </form>

                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<!-- Modal Detalhes da Questão -->
<div id="detailsModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-3xl overflow-hidden scale-95 opacity-0 transition-all duration-300 flex flex-col max-h-[90vh]" id="detailsModalContent">
        
        <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/80 shrink-0">
            <h3 class="font-bold text-lg text-slate-800 flex items-center gap-2">
                <i class="ph-bold ph-magnifying-glass-plus text-senai-blue"></i> Detalhes da Questão
            </h3>
            <button onclick="closeDetailsModal()" class="text-slate-400 hover:text-red-500 transition-colors bg-white rounded-full p-1 shadow-sm border border-slate-200">
                <i class="ph-bold ph-x text-lg"></i>
            </button>
        </div>
        
        <div class="p-6 overflow-y-auto flex-1 bg-white">
            <div class="flex gap-2 mb-4" id="modal-badges">
                <!-- Badges injetadas via JS -->
            </div>
            
            <div class="mb-5 bg-slate-50 p-4 rounded-xl border border-slate-200">
                <h4 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Contexto</h4>
                <p class="text-sm text-slate-700" id="modal-context"></p>
            </div>
            
            <div class="mb-6">
                <h4 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Comando da Questão</h4>
                <p class="text-base font-semibold text-slate-900" id="modal-command"></p>
            </div>
            
            <div>
                <h4 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-3">Alternativas</h4>
                <div class="space-y-2" id="modal-options">
                    <!-- Alternativas injetadas via JS -->
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Dicionário de questões em JSON
    const questionsData = <?= json_encode($questoes_json, JSON_INVALID_UTF8_SUBSTITUTE) ?>;

    // Elementos de Filtro
    const fModule = document.getElementById('filter-module');
    const fCapacity = document.getElementById('filter-capacity');
    const fDifficulty = document.getElementById('filter-difficulty');
    const fTag = document.getElementById('filter-tag');
    const fUsed = document.getElementById('filter-used');
    const cards = document.querySelectorAll('.question-card');
    const noResults = document.getElementById('no-results');

    function applyFilters() {
        const mod = fModule.value;
        const cap = fCapacity.value;
        const diff = fDifficulty.value;
        const tag = fTag ? fTag.value : 'all';
        const hideUsed = fUsed.checked;
        
        let visibleCount = 0;

        cards.forEach(card => {
            let show = true;
            
            if (mod !== 'all' && card.dataset.module !== mod) show = false;
            if (cap !== 'all' && card.dataset.capacity !== cap) show = false;
            if (diff !== 'all' && card.dataset.difficulty !== diff) show = false;
            if (tag !== 'all' && card.dataset.tag !== tag) show = false;
            if (hideUsed && card.dataset.used === 'true') show = false;
            
            if (show) {
                card.style.display = 'flex';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });

        if (visibleCount === 0) {
            noResults.classList.remove('hidden');
        } else {
            noResults.classList.add('hidden');
        }
    }

    if(fModule) {
        fModule.addEventListener('change', applyFilters);
        fCapacity.addEventListener('change', applyFilters);
        fDifficulty.addEventListener('change', applyFilters);
        if(fTag) fTag.addEventListener('change', applyFilters);
        fUsed.addEventListener('change', applyFilters);
    }

    // Contagem de questões selecionadas
    const checkboxes = document.querySelectorAll('.checkbox-select');
    const countEl = document.getElementById('selected-count');
    checkboxes.forEach(cb => {
        cb.addEventListener('change', () => {
            const count = document.querySelectorAll('.checkbox-select:checked').length;
            countEl.textContent = count;
            
            const card = cb.closest('.question-card');
            if(cb.checked) {
                card.classList.add('ring-2', 'ring-senai-blue', 'border-transparent');
            } else {
                card.classList.remove('ring-2', 'ring-senai-blue', 'border-transparent');
            }
        });
    });

    // Lógica do Modal de Detalhes
    function showQuestionDetails(id) {
        const q = questionsData[id];
        if(!q) return;

        // Limpa e popula dados
        document.getElementById('modal-context').textContent = q.context || 'Nenhum contexto fornecido.';
        document.getElementById('modal-command').textContent = q.command;
        
        let diffColor = 'bg-slate-100 text-slate-600';
        if (q.difficulty === 'Fácil') diffColor = 'bg-green-100 text-green-700';
        if (q.difficulty === 'Médio') diffColor = 'bg-yellow-100 text-yellow-700';
        if (q.difficulty === 'Difícil') diffColor = 'bg-red-100 text-red-700';

        document.getElementById('modal-badges').innerHTML = `
            <span class="text-[10px] font-bold px-2 py-1 rounded-md bg-slate-100 text-slate-600 uppercase tracking-wider">${q.module_name}</span>
            <span class="text-[10px] font-bold px-2 py-1 rounded-md bg-senai-cyan/10 text-senai-cyan uppercase tracking-wider">CAP: ${q.capacity}</span>
            <span class="text-[10px] font-bold px-2 py-1 rounded-md ${diffColor} uppercase tracking-wider">${q.difficulty}</span>
        `;

        const optsContainer = document.getElementById('modal-options');
        optsContainer.innerHTML = '';
        
        if (q.options && q.options.length > 0) {
            const labels = ['A', 'B', 'C', 'D', 'E'];
            q.options.forEach((opt, index) => {
                const isCorrect = opt.is_correct == 1;
                const letter = labels[index] || '-';
                
                let cssClass = 'border-slate-200 bg-white text-slate-700';
                let icon = '';
                
                if (isCorrect) {
                    cssClass = 'border-green-300 bg-green-50 text-green-800 ring-1 ring-green-300';
                    icon = '<i class="ph-bold ph-check-circle text-green-600 text-lg"></i>';
                }

                optsContainer.innerHTML += `
                    <div class="flex items-start gap-3 p-3 rounded-xl border ${cssClass}">
                        <div class="w-6 h-6 rounded bg-slate-100 flex items-center justify-center font-bold text-xs text-slate-500 shrink-0 mt-0.5">${letter}</div>
                        <div class="text-sm font-medium pt-0.5 flex-1">${opt.text}</div>
                        ${icon}
                    </div>
                `;
            });
        } else {
            optsContainer.innerHTML = '<p class="text-sm text-slate-400 italic">Nenhuma alternativa cadastrada.</p>';
        }

        // Abre o modal
        const modal = document.getElementById('detailsModal');
        const content = document.getElementById('detailsModalContent');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setTimeout(() => {
            content.classList.remove('scale-95', 'opacity-0');
        }, 10);
    }

    function closeDetailsModal() {
        const modal = document.getElementById('detailsModal');
        const content = document.getElementById('detailsModalContent');
        content.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }, 300);
    }
</script>

<?php include 'includes/footer.php'; ?>
