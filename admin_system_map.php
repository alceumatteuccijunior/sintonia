<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

require_once 'config.php';
$pdo = getPDOConnection(true);

// Estatísticas vivas da arquitetura para exibir no Mapa do Sistema
$stats = [
    'regionals' => 0,
    'units' => 0,
    'courses' => 0,
    'modules' => 0,
    'questions' => 0,
    'questions_img' => 0,
    'classes' => 0,
    'students_linked' => 0,
    'sprints_total' => 0,
    'sprints_scheduled' => 0,
    'answers_total' => 0,
    'admins' => 0,
    'teachers' => 0,
    'students' => 0
];

try {
    $stats['regionals'] = (int)$pdo->query("SELECT COUNT(*) FROM regionals")->fetchColumn();
    $stats['units'] = (int)$pdo->query("SELECT COUNT(*) FROM units")->fetchColumn();
    $stats['courses'] = (int)$pdo->query("SELECT COUNT(*) FROM courses")->fetchColumn();
    $stats['modules'] = (int)$pdo->query("SELECT COUNT(*) FROM modules")->fetchColumn();
    $stats['questions'] = (int)$pdo->query("SELECT COUNT(*) FROM questions WHERE is_active = 1")->fetchColumn();
    $stats['questions_img'] = (int)$pdo->query("SELECT COUNT(*) FROM questions WHERE is_active = 1 AND image_url IS NOT NULL AND image_url != ''")->fetchColumn();
    $stats['classes'] = (int)$pdo->query("SELECT COUNT(*) FROM classes")->fetchColumn();
    $stats['students_linked'] = (int)$pdo->query("SELECT COUNT(DISTINCT student_id) FROM class_students")->fetchColumn();
    $stats['sprints_total'] = (int)$pdo->query("SELECT COUNT(*) FROM sprints")->fetchColumn();
    $stats['sprints_scheduled'] = (int)$pdo->query("SELECT COUNT(*) FROM sprints WHERE status = 'scheduled'")->fetchColumn();
    $stats['answers_total'] = (int)$pdo->query("SELECT COUNT(*) FROM student_answers")->fetchColumn();

    $roleCounts = $pdo->query("SELECT role, COUNT(*) as total FROM users GROUP BY role")->fetchAll(PDO::FETCH_KEY_PAIR);
    $stats['admins'] = (int)($roleCounts['admin'] ?? 0);
    $stats['teachers'] = (int)($roleCounts['teacher'] ?? 0);
    $stats['students'] = (int)($roleCounts['student'] ?? 0);
} catch (Exception $e) {
    // Fallback silencioso caso alguma tabela opcional ainda não tenha sido migrada
}

$currentPage = 'admin_system_map';
include 'includes/header.php';
?>

