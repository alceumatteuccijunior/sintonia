<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'teacher'])) {
    header("Location: index.php");
    exit;
}
require_once 'config.php';
$pdo = getPDOConnection(true);

$stmtClasses = $pdo->query("SELECT id, name, year, semester FROM classes ORDER BY year DESC, semester DESC, name ASC");
$classes = $stmtClasses->fetchAll();

$teachers = [];
if ($_SESSION['user_role'] === 'admin') {
    $stmtT = $pdo->query("SELECT id, name FROM users WHERE role IN ('teacher', 'admin') ORDER BY name ASC");
    $teachers = $stmtT->fetchAll();
}

include 'includes/header.php';
?>

<div id="app-screen" class="absolute inset-0 z-40 flex w-full h-full">
    <!-- Fundo Vivo -->
    <div class="fixed inset-0 w-full h-full overflow-hidden pointer-events-none z-0 bg-[#F8FAFC]">
        <div id="blob1" class="absolute top-[-5%] right-[-5%] w-[500px] h-[500px] bg-senai-cyan/15 rounded-full mix-blend-multiply filter blur-[80px] opacity-70 animate-blob"></div>
        <div id="blob3" class="absolute top-[25%] left-[25%] w-[400px] h-[400px] bg-senai-blue/10 rounded-full mix-blend-multiply filter blur-[80px] opacity-50 animate-blob"></div>
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
            <div class="w-full max-w-2xl">
                
                <!-- Progresso Simulado -->
                <div class="flex items-center justify-center mb-8 gap-3 opacity-80">
                    <div class="flex items-center gap-2 text-senai-blue font-bold text-sm">
                        <div class="w-6 h-6 rounded-full bg-senai-blue text-white flex items-center justify-center text-xs">1</div>
                        Dados Base
                    </div>
                    <div class="w-12 h-px bg-slate-300"></div>
                    <div class="flex items-center gap-2 text-slate-400 font-bold text-sm">
                        <div class="w-6 h-6 rounded-full bg-slate-200 flex items-center justify-center text-xs">2</div>
                        Questões
                    </div>
                </div>

                <div class="mb-8 animate-slide-up text-center flex flex-col items-center justify-center">
                    <h1 class="text-3xl font-bold tracking-tight mb-2 text-slate-800 flex items-center gap-3">
                        Nova Sprint (Etapa 1)
                        <button onclick="openHelpModal('Criar Nova Avaliação (Passo 1)', 'Para aplicar uma prova (Sprint), primeiro escolha a Turma e defina um Nome.<br><br><b>Tempo Limite:</b> Defina o tempo em minutos. Se você quiser que a prova tenha tempo <b>ilimitado</b>, deixe o valor em <b>0</b>.<br><br>Após preencher, clique em Avançar para selecionar as questões.')" class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 hover:text-senai-blue hover:bg-blue-50 transition-colors shadow-inner" title="Como Usar">
                            <i class="ph-bold ph-question text-lg"></i>
                        </button>
                    </h1>
                    <p class="text-slate-500 font-medium">Defina a turma, o nome e o tempo limite da avaliação.</p>
                </div>

                <?php if (empty($classes)): ?>
                    <div class="bg-yellow-50 text-yellow-700 p-6 rounded-2xl border border-yellow-200 text-center shadow-sm">
                        <i class="ph-fill ph-warning-circle text-4xl mb-3"></i>
                        <h3 class="text-lg font-bold mb-1">Nenhuma turma cadastrada</h3>
                        <p class="text-sm font-medium mb-4">Você precisa criar pelo menos uma turma antes de gerar uma Sprint.</p>
                        <a href="class_create.php" class="inline-block bg-white px-5 py-2 rounded-xl text-yellow-700 font-bold shadow-sm hover:shadow transition-all">Ir para Turmas</a>
                    </div>
                <?php else: ?>
                    <form method="POST" action="sprint_select_questions.php" class="bg-white/80 backdrop-blur-md border border-white/80 rounded-3xl p-8 shadow-sm animate-fade-in delay-200">
                        <div class="space-y-6">
                            
                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-2">Turma Alvo</label>
                                <select name="class_id" required class="search-select w-full px-5 py-3.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-senai-blue/20 focus:border-senai-blue transition-all bg-white/50 text-slate-800 font-medium">
                                    <option value="" disabled selected>Selecione a turma...</option>
                                    <?php foreach ($classes as $c): ?>
                                        <option value="<?= $c['id'] ?>">
                                            <?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['year'] . '/' . $c['semester']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-2">Nome da Sprint</label>
                                <input type="text" name="name" required placeholder="Ex: Avaliação Diagnóstica - Módulo 1" class="w-full px-5 py-3.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-senai-blue/20 focus:border-senai-blue transition-all bg-white/50 text-slate-800 font-medium placeholder:text-slate-400">
                            </div>

                            <?php if ($_SESSION['user_role'] === 'admin'): ?>
                            <div class="p-5 bg-indigo-50 border border-indigo-100 rounded-2xl">
                                <label class="block text-sm font-bold text-indigo-800 mb-2"><i class="ph-bold ph-chalkboard-teacher"></i> Professor Responsável</label>
                                <p class="text-[11px] text-indigo-600/70 mb-3 font-medium">Como administrador, você pode atribuir esta Sprint a outro professor.</p>
                                <select name="teacher_id" class="search-select w-full px-5 py-3.5 rounded-xl border border-indigo-200 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all bg-white text-indigo-900 font-medium">
                                    <option value="<?= $_SESSION['user_id'] ?>">Atribuir a mim mesmo</option>
                                    <?php foreach ($teachers as $t): ?>
                                        <?php if ($t['id'] != $_SESSION['user_id']): ?>
                                            <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-2">Tempo Limite (Máximo)</label>
                                    <div class="relative">
                                        <input type="number" name="time_limit_minutes" required value="60" min="5" max="300" class="w-full px-5 py-3.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-senai-blue/20 focus:border-senai-blue transition-all bg-white/50 text-slate-800 font-medium">
                                        <div class="absolute inset-y-0 right-5 flex items-center pointer-events-none">
                                            <span class="text-slate-400 font-bold text-sm">minutos</span>
                                        </div>
                                    </div>
                                    <p class="text-[11px] text-slate-400 font-semibold mt-2 ml-1">
                                        Tempo que o aluno terá para concluir a avaliação.
                                    </p>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-2">Tempo Mínimo <span class="text-slate-400 font-normal">(Opcional)</span></label>
                                    <div class="relative">
                                        <input type="number" name="time_min_minutes" value="" min="0" max="300" placeholder="Ex: 10" class="w-full px-5 py-3.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-senai-blue/20 focus:border-senai-blue transition-all bg-white/50 text-slate-800 font-medium">
                                        <div class="absolute inset-y-0 right-5 flex items-center pointer-events-none">
                                            <span class="text-slate-400 font-bold text-sm">minutos</span>
                                        </div>
                                    </div>
                                    <p class="text-[11px] text-slate-400 font-semibold mt-2 ml-1">
                                        Obriga o aluno a aguardar esse tempo antes de finalizar (garante leitura).
                                    </p>
                                </div>
                            </div>

                            <div class="pt-4 mt-8 border-t border-slate-100 flex justify-end">
                                <button type="submit" class="bg-senai-blue text-white px-8 py-3.5 rounded-xl font-semibold shadow-[0_4px_15px_-3px_rgba(26,66,138,0.4)] hover:shadow-[0_8px_20px_-3px_rgba(26,66,138,0.5)] hover:-translate-y-0.5 transition-all flex items-center gap-2 group">
                                    Avançar para Questões <i class="ph-bold ph-arrow-right group-hover:translate-x-1 transition-transform"></i>
                                </button>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<?php include 'includes/footer.php'; ?>