<div id="app-screen" class="absolute inset-0 z-40 flex w-full h-full bg-[#F8FAFC]">
    <!-- Fundo Vivo com Parallax -->
    <div class="fixed inset-0 w-full h-full overflow-hidden pointer-events-none z-0">
        <div id="blob1" class="absolute top-[-5%] right-[-5%] w-[500px] h-[500px] bg-senai-cyan/15 rounded-full mix-blend-multiply filter blur-[80px] opacity-70 animate-blob"></div>
        <div id="blob2" class="absolute bottom-[-5%] left-[-5%] w-[600px] h-[600px] bg-senai-orange/10 rounded-full mix-blend-multiply filter blur-[80px] opacity-50 animate-blob"></div>
    </div>

    <?php include 'includes/sidebar.php'; ?>

    <main id="main-content" class="flex-1 flex flex-col relative h-full w-full">
        <!-- Header Top -->
        <header class="h-14 flex items-center justify-between px-4 md:px-6 absolute top-0 w-full z-20 pointer-events-none">
            <button onclick="toggleSidebar()" class="pointer-events-auto w-10 h-10 flex items-center justify-center bg-white/50 hover:bg-white rounded-xl backdrop-blur-md shadow-sm border border-white/60 transition-all active:scale-95 text-senai-dark">
                <i id="sidebar-icon" class="ph-bold ph-list text-xl"></i>
            </button>
            <div class="pointer-events-auto text-[11px] font-bold text-slate-600 flex items-center gap-1.5 bg-white/70 backdrop-blur-xl border border-white/80 shadow-sm px-4 py-2 rounded-full">
                <i class="ph-fill ph-tree-structure text-senai-blue"></i> Arquitetura &amp; Guia Master
            </div>
        </header>

        <div class="flex-1 overflow-y-auto w-full flex flex-col items-center pt-20 pb-24 relative z-10 px-4 md:px-8">
            <div class="w-full max-w-6xl animate-fade-in">

                <!-- Cabeçalho Principal -->
                <div class="flex flex-col md:flex-row justify-between items-start md:items-end mb-8 gap-4">
                    <div>
                        <div class="inline-flex items-center gap-2 px-3 py-1 bg-senai-blue/10 text-senai-blue rounded-full text-xs font-bold tracking-widest uppercase mb-3 border border-senai-blue/20">
                            <i class="ph-fill ph-compass"></i> Documentação Interativa de Governança
                        </div>
                        <h1 class="text-3xl md:text-4xl font-bold tracking-tight mb-2 text-slate-800 flex items-center gap-3">
                            Mapa do Sistema &amp; Guia de Excelência
                        </h1>
                        <p class="text-slate-500 font-medium max-w-3xl">
                            Entenda visualmente como cada módulo conversa, como os relacionamentos de banco de dados operam nos bastidores e como extrair 100% do potencial pedagógico e analítico do <strong>iaS</strong>.
                        </p>
                    </div>
                </div>

                <!-- Navegação por Abas -->
                <div class="flex flex-wrap gap-2 mb-8 bg-white/80 backdrop-blur-md p-2 rounded-2xl border border-slate-200/80 shadow-sm">
                    <button onclick="switchMapTab('tab-fluxo')" id="btn-tab-fluxo" class="map-tab-btn active-tab flex-1 min-w-[180px] py-3 px-4 rounded-xl font-bold text-xs md:text-sm transition-all flex items-center justify-center gap-2 bg-senai-blue text-white shadow-sm">
                        <i class="ph-bold ph-git-merge text-lg"></i> 1. Fluxo Ponta a Ponta
                    </button>
                    <button onclick="switchMapTab('tab-relacionamentos')" id="btn-tab-relacionamentos" class="map-tab-btn flex-1 min-w-[180px] py-3 px-4 rounded-xl font-bold text-xs md:text-sm transition-all flex items-center justify-center gap-2 text-slate-600 hover:bg-slate-100">
                        <i class="ph-bold ph-database text-lg"></i> 2. Relacionamentos &amp; Dados
                    </button>
                    <button onclick="switchMapTab('tab-perfis')" id="btn-tab-perfis" class="map-tab-btn flex-1 min-w-[180px] py-3 px-4 rounded-xl font-bold text-xs md:text-sm transition-all flex items-center justify-center gap-2 text-slate-600 hover:bg-slate-100">
                        <i class="ph-bold ph-users-three text-lg"></i> 3. Interação entre Perfis
                    </button>
                    <button onclick="switchMapTab('tab-playbooks')" id="btn-tab-playbooks" class="map-tab-btn flex-1 min-w-[180px] py-3 px-4 rounded-xl font-bold text-xs md:text-sm transition-all flex items-center justify-center gap-2 text-slate-600 hover:bg-slate-100">
                        <i class="ph-bold ph-rocket-launch text-lg"></i> 4. Como Extrair o Máximo
                    </button>
                </div>

                <!-- ==================== ABA 1: FLUXO PONTA A PONTA ==================== -->
                <div id="tab-fluxo" class="map-tab-content space-y-8">
                    
                    <!-- Resumo de Pulso Vivo -->
                    <div class="glass-panel p-6 rounded-3xl border border-white/90">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-base font-bold text-slate-800 flex items-center gap-2">
                                <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-ping"></span>
                                Pulso Atual do Ecossistema (Dados em Tempo Real)
                            </h2>
                            <span class="text-xs font-semibold text-slate-400">Sincronizado com Fuso de Brasília (UTC-3)</span>
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-3">
                            <div class="bg-slate-50/90 border border-slate-200/60 p-3.5 rounded-2xl text-center">
                                <div class="text-xl font-black text-senai-blue"><?= $stats['regionals'] ?> / <?= $stats['units'] ?></div>
                                <div class="text-[10px] font-bold uppercase text-slate-400 mt-0.5">Regionais / Escolas</div>
                            </div>
                            <div class="bg-slate-50/90 border border-slate-200/60 p-3.5 rounded-2xl text-center">
                                <div class="text-xl font-black text-indigo-600"><?= $stats['courses'] ?> / <?= $stats['modules'] ?></div>
                                <div class="text-[10px] font-bold uppercase text-slate-400 mt-0.5">Cursos / Módulos</div>
                            </div>
                            <div class="bg-slate-50/90 border border-slate-200/60 p-3.5 rounded-2xl text-center">
                                <div class="text-xl font-black text-emerald-600"><?= $stats['questions'] ?> <span class="text-xs font-semibold text-slate-400">(<?= $stats['questions_img'] ?> 📷)</span></div>
                                <div class="text-[10px] font-bold uppercase text-slate-400 mt-0.5">Questões Ativas</div>
                            </div>
                            <div class="bg-slate-50/90 border border-slate-200/60 p-3.5 rounded-2xl text-center">
                                <div class="text-xl font-black text-senai-orange"><?= $stats['classes'] ?></div>
                                <div class="text-[10px] font-bold uppercase text-slate-400 mt-0.5">Turmas Ativas</div>
                            </div>
                            <div class="bg-slate-50/90 border border-slate-200/60 p-3.5 rounded-2xl text-center">
                                <div class="text-xl font-black text-purple-600"><?= $stats['sprints_total'] ?> <span class="text-xs font-semibold text-amber-600">(<?= $stats['sprints_scheduled'] ?> 🗓️)</span></div>
                                <div class="text-[10px] font-bold uppercase text-slate-400 mt-0.5">Sprints / Agendadas</div>
                            </div>
                            <div class="bg-slate-50/90 border border-slate-200/60 p-3.5 rounded-2xl text-center">
                                <div class="text-xl font-black text-cyan-600"><?= $stats['answers_total'] ?></div>
                                <div class="text-[10px] font-bold uppercase text-slate-400 mt-0.5">Respostas Coletadas</div>
                            </div>
                        </div>
                    </div>

                    <!-- A Engrenagem em 7 Etapas -->
                    <div class="glass-panel p-6 md:p-8 rounded-3xl">
                        <div class="mb-6">
                            <h2 class="text-2xl font-bold text-slate-800 mb-1">A Engrenagem do Sistema em 7 Etapas</h2>
                            <p class="text-sm text-slate-500">Para que os relatórios e a inteligência funcionem perfeitamente, as informações fluem na seguinte ordem lógica:</p>
                        </div>

                        <div class="relative space-y-6 before:absolute before:inset-0 before:ml-6 before:-translate-x-px md:before:mx-auto md:before:translate-x-0 before:h-full before:w-0.5 before:bg-gradient-to-b before:from-senai-blue before:via-indigo-400 before:to-senai-orange">
                            
                            <!-- Etapa 1 -->
                            <div class="relative flex items-center justify-between md:justify-normal md:odd:flex-row-reverse group">
                                <div class="flex items-center justify-center w-12 h-12 rounded-2xl border-4 border-white bg-senai-blue text-white font-black shadow-md shrink-0 md:order-1 md:group-odd:-translate-x-1/2 md:group-even:translate-x-1/2 z-10">
                                    1
                                </div>
                                <div class="w-[calc(100%-4rem)] md:w-[calc(50%-2.5rem)] bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm hover:shadow-md transition-all">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-[10px] font-extrabold uppercase tracking-wider px-2.5 py-0.5 rounded-full bg-blue-50 text-senai-blue">Fundação Multi-Tenant</span>
                                        <a href="admin_units.php" class="text-xs font-bold text-senai-blue hover:underline flex items-center gap-1">Acessar <i class="ph-bold ph-arrow-right"></i></a>
                                    </div>
                                    <h3 class="font-bold text-slate-800 text-base mb-1">1. Regionais &amp; Unidades Escolares</h3>
                                    <p class="text-xs text-slate-600 leading-relaxed">
                                        Tudo começa na estrutura física: cada <strong>Regional</strong> agrupa diversas <strong>Unidades Escolares</strong> (ex: SENAI Luzerna). Quando professores e turmas pertencem a uma Unidade, seus dados alimentam automaticamente as estatísticas daquela escola e regional.
                                    </p>
                                </div>
                            </div>

                            <!-- Etapa 2 -->
                            <div class="relative flex items-center justify-between md:justify-normal md:odd:flex-row-reverse group">
                                <div class="flex items-center justify-center w-12 h-12 rounded-2xl border-4 border-white bg-indigo-600 text-white font-black shadow-md shrink-0 md:order-1 md:group-odd:-translate-x-1/2 md:group-even:translate-x-1/2 z-10">
                                    2
                                </div>
                                <div class="w-[calc(100%-4rem)] md:w-[calc(50%-2.5rem)] bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm hover:shadow-md transition-all">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-[10px] font-extrabold uppercase tracking-wider px-2.5 py-0.5 rounded-full bg-indigo-50 text-indigo-600">Espinha Dorsal Pedagógica</span>
                                        <a href="questions.php" class="text-xs font-bold text-indigo-600 hover:underline flex items-center gap-1">Acessar <i class="ph-bold ph-arrow-right"></i></a>
                                    </div>
                                    <h3 class="font-bold text-slate-800 text-base mb-1">2. Cursos, Módulos (UCs) &amp; Capacidades</h3>
                                    <p class="text-xs text-slate-600 leading-relaxed">
                                        Cada <strong>Curso</strong> possui seus <strong>Módulos / Unidades Curriculares</strong> e cada questão aponta para uma <strong>Capacidade (ex: C1, C2, H3)</strong>. É esse vínculo que permite ao sistema descobrir exatamente qual competência técnica a turma ainda não domina.
                                    </p>
                                </div>
                            </div>

                            <!-- Etapa 3 -->
                            <div class="relative flex items-center justify-between md:justify-normal md:odd:flex-row-reverse group">
                                <div class="flex items-center justify-center w-12 h-12 rounded-2xl border-4 border-white bg-emerald-600 text-white font-black shadow-md shrink-0 md:order-1 md:group-odd:-translate-x-1/2 md:group-even:translate-x-1/2 z-10">
                                    3
                                </div>
                                <div class="w-[calc(100%-4rem)] md:w-[calc(50%-2.5rem)] bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm hover:shadow-md transition-all">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-[10px] font-extrabold uppercase tracking-wider px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700">Acervo Técnico &amp; Mídia</span>
                                        <a href="questions_view.php" class="text-xs font-bold text-emerald-600 hover:underline flex items-center gap-1">Ver Acervo <i class="ph-bold ph-arrow-right"></i></a>
                                    </div>
                                    <h3 class="font-bold text-slate-800 text-base mb-1">3. Banco de Questões, Tags &amp; Imagens de Apoio</h3>
                                    <p class="text-xs text-slate-600 leading-relaxed">
                                        As questões podem ser criadas individualmente ou importadas em massa via CSV com uma <strong>Tag/Lote</strong> (ex: <code>SAEP2026</code>). Questões que exigem esquemas elétricos, plantas ou gráficos recebem <strong>Imagens de Apoio</strong> (no pós-importação ou na listagem do banco).
                                    </p>
                                </div>
                            </div>

                            <!-- Etapa 4 -->
                            <div class="relative flex items-center justify-between md:justify-normal md:odd:flex-row-reverse group">
                                <div class="flex items-center justify-center w-12 h-12 rounded-2xl border-4 border-white bg-cyan-600 text-white font-black shadow-md shrink-0 md:order-1 md:group-odd:-translate-x-1/2 md:group-even:translate-x-1/2 z-10">
                                    4
                                </div>
                                <div class="w-[calc(100%-4rem)] md:w-[calc(50%-2.5rem)] bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm hover:shadow-md transition-all">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-[10px] font-extrabold uppercase tracking-wider px-2.5 py-0.5 rounded-full bg-cyan-50 text-cyan-700">Gestão de Pessoas</span>
                                        <div class="flex gap-2">
                                            <a href="classes.php" class="text-xs font-bold text-cyan-600 hover:underline">Turmas</a>
                                            <span class="text-slate-300">|</span>
                                            <a href="admin_users.php" class="text-xs font-bold text-cyan-600 hover:underline">Usuários</a>
                                        </div>
                                    </div>
                                    <h3 class="font-bold text-slate-800 text-base mb-1">4. Turmas, Professores e Alunos</h3>
                                    <p class="text-xs text-slate-600 leading-relaxed">
                                        As <strong>Turmas</strong> conectam um Curso a uma Unidade Escolar. Os <strong>Alunos</strong> são vinculados às turmas de 3 maneiras possíveis: via <strong>CSV de 3 colunas</strong> (<code>Nome,Email,Turma</code>), pelo <strong>campo suspenso na edição do usuário</strong>, ou no primeiro acesso do estudante.
                                    </p>
                                </div>
                            </div>

                            <!-- Etapa 5 -->
                            <div class="relative flex items-center justify-between md:justify-normal md:odd:flex-row-reverse group">
                                <div class="flex items-center justify-center w-12 h-12 rounded-2xl border-4 border-white bg-amber-500 text-white font-black shadow-md shrink-0 md:order-1 md:group-odd:-translate-x-1/2 md:group-even:translate-x-1/2 z-10">
                                    5
                                </div>
                                <div class="w-[calc(100%-4rem)] md:w-[calc(50%-2.5rem)] bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm hover:shadow-md transition-all">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-[10px] font-extrabold uppercase tracking-wider px-2.5 py-0.5 rounded-full bg-amber-50 text-amber-700">Planejamento &amp; Governança</span>
                                        <div class="flex gap-2">
                                            <a href="sprint_create.php" class="text-xs font-bold text-amber-600 hover:underline">Criar Sprint</a>
                                            <span class="text-slate-300">|</span>
                                            <a href="agenda.php" class="text-xs font-bold text-amber-600 hover:underline">Agenda</a>
                                        </div>
                                    </div>
                                    <h3 class="font-bold text-slate-800 text-base mb-1">5. Sprints &amp; Agenda Interativa</h3>
                                    <p class="text-xs text-slate-600 leading-relaxed">
                                        O professor ou admin cria a <strong>Sprint</strong> escolhendo a turma, o tempo limite, o tempo mínimo obrigatório e a janela de aplicação (agendada ou imediata). Todas as provas aparecem na <strong>Agenda Mensal</strong> para acompanhamento da coordenação e dos docentes.
                                    </p>
                                </div>
                            </div>

                            <!-- Etapa 6 -->
                            <div class="relative flex items-center justify-between md:justify-normal md:odd:flex-row-reverse group">
                                <div class="flex items-center justify-center w-12 h-12 rounded-2xl border-4 border-white bg-purple-600 text-white font-black shadow-md shrink-0 md:order-1 md:group-odd:-translate-x-1/2 md:group-even:translate-x-1/2 z-10">
                                    6
                                </div>
                                <div class="w-[calc(100%-4rem)] md:w-[calc(50%-2.5rem)] bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm hover:shadow-md transition-all">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-[10px] font-extrabold uppercase tracking-wider px-2.5 py-0.5 rounded-full bg-purple-50 text-purple-700">Aplicação Blindada &amp; Gamificação</span>
                                        <span class="text-[11px] font-semibold text-slate-400">Visão Estudante</span>
                                    </div>
                                    <h3 class="font-bold text-slate-800 text-base mb-1">6. Resolução com Controle Anti-Cola &amp; Devolutiva</h3>
                                    <p class="text-xs text-slate-600 leading-relaxed">
                                        No horário agendado, a prova é liberada automaticamente. O aluno responde com zoom nas imagens técnicas, sob monitoramento de foco de tela (máx. 3 saídas de aba) e trava de leitura mínima. Ao entregar, ganha XP no ranking e recebe seu boletim PDF por e-mail.
                                    </p>
                                </div>
                            </div>

                            <!-- Etapa 7 -->
                            <div class="relative flex items-center justify-between md:justify-normal md:odd:flex-row-reverse group">
                                <div class="flex items-center justify-center w-12 h-12 rounded-2xl border-4 border-white bg-senai-orange text-white font-black shadow-md shrink-0 md:order-1 md:group-odd:-translate-x-1/2 md:group-even:translate-x-1/2 z-10">
                                    7
                                </div>
                                <div class="w-[calc(100%-4rem)] md:w-[calc(50%-2.5rem)] bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm hover:shadow-md transition-all">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-[10px] font-extrabold uppercase tracking-wider px-2.5 py-0.5 rounded-full bg-orange-50 text-senai-orange">Inteligência &amp; Intervenção</span>
                                        <a href="admin_dashboard.php" class="text-xs font-bold text-senai-orange hover:underline flex items-center gap-1">Visão Global <i class="ph-bold ph-arrow-right"></i></a>
                                    </div>
                                    <h3 class="font-bold text-slate-800 text-base mb-1">7. Raio-X Analítico, Slides em Sala &amp; Planos de Aula IA</h3>
                                    <p class="text-xs text-slate-600 leading-relaxed">
                                        As respostas alimentam o <strong>Raio-X da Sprint</strong>, o <strong>Histórico Individual do Aluno</strong> e a <strong>Visão Global</strong>. Com 1 clique, o professor projeta os <strong>Slides de Devolutiva</strong> para debater as questões mais erradas em sala e aciona a <strong>IA</strong> para gerar planos de aula corretivos.
                                    </p>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- ==================== ABA 2: RELACIONAMENTOS & BANCO DE DADOS ==================== -->
                <div id="tab-relacionamentos" class="map-tab-content hidden space-y-6">
                    <div class="glass-panel p-6 md:p-8 rounded-3xl">
                        <h2 class="text-2xl font-bold text-slate-800 mb-2 flex items-center gap-2">
                            <i class="ph-fill ph-Share-network text-senai-blue"></i> Mapa de Relacionamentos de Entidades
                        </h2>
                        <p class="text-sm text-slate-500 mb-6">
                            Veja exatamente como as tabelas do banco de dados conversam entre si e quais regras garantem que nenhum histórico seja perdido:
                        </p>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Bloco 1: Hierarquia Organizacional -->
                            <div class="bg-white rounded-2xl p-6 border border-slate-200/80 shadow-sm">
                                <div class="flex items-center gap-3 mb-4">
                                    <div class="w-10 h-10 rounded-xl bg-blue-50 text-senai-blue flex items-center justify-center">
                                        <i class="ph-bold ph-buildings text-xl"></i>
                                    </div>
                                    <div>
                                        <h3 class="font-bold text-slate-800">1. Hierarquia Organizacional (Multi-Tenant)</h3>
                                        <span class="text-[11px] text-slate-400 font-mono">regionals ➔ units ➔ classes / users</span>
                                    </div>
                                </div>
                                <div class="space-y-3 text-xs text-slate-600">
                                    <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                                        <strong class="text-slate-800">Como conversa:</strong> Uma <code>Regional</code> possui várias <code>Unidades Escolares (units)</code>. Tanto os <code>Usuários</code> quanto as <code>Turmas (classes)</code> têm uma chave <code>unit_id</code>.
                                    </div>
                                    <div class="p-3 bg-blue-50/50 rounded-xl border border-blue-100 text-blue-900">
                                        <i class="ph-fill ph-sparkle text-senai-blue"></i> <strong>Automação Inteligente:</strong> Quando você vincula um aluno a uma turma (via CSV ou edição) ou transfere uma turma de unidade escolar, o sistema sincroniza automaticamente o <code>unit_id</code> dos alunos daquela turma!
                                    </div>
                                </div>
                            </div>

                            <!-- Bloco 2: Hierarquia Curricular -->
                            <div class="bg-white rounded-2xl p-6 border border-slate-200/80 shadow-sm">
                                <div class="flex items-center gap-3 mb-4">
                                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                                        <i class="ph-bold ph-books text-xl"></i>
                                    </div>
                                    <div>
                                        <h3 class="font-bold text-slate-800">2. Hierarquia Curricular &amp; Acervo</h3>
                                        <span class="text-[11px] text-slate-400 font-mono">courses ➔ modules ➔ questions ➔ question_options</span>
                                    </div>
                                </div>
                                <div class="space-y-3 text-xs text-slate-600">
                                    <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                                        <strong class="text-slate-800">Como conversa:</strong> Cada <code>Curso</code> agrupa vários <code>Módulos</code>. Cada <code>Questão</code> pertence a um Módulo, carrega uma <code>Capacidade</code> (ex: C1), uma <code>Tag de Lote</code> (<code>import_tag</code>), uma imagem opcional (<code>image_url</code>) e possui suas alternativas em <code>question_options</code>.
                                    </div>
                                    <div class="p-3 bg-emerald-50/60 rounded-xl border border-emerald-100 text-emerald-900">
                                        <i class="ph-fill ph-shield-check text-emerald-600"></i> <strong>Proteção Histórica (Soft Delete):</strong> Quando uma questão é excluída pelo painel, ela recebe <code>is_active = 0</code>. Assim, ela não aparece em provas futuras, mas <strong>jamais quebra as notas de provas passadas</strong>!
                                    </div>
                                </div>
                            </div>

                            <!-- Bloco 3: Vínculo Turma x Aluno -->
                            <div class="bg-white rounded-2xl p-6 border border-slate-200/80 shadow-sm">
                                <div class="flex items-center gap-3 mb-4">
                                    <div class="w-10 h-10 rounded-xl bg-cyan-50 text-cyan-600 flex items-center justify-center">
                                        <i class="ph-bold ph-student text-xl"></i>
                                    </div>
                                    <div>
                                        <h3 class="font-bold text-slate-800">3. Matrícula e Vínculo de Turmas</h3>
                                        <span class="text-[11px] text-slate-400 font-mono">users (student) ⟷ class_students ⟷ classes</span>
                                    </div>
                                </div>
                                <div class="space-y-3 text-xs text-slate-600">
                                    <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                                        <strong class="text-slate-800">Como conversa:</strong> A tabela pivô <code>class_students</code> liga cada aluno (<code>student_id</code>) à sua turma (<code>class_id</code>). É através dela que o painel do estudante sabe quais Sprints exibir.
                                    </div>
                                    <div class="p-3 bg-cyan-50/50 rounded-xl border border-cyan-100 text-cyan-900">
                                        <i class="ph-fill ph-arrows-left-right text-cyan-600"></i> <strong>Fallback de Segurança:</strong> Se um aluno for cadastrado sem turma, no primeiro login o sistema o direciona automaticamente para <code>student_setup.php</code> para que ele escolha sua turma sem travar o acesso.
                                    </div>
                                </div>
                            </div>

                            <!-- Bloco 4: Motor de Sprints e Resultados -->
                            <div class="bg-white rounded-2xl p-6 border border-slate-200/80 shadow-sm">
                                <div class="flex items-center gap-3 mb-4">
                                    <div class="w-10 h-10 rounded-xl bg-orange-50 text-senai-orange flex items-center justify-center">
                                        <i class="ph-bold ph-exam text-xl"></i>
                                    </div>
                                    <div>
                                        <h3 class="font-bold text-slate-800">4. Motor de Sprints, Tentativas e Respostas</h3>
                                        <span class="text-[11px] text-slate-400 font-mono">sprints ➔ sprint_questions / sprint_attempts / student_answers</span>
                                    </div>
                                </div>
                                <div class="space-y-3 text-xs text-slate-600">
                                    <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                                        <strong class="text-slate-800">Como conversa:</strong>
                                        <ul class="list-disc pl-4 mt-1 space-y-1">
                                            <li><code>sprint_questions</code>: Guarda quais questões compõem a prova.</li>
                                            <li><code>sprint_attempts</code>: Controla o cronômetro individual (<code>started_at</code> e <code>completed_at</code>).</li>
                                            <li><code>student_answers</code>: Registra cada alternativa marcada e se acertou (<code>is_correct</code>).</li>
                                        </ul>
                                    </div>
                                    <div class="p-3 bg-amber-50/60 rounded-xl border border-amber-200 text-amber-900">
                                        <i class="ph-fill ph-clock-countdown text-amber-600"></i> <strong>Ciclo de Tempo Inteligente:</strong> Se uma prova tem horário de término (<code>end_time</code>), alunos que ainda não começaram são bloqueados após o prazo, mas <strong>alunos que já estão com a prova aberta podem terminar seu tempo normalmente</strong> sem perder as respostas.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ==================== ABA 3: INTERAÇÃO ENTRE PERFIS ==================== -->
                <div id="tab-perfis" class="map-tab-content hidden space-y-6">
                    <div class="glass-panel p-6 md:p-8 rounded-3xl">
                        <h2 class="text-2xl font-bold text-slate-800 mb-2">Como os 3 Perfis de Usuário Interagem</h2>
                        <p class="text-sm text-slate-500 mb-6">
                            Cada ação tomada em um perfil reflete em tempo real nos demais níveis da plataforma:
                        </p>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <!-- Perfil Admin -->
                            <div class="bg-white rounded-3xl p-6 border-2 border-senai-orange/30 shadow-sm flex flex-col">
                                <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-senai-orange/10 text-senai-orange text-xs font-bold w-max mb-4">
                                    <i class="ph-fill ph-shield-star"></i> Administrador (<?= $stats['admins'] ?> ativos)
                                </div>
                                <h3 class="text-lg font-bold text-slate-800 mb-2">Governança &amp; Visão Macro</h3>
                                <ul class="space-y-2.5 text-xs text-slate-600 flex-1">
                                    <li class="flex items-start gap-2"><i class="ph-bold ph-check text-senai-orange mt-0.5"></i> Cadastra Regionais, Escolas, Cursos e Módulos.</li>
                                    <li class="flex items-start gap-2"><i class="ph-bold ph-check text-senai-orange mt-0.5"></i> Importa alunos em massa via CSV já atribuindo Turma e Unidade.</li>
                                    <li class="flex items-start gap-2"><i class="ph-bold ph-check text-senai-orange mt-0.5"></i> Visualiza na <strong>Agenda Global</strong> todas as provas de todas as escolas e pode reagendar ou atribuir Sprints para qualquer professor.</li>
                                    <li class="flex items-start gap-2"><i class="ph-bold ph-check text-senai-orange mt-0.5"></i> Analisa indicadores estaduais/regionais e importa planilhas oficiais do SAEP.</li>
                                </ul>
                            </div>

                            <!-- Perfil Professor -->
                            <div class="bg-white rounded-3xl p-6 border-2 border-senai-blue/30 shadow-sm flex flex-col">
                                <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-senai-blue/10 text-senai-blue text-xs font-bold w-max mb-4">
                                    <i class="ph-fill ph-chalkboard-teacher"></i> Professor (<?= $stats['teachers'] ?> ativos)
                                </div>
                                <h3 class="text-lg font-bold text-slate-800 mb-2">Gestão de Sala &amp; Diagnóstico</h3>
                                <ul class="space-y-2.5 text-xs text-slate-600 flex-1">
                                    <li class="flex items-start gap-2"><i class="ph-bold ph-check text-senai-blue mt-0.5"></i> Alimenta o Banco de Questões (manual ou CSV) e anexa imagens técnicas.</li>
                                    <li class="flex items-start gap-2"><i class="ph-bold ph-check text-senai-blue mt-0.5"></i> Cria e agenda Sprints para suas turmas com alerta de questões já aplicadas anteriormente.</li>
                                    <li class="flex items-start gap-2"><i class="ph-bold ph-check text-senai-blue mt-0.5"></i> Acompanha em tempo real quem está fazendo a prova e quem finalizou.</li>
                                    <li class="flex items-start gap-2"><i class="ph-bold ph-check text-senai-blue mt-0.5"></i> Exporta provas para Word (.doc), projeta Slides de correção em sala e usa o Tutor IA para planos de aula.</li>
                                </ul>
                            </div>

                            <!-- Perfil Aluno -->
                            <div class="bg-white rounded-3xl p-6 border-2 border-emerald-500/30 shadow-sm flex flex-col">
                                <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-50 text-emerald-700 text-xs font-bold w-max mb-4">
                                    <i class="ph-fill ph-student"></i> Aluno (<?= $stats['students'] ?> ativos)
                                </div>
                                <h3 class="text-lg font-bold text-slate-800 mb-2">Execução &amp; Evolução Gamificada</h3>
                                <ul class="space-y-2.5 text-xs text-slate-600 flex-1">
                                    <li class="flex items-start gap-2"><i class="ph-bold ph-check text-emerald-600 mt-0.5"></i> Recebe senha provisória por e-mail e define senha pessoal no 1º acesso.</li>
                                    <li class="flex items-start gap-2"><i class="ph-bold ph-check text-emerald-600 mt-0.5"></i> Visualiza no Dashboard as provas agendadas (com proteção anti-vazamento até o minuto de início) e as provas liberadas.</li>
                                    <li class="flex items-start gap-2"><i class="ph-bold ph-check text-emerald-600 mt-0.5"></i> Responde às questões com zoom em alta definição para diagramas e esquemas técnicos.</li>
                                    <li class="flex items-start gap-2"><i class="ph-bold ph-check text-emerald-600 mt-0.5"></i> Recebe devolutiva em PDF no e-mail e sobe de nível (XP) no ranking da turma.</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ==================== ABA 4: PLAYBOOKS DE EXCELÊNCIA ==================== -->
                <div id="tab-playbooks" class="map-tab-content hidden space-y-6">
                    <div class="glass-panel p-6 md:p-8 rounded-3xl">
                        <h2 class="text-2xl font-bold text-slate-800 mb-2 flex items-center gap-2">
                            <i class="ph-fill ph-trophy text-amber-500"></i> 5 Roteiros Práticos para Extrair 100% do Sistema
                        </h2>
                        <p class="text-sm text-slate-500 mb-6">
                            Estratégias recomendadas pela arquitetura do sistema para máxima produtividade da equipe pedagógica e administrativa:
                        </p>

                        <div class="space-y-4">
                            <!-- Playbook 1 -->
                            <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm">
                                <div class="flex flex-col md:flex-row md:items-center justify-between gap-2 mb-3">
                                    <h3 class="font-bold text-base text-slate-800 flex items-center gap-2">
                                        <span class="w-7 h-7 rounded-lg bg-indigo-600 text-white text-xs font-black flex items-center justify-center">01</span>
                                        Uso Estratégico de Tags de Lote no Banco de Questões
                                    </h3>
                                    <span class="text-[11px] font-bold text-indigo-600 bg-indigo-50 px-3 py-1 rounded-full w-max">Economiza 80% do tempo na criação de provas</span>
                                </div>
                                <p class="text-xs text-slate-600 leading-relaxed">
                                    Ao importar questões via CSV em <a href="questions.php" class="text-indigo-600 font-bold underline">Banco de Questões</a>, sempre preencha o campo <strong>Tag de Identificação do Lote</strong> com um código padronizado (ex: <code>SIMULADO_SAEP_2026_1</code> ou <code>UC_ELETRO_MOD2</code>).
                                    Assim, quando o professor for montar uma Sprint em <a href="sprint_create.php" class="text-indigo-600 font-bold underline">Nova Sprint</a>, basta selecionar essa Tag no filtro para localizar instantaneamente apenas as questões daquele simulado, e logo após a importação o sistema já permite vincular imagens de circuitos/tabelas às questões daquele lote!
                                </p>
                            </div>

                            <!-- Playbook 2 -->
                            <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm">
                                <div class="flex flex-col md:flex-row md:items-center justify-between gap-2 mb-3">
                                    <h3 class="font-bold text-base text-slate-800 flex items-center gap-2">
                                        <span class="w-7 h-7 rounded-lg bg-senai-blue text-white text-xs font-black flex items-center justify-center">02</span>
                                        Planejamento Antecipado pela Agenda Institucional
                                    </h3>
                                    <span class="text-[11px] font-bold text-senai-blue bg-blue-50 px-3 py-1 rounded-full w-max">Governança Pedagógica</span>
                                </div>
                                <p class="text-xs text-slate-600 leading-relaxed">
                                    A coordenação pode criar todas as Sprints do mês com antecedência para os professores em <a href="sprint_create.php" class="text-senai-blue font-bold underline">Nova Sprint</a> definindo a <strong>Data/Hora de Início</strong> e o <strong>Professor Responsável</strong>.
                                    Os alunos verão que a prova está agendada para o dia marcado, mas não terão acesso às questões antes do minuto exato. Pela tela <a href="agenda.php" class="text-senai-blue font-bold underline">Agenda</a>, você filtra por Unidade, Curso ou Docente e pode reagendar qualquer avaliação com 1 clique caso o calendário escolar mude.
                                </p>
                            </div>

                            <!-- Playbook 3 -->
                            <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm">
                                <div class="flex flex-col md:flex-row md:items-center justify-between gap-2 mb-3">
                                    <h3 class="font-bold text-base text-slate-800 flex items-center gap-2">
                                        <span class="w-7 h-7 rounded-lg bg-emerald-600 text-white text-xs font-black flex items-center justify-center">03</span>
                                        Blindagem Contra "Chutes Rápidos" e Cola Digital
                                    </h3>
                                    <span class="text-[11px] font-bold text-emerald-700 bg-emerald-50 px-3 py-1 rounded-full w-max">Confiabilidade de Dados</span>
                                </div>
                                <p class="text-xs text-slate-600 leading-relaxed">
                                    Para garantir que o diagnóstico reflita o conhecimento real da turma, configure sempre o <strong>Tempo Mínimo de Entrega</strong> (ex: 15 ou 20 minutos em uma prova de 60 minutos). O botão de enviar respostas ficará bloqueado até que o aluno dedique esse tempo mínimo de leitura. Somado ao detector de mudança de aba (que encerra a prova após 3 tentativas de sair da tela), você elimina chutes aleatórios e pesquisas externas.
                                </p>
                            </div>

                            <!-- Playbook 4 -->
                            <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm">
                                <div class="flex flex-col md:flex-row md:items-center justify-between gap-2 mb-3">
                                    <h3 class="font-bold text-base text-slate-800 flex items-center gap-2">
                                        <span class="w-7 h-7 rounded-lg bg-senai-orange text-white text-xs font-black flex items-center justify-center">04</span>
                                        Rito Pós-Prova: Raio-X ➔ Slides de Correção ➔ Plano de Aula IA
                                    </h3>
                                    <span class="text-[11px] font-bold text-senai-orange bg-orange-50 px-3 py-1 rounded-full w-max">Transformando Nota em Aprendizagem</span>
                                </div>
                                <p class="text-xs text-slate-600 leading-relaxed">
                                    Logo após o término de uma Sprint, abra o <strong>Relatório da Sprint (Raio-X)</strong>:
                                    <br>1. Clique em <strong>Apresentação em Slides</strong> para projetar no quadro da sala as questões com maior índice de erro e debatê-las com a turma;
                                    <br>2. Identifique a Capacidade mais crítica no gráfico e clique em <strong>Gerar Plano de Aula com IA</strong> para receber uma dinâmica prática de recuperação;
                                    <br>3. Caso algum aluno não tenha computador no dia, use o botão <strong>Exportar para Word (.doc)</strong> — a prova já sai formatada para impressão com todas as imagens incorporadas.
                                </p>
                            </div>

                            <!-- Playbook 5 -->
                            <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm">
                                <div class="flex flex-col md:flex-row md:items-center justify-between gap-2 mb-3">
                                    <h3 class="font-bold text-base text-slate-800 flex items-center gap-2">
                                        <span class="w-7 h-7 rounded-lg bg-cyan-600 text-white text-xs font-black flex items-center justify-center">05</span>
                                        Virada de Semestre e Transferência Rápida de Alunos
                                    </h3>
                                    <span class="text-[11px] font-bold text-cyan-700 bg-cyan-50 px-3 py-1 rounded-full w-max">Gestão de Secretaria em Segundos</span>
                                </div>
                                <p class="text-xs text-slate-600 leading-relaxed">
                                    Em <a href="admin_users.php" class="text-cyan-600 font-bold underline">Gestão de Usuários</a>, você pode matricular dezenas de turmas de uma só vez subindo a planilha CSV com 3 colunas (<code>Nome,Email,Turma</code>). E se alunos que já existem no sistema mudarem de turma no semestre seguinte, basta subir a nova planilha CSV com a turma nova (ou alterar diretamente no campo suspenso de edição do aluno) — o sistema transfere o aluno mantendo todo o seu histórico de evolução intacto!
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>
</div>

<script>
    function switchMapTab(tabId) {
        // Esconder todos os conteúdos
        document.querySelectorAll('.map-tab-content').forEach(el => {
            el.classList.add('hidden');
        });
        // Resetar todos os botões
        document.querySelectorAll('.map-tab-btn').forEach(btn => {
            btn.classList.remove('bg-senai-blue', 'text-white', 'shadow-sm');
            btn.classList.add('text-slate-600', 'hover:bg-slate-100');
        });

        // Mostrar aba selecionada
        const target = document.getElementById(tabId);
        if (target) target.classList.remove('hidden');

        // Destacar botão ativo
        const activeBtn = document.getElementById('btn-' + tabId);
        if (activeBtn) {
            activeBtn.classList.remove('text-slate-600', 'hover:bg-slate-100');
            activeBtn.classList.add('bg-senai-blue', 'text-white', 'shadow-sm');
        }
    }
</script>

<?php include 'includes/footer.php'; ?>
