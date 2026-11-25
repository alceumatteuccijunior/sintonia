<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
require_once 'config.php';
$pdo = getPDOConnection(true);

$unit_id = ($_SESSION['user_role'] === 'admin') ? null : ($_SESSION['unit_id'] ?? null);
$where_unit = $unit_id ? "WHERE unit_id = ?" : "";
$params_unit = $unit_id ? [$unit_id] : [];

// Contadores dinâmicos do resumo
$total_questions = (int)$pdo->query("SELECT COUNT(*) FROM questions WHERE is_active = 1 OR is_active IS NULL")->fetchColumn();

$stmtClassesCount = $pdo->prepare("SELECT COUNT(*) FROM classes $where_unit");
$stmtClassesCount->execute($params_unit);
$total_classes = (int)$stmtClassesCount->fetchColumn();

$base_sprint_filter = "WHERE teacher_id IS NOT NULL AND name NOT LIKE 'DUELO|%' AND name NOT LIKE 'Missão de Treinamento%'";
$where_sprint = $unit_id ? "$base_sprint_filter AND class_id IN (SELECT id FROM classes WHERE unit_id = ?)" : $base_sprint_filter;
$stmtSprintsCount = $pdo->prepare("SELECT COUNT(*) FROM sprints $where_sprint");
$stmtSprintsCount->execute($params_unit);
$total_sprints = (int)$stmtSprintsCount->fetchColumn();

$where_active_sprint = $where_sprint . " AND status = 'active'";
$stmtActiveCount = $pdo->prepare("SELECT COUNT(*) FROM sprints $where_active_sprint");
$stmtActiveCount->execute($params_unit);
$active_sprints_count = (int)$stmtActiveCount->fetchColumn();

include 'includes/header.php';
?>

<!-- ==================== APLICAÇÃO PRINCIPAL ==================== -->
<div id="app-screen" class="absolute inset-0 z-40 flex w-full h-full">
    
    <!-- Fundo Vivo com Parallax -->
    <div class="fixed inset-0 w-full h-full overflow-hidden pointer-events-none z-0 bg-[#F8FAFC]">
        <div id="blob1" class="absolute top-[-5%] right-[-5%] w-[500px] h-[500px] bg-senai-cyan/15 rounded-full mix-blend-multiply filter blur-[80px] opacity-70 animate-blob transition-transform duration-1000 ease-out"></div>
        <div id="blob2" class="absolute bottom-[-5%] left-[-5%] w-[600px] h-[600px] bg-senai-orange/10 rounded-full mix-blend-multiply filter blur-[90px] opacity-60 animate-blob transition-transform duration-1000 ease-out" style="animation-delay: -5s;"></div>
        <div id="blob3" class="absolute top-[25%] left-[25%] w-[400px] h-[400px] bg-senai-blue/10 rounded-full mix-blend-multiply filter blur-[80px] opacity-50 animate-blob transition-transform duration-1000 ease-out" style="animation-delay: -10s;"></div>
    </div>

    <?php
    $currentPage = 'dashboard';
    include 'includes/sidebar.php'; 
    ?>

    <!-- Main Content -->
    <main id="main-content" class="flex-1 flex flex-col relative h-full w-full">
        
        <header class="h-14 flex items-center justify-between px-4 md:px-6 absolute top-0 w-full z-20 pointer-events-none">
            <button onclick="toggleSidebar()" class="pointer-events-auto w-10 h-10 flex items-center justify-center bg-white/50 hover:bg-white rounded-xl backdrop-blur-md shadow-sm border border-white/60 transition-all active:scale-95 group text-senai-dark hover:text-senai-cyan">
                <i id="sidebar-icon" class="ph-bold ph-list text-xl transition-transform duration-300"></i>
            </button>
            <div class="flex-1"></div>
            
            <a href="#sprints-recentes-section" class="pointer-events-auto text-[11px] font-bold text-slate-600 hover:text-senai-blue flex items-center gap-1.5 bg-white/70 backdrop-blur-xl border border-white/80 shadow-sm px-4 py-2 rounded-full hover:shadow transition-all duration-300 active:scale-95 group mr-2">
                <i class="ph-fill ph-clock text-senai-orange group-hover:animate-pulse"></i> <?= $active_sprints_count ?> Sprint(s) Ativa(s)
            </a>
        </header>

        <!-- Area de Scroll Dinâmica -->
        <div id="content-scroll-area" class="flex-1 overflow-y-auto no-scrollbar w-full flex flex-col items-center pt-24 pb-20 relative z-10 px-4 md:px-8 scroll-smooth">
            
            <div class="w-full max-w-5xl">
                <div class="mb-8 animate-slide-up text-center md:text-left">
                    <h1 class="text-3xl md:text-4xl font-bold tracking-tight mb-2 drop-shadow-sm text-slate-800 flex items-center justify-center md:justify-start gap-3">
                        <span>Olá, <span class="text-gradient"><?= htmlspecialchars(explode(' ', trim($_SESSION['user_name']))[0]) ?></span></span>
                        <button onclick="openHelpModal('Painel do Professor', 'Bem-vindo ao seu painel principal! <br><br>Aqui você tem um resumo rápido das suas turmas, acesso direto a todas as ferramentas pedagógicas da plataforma (gerador de provas em PDF no padrão SENAI, avaliações online, agenda, relatórios ao vivo e histórico de evolução) e às suas Sprints recentes.')" class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 hover:text-senai-blue hover:bg-blue-50 transition-colors shadow-inner" title="Como Usar">
                            <i class="ph-bold ph-question text-lg"></i>
                        </button>
                    </h1>
                    <p class="text-base text-slate-500 font-medium">
                        Aqui está o resumo das suas atividades e turmas.
                    </p>
                </div>

                <!-- Cards de Resumo (KPIs) -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5 w-full mb-10">
                    <a href="questions.php" class="bg-white/70 backdrop-blur-md border border-white/80 p-5 rounded-2xl shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all group flex items-center gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-senai-blue/10 flex items-center justify-center text-senai-blue group-hover:scale-110 transition-transform shrink-0">
                            <i class="ph-fill ph-files text-2xl"></i>
                        </div>
                        <div>
                            <h3 class="text-3xl font-extrabold text-slate-800 leading-none mb-1"><?= $total_questions ?></h3>
                            <p class="text-xs text-slate-500 font-bold uppercase tracking-wider">Questões Padronizadas</p>
                        </div>
                    </a>

                    <a href="classes.php" class="bg-white/70 backdrop-blur-md border border-white/80 p-5 rounded-2xl shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all group flex items-center gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-senai-orange/10 flex items-center justify-center text-senai-orange group-hover:scale-110 transition-transform shrink-0">
                            <i class="ph-fill ph-users-three text-2xl"></i>
                        </div>
                        <div>
                            <h3 class="text-3xl font-extrabold text-slate-800 leading-none mb-1"><?= $total_classes ?></h3>
                            <p class="text-xs text-slate-500 font-bold uppercase tracking-wider">Turmas Cadastradas</p>
                        </div>
                    </a>

                    <a href="#sprints-recentes-section" class="bg-white/70 backdrop-blur-md border border-white/80 p-5 rounded-2xl shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all group flex items-center gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-senai-cyan/10 flex items-center justify-center text-senai-cyan group-hover:scale-110 transition-transform shrink-0">
                            <i class="ph-fill ph-target text-2xl"></i>
                        </div>
                        <div>
                            <h3 class="text-3xl font-extrabold text-slate-800 leading-none mb-1"><?= $total_sprints ?></h3>
                            <p class="text-xs text-slate-500 font-bold uppercase tracking-wider">Avaliações na Unidade</p>
                        </div>
                    </a>
                </div>

                <!-- ==================== VITRINE DE FERRAMENTAS & ATALHOS DO PROFESSOR ==================== -->
                <div class="mb-12 animate-slide-up">
                    <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-2 mb-5">
                        <div>
                            <span class="text-[11px] font-extrabold uppercase tracking-widest text-senai-orange flex items-center gap-1.5 mb-1">
                                <i class="ph-fill ph-sparkle"></i> Ferramentas Disponíveis para Você
                            </span>
                            <h2 class="text-xl md:text-2xl font-bold text-slate-800">O que você deseja fazer hoje?</h2>
                        </div>
                        <p class="text-xs text-slate-500 font-medium">Explore os recursos do iaS para montar provas, avaliar turmas e analisar resultados</p>
                    </div>

                    <!-- Linha Principal: 2 Cards de Grande Destaque (Prova Impressa em PDF vs Prova Objetiva Online) -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-5">
                        
                        <!-- Card 1: Gerar Prova Impressa em PDF (Padrão SENAI) -->
                        <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-[#004b8d] to-[#0d2758] text-white p-6 md:p-7 shadow-lg hover:shadow-xl transition-all group flex flex-col justify-between border border-blue-800">
                            <div class="absolute -right-8 -top-8 w-40 h-40 bg-white/10 rounded-full blur-2xl pointer-events-none group-hover:scale-125 transition-transform duration-500"></div>
                            <div>
                                <div class="flex items-center justify-between gap-2 mb-4">
                                    <div class="w-12 h-12 rounded-2xl bg-white/15 backdrop-blur-md flex items-center justify-center text-white shadow-inner">
                                        <i class="ph-bold ph-file-pdf text-2xl"></i>
                                    </div>
                                    <span class="px-3 py-1 rounded-full bg-emerald-400/20 border border-emerald-300/30 text-emerald-200 text-[10px] font-extrabold uppercase tracking-wider">
                                        Padrão Oficial SENAI (MSEP)
                                    </span>
                                </div>
                                <h3 class="text-xl font-extrabold tracking-tight mb-2">
                                    Montar Prova em PDF para Imprimir
                                </h3>
                                <p class="text-blue-100/90 text-xs md:text-sm leading-relaxed mb-5">
                                    Utilize as questões do banco já padronizadas e revisadas no modelo SENAI (com capacidades da Matriz, texto-base, comando e figuras) para montar sua prova e <strong>baixar em PDF pronta para imprimir</strong>, com cabeçalho institucional e gabarito.
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-3 pt-2 border-t border-white/15">
                                <a href="questions.php" class="w-full sm:w-auto flex-1 inline-flex items-center justify-center gap-2 bg-white text-[#004b8d] hover:bg-blue-50 px-5 py-3 rounded-xl font-extrabold text-xs md:text-sm shadow-md transition-all group-hover:translate-x-0.5">
                                    <i class="ph-bold ph-printer text-base"></i> Escolher Questões e Gerar PDF
                                    <i class="ph-bold ph-arrow-right"></i>
                                </a>
                            </div>
                        </div>

                        <!-- Card 2: Criar Prova Objetiva Online (Sprint Diagnóstica) -->
                        <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-senai-orange to-[#d44413] text-white p-6 md:p-7 shadow-lg hover:shadow-xl transition-all group flex flex-col justify-between border border-orange-500">
                            <div class="absolute -right-8 -top-8 w-40 h-40 bg-white/10 rounded-full blur-2xl pointer-events-none group-hover:scale-125 transition-transform duration-500"></div>
                            <div>
                                <div class="flex items-center justify-between gap-2 mb-4">
                                    <div class="w-12 h-12 rounded-2xl bg-white/15 backdrop-blur-md flex items-center justify-center text-white shadow-inner">
                                        <i class="ph-bold ph-laptop text-2xl"></i>
                                    </div>
                                    <span class="px-3 py-1 rounded-full bg-white/20 border border-white/30 text-white text-[10px] font-extrabold uppercase tracking-wider">
                                        Correção Automática &amp; Anticola
                                    </span>
                                </div>
                                <h3 class="text-xl font-extrabold tracking-tight mb-2">
                                    Criar Prova Objetiva Online (Sprint)
                                </h3>
                                <p class="text-orange-50/95 text-xs md:text-sm leading-relaxed mb-5">
                                    Aplique uma avaliação diagnóstica digital com seus alunos em laboratório ou sala. Conta com <strong>cronômetro, trava anticola, acompanhamento ao vivo a cada 5s</strong> e envio automático do boletim por capacidade para o e-mail do aluno.
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-3 pt-2 border-t border-white/20">
                                <a href="sprint_create.php" class="w-full sm:w-auto flex-1 inline-flex items-center justify-center gap-2 bg-white text-senai-orange hover:bg-orange-50 px-5 py-3 rounded-xl font-extrabold text-xs md:text-sm shadow-md transition-all group-hover:translate-x-0.5">
                                    <i class="ph-bold ph-rocket-launch text-base"></i> Criar Prova Online (Sprint)
                                    <i class="ph-bold ph-arrow-right"></i>
                                </a>
                            </div>
                        </div>

                    </div>

                    <!-- Linha Secundária: 4 Cards de Ferramentas de Gestão Pedagógica, Relatórios, Histórico e Agenda -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

                        <!-- Card 3: Relatórios das Turmas, PDF Unificado e Modo Projetor -->
                        <a href="#sprints-recentes-section" class="bg-white/85 backdrop-blur-md border border-slate-200/80 hover:border-senai-blue rounded-2xl p-5 shadow-sm hover:shadow-md hover:-translate-y-1 transition-all flex flex-col justify-between group">
                            <div>
                                <div class="flex items-center justify-between mb-3">
                                    <div class="w-10 h-10 rounded-xl bg-blue-50 text-senai-blue flex items-center justify-center group-hover:bg-senai-blue group-hover:text-white transition-colors">
                                        <i class="ph-bold ph-presentation-chart text-xl"></i>
                                    </div>
                                    <span class="text-[10px] font-extrabold px-2 py-0.5 rounded-md bg-blue-50 text-senai-blue uppercase">Ao Vivo &amp; IA</span>
                                </div>
                                <h4 class="font-extrabold text-slate-800 text-sm mb-1.5 group-hover:text-senai-blue transition-colors">
                                    Relatórios, Slides IA &amp; Projetor
                                </h4>
                                <p class="text-xs text-slate-500 leading-relaxed mb-4">
                                    Veja o desempenho da turma em tempo real, baixe o <strong>PDF unificado de todos os alunos</strong>, gere Slides com Parecer da IA e corrija no <strong>Modo Projetor</strong>.
                                </p>
                            </div>
                            <span class="text-xs font-extrabold text-senai-blue flex items-center gap-1 group-hover:gap-2 transition-all">
                                Ver Relatórios das Provas <i class="ph-bold ph-arrow-right"></i>
                            </span>
                        </a>

                        <!-- Card 4: Histórico de Evolução por Aluno e Turma -->
                        <a href="student_history.php" class="bg-white/85 backdrop-blur-md border border-slate-200/80 hover:border-emerald-500 rounded-2xl p-5 shadow-sm hover:shadow-md hover:-translate-y-1 transition-all flex flex-col justify-between group">
                            <div>
                                <div class="flex items-center justify-between mb-3">
                                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center group-hover:bg-emerald-600 group-hover:text-white transition-colors">
                                        <i class="ph-bold ph-chart-line-up text-xl"></i>
                                    </div>
                                    <span class="text-[10px] font-extrabold px-2 py-0.5 rounded-md bg-emerald-50 text-emerald-700 uppercase">Evolução</span>
                                </div>
                                <h4 class="font-extrabold text-slate-800 text-sm mb-1.5 group-hover:text-emerald-600 transition-colors">
                                    Histórico de Alunos e Turmas
                                </h4>
                                <p class="text-xs text-slate-500 leading-relaxed mb-4">
                                    Acompanhe a evolução histórica de cada estudante ao longo das diagnósticas por Unidade Curricular e por Capacidade da Matriz SAEP.
                                </p>
                            </div>
                            <span class="text-xs font-extrabold text-emerald-600 flex items-center gap-1 group-hover:gap-2 transition-all">
                                Consultar Histórico <i class="ph-bold ph-arrow-right"></i>
                            </span>
                        </a>

                        <!-- Card 5: Agenda e Calendário de Avaliações -->
                        <a href="agenda.php" class="bg-white/85 backdrop-blur-md border border-slate-200/80 hover:border-indigo-500 rounded-2xl p-5 shadow-sm hover:shadow-md hover:-translate-y-1 transition-all flex flex-col justify-between group">
                            <div>
                                <div class="flex items-center justify-between mb-3">
                                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center group-hover:bg-indigo-600 group-hover:text-white transition-colors">
                                        <i class="ph-bold ph-calendar-check text-xl"></i>
                                    </div>
                                    <span class="text-[10px] font-extrabold px-2 py-0.5 rounded-md bg-indigo-50 text-indigo-700 uppercase">Calendário</span>
                                </div>
                                <h4 class="font-extrabold text-slate-800 text-sm mb-1.5 group-hover:text-indigo-600 transition-colors">
                                    Agenda de Diagnósticas
                                </h4>
                                <p class="text-xs text-slate-500 leading-relaxed mb-4">
                                    Visualize o calendário mensal das avaliações agendadas, programe novas datas para suas turmas e sincronize com o Google Agenda.
                                </p>
                            </div>
                            <span class="text-xs font-extrabold text-indigo-600 flex items-center gap-1 group-hover:gap-2 transition-all">
                                Abrir Calendário <i class="ph-bold ph-arrow-right"></i>
                            </span>
                        </a>

                        <!-- Card 6: Gestão de Turmas, Alunos & Tutor IA -->
                        <div class="bg-white/85 backdrop-blur-md border border-slate-200/80 hover:border-violet-500 rounded-2xl p-5 shadow-sm hover:shadow-md hover:-translate-y-1 transition-all flex flex-col justify-between group">
                            <div>
                                <div class="flex items-center justify-between mb-3">
                                    <div class="w-10 h-10 rounded-xl bg-violet-50 text-violet-600 flex items-center justify-center group-hover:bg-violet-600 group-hover:text-white transition-colors">
                                        <i class="ph-bold ph-robot text-xl"></i>
                                    </div>
                                    <span class="text-[10px] font-extrabold px-2 py-0.5 rounded-md bg-violet-50 text-violet-700 uppercase">Turmas &amp; IA</span>
                                </div>
                                <h4 class="font-extrabold text-slate-800 text-sm mb-1.5 group-hover:text-violet-600 transition-colors">
                                    Minhas Turmas &amp; Tutor IA
                                </h4>
                                <p class="text-xs text-slate-500 leading-relaxed mb-4">
                                    Gerencie os estudantes das suas turmas ou utilize o <strong>Tutor Pedagógico de IA</strong> para criar situações de aprendizagem e planos de aula.
                                </p>
                            </div>
                            <div class="flex items-center justify-between gap-2 pt-1 border-t border-slate-100">
                                <a href="classes.php" class="text-xs font-extrabold text-slate-600 hover:text-senai-blue flex items-center gap-1">
                                    <i class="ph-bold ph-users-three"></i> Turmas
                                </a>
                                <span class="text-slate-300">|</span>
                                <a href="ai_tutor.php" class="text-xs font-extrabold text-violet-600 hover:text-violet-800 flex items-center gap-1">
                                    <i class="ph-bold ph-sparkle"></i> Tutor de IA <i class="ph-bold ph-arrow-right"></i>
                                </a>
                            </div>
                        </div>

                    </div>

                    <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
                    <!-- Barra Extra de Atalhos Administrativos (Visível apenas para Admin) -->
                    <div class="mt-4 p-4 rounded-2xl bg-slate-900 text-white flex flex-wrap items-center justify-between gap-3 shadow-md">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-senai-orange flex items-center justify-center text-white shrink-0">
                                <i class="ph-fill ph-shield-star text-lg"></i>
                            </div>
                            <div>
                                <h4 class="text-xs md:text-sm font-extrabold leading-tight">Atalhos de Gestão &amp; Administração</h4>
                                <p class="text-[11px] text-slate-400">Acesse os painéis estratégicos multi-unidades, usuários e importação oficial do SAEP</p>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <a href="admin_dashboard.php" class="px-3.5 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-white text-xs font-bold flex items-center gap-1.5 transition-colors">
                                <i class="ph-bold ph-globe"></i> Visão Global (Gráficos)
                            </a>
                            <a href="admin_users.php" class="px-3.5 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-white text-xs font-bold flex items-center gap-1.5 transition-colors">
                                <i class="ph-bold ph-user-gear"></i> Gestão de Usuários
                            </a>
                            <a href="admin_units.php" class="px-3.5 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-white text-xs font-bold flex items-center gap-1.5 transition-colors">
                                <i class="ph-bold ph-buildings"></i> Regionais &amp; Escolas
                            </a>
                            <a href="import_saep.php" class="px-3.5 py-2 rounded-xl bg-senai-orange hover:bg-orange-600 text-white text-xs font-bold flex items-center gap-1.5 transition-colors">
                                <i class="ph-bold ph-upload-simple"></i> Importar SAEP
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- ==================== SPRINTS RECENTES & RELATÓRIOS ==================== -->
                <div id="sprints-recentes-section" class="mt-8 animate-slide-up delay-200">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                        <div>
                            <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                                <i class="ph-fill ph-chart-bar text-senai-blue"></i> Avaliações (Sprints) Recentes &amp; Relatórios das Turmas
                            </h2>
                            <p class="text-xs text-slate-500 font-medium">Clique em <b>Relatório</b> para acompanhar a prova ao vivo, baixar os PDFs da turma, reabrir provas ou usar o Modo Projetor</p>
                        </div>
                        <a href="sprint_create.php" class="px-4 py-2 rounded-xl bg-senai-blue hover:bg-blue-800 text-white text-xs md:text-sm font-bold transition-all shadow-sm flex items-center gap-1.5">
                            <i class="ph-bold ph-plus"></i> Nova Sprint Online
                        </a>
                    </div>
                    
                    <div class="bg-white/70 backdrop-blur-md border border-white/80 rounded-3xl p-6 shadow-sm overflow-hidden">
                        <?php
                        if ($_SESSION['user_role'] === 'admin') {
                            $stmtSprints = $pdo->query("
                                SELECT s.*, c.name as class_name,
                                (SELECT count(*) FROM sprint_questions WHERE sprint_id = s.id) as total_questions,
                                (SELECT count(DISTINCT student_id) FROM sprint_attempts WHERE sprint_id = s.id) as students_started
                                FROM sprints s
                                JOIN classes c ON s.class_id = c.id
                                WHERE s.teacher_id IS NOT NULL
                                  AND s.name NOT LIKE 'DUELO|%'
                                  AND s.name NOT LIKE 'Missão de Treinamento%'
                                ORDER BY s.created_at DESC LIMIT 8
                            ");
                            $sprints = $stmtSprints->fetchAll();
                        } else {
                            $stmtSprints = $pdo->prepare("
                                SELECT s.*, c.name as class_name,
                                (SELECT count(*) FROM sprint_questions WHERE sprint_id = s.id) as total_questions,
                                (SELECT count(DISTINCT student_id) FROM sprint_attempts WHERE sprint_id = s.id) as students_started
                                FROM sprints s
                                JOIN classes c ON s.class_id = c.id
                                WHERE s.teacher_id = ?
                                  AND s.name NOT LIKE 'DUELO|%'
                                  AND s.name NOT LIKE 'Missão de Treinamento%'
                                ORDER BY s.created_at DESC LIMIT 8
                            ");
                            $stmtSprints->execute([$_SESSION['user_id']]);
                            $sprints = $stmtSprints->fetchAll();
                        }
                        
                        if(empty($sprints)):
                        ?>
                            <div class="text-center py-8 text-slate-500">
                                <i class="ph-thin ph-clipboard-text text-4xl mb-2 opacity-50"></i>
                                <p>Nenhuma Sprint criada ainda.</p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left border-collapse">
                                    <thead>
                                        <tr class="border-b border-slate-100 text-xs uppercase tracking-widest text-slate-400">
                                            <th class="pb-3 font-semibold">Nome da Prova</th>
                                            <th class="pb-3 font-semibold">Turma</th>
                                            <th class="pb-3 font-semibold">Status</th>
                                            <th class="pb-3 font-semibold">Engajamento</th>
                                            <th class="pb-3 font-semibold text-right">Ações Rápidas</th>
                                        </tr>
                                    </thead>
                                    <tbody class="text-sm">
                                        <?php foreach($sprints as $s): ?>
                                        <tr class="border-b border-slate-50 hover:bg-slate-50/50 transition-colors group">
                                            <td class="py-4 font-bold text-slate-700"><?= htmlspecialchars($s['name']) ?></td>
                                            <td class="py-4 font-medium text-slate-500"><?= htmlspecialchars($s['class_name']) ?></td>
                                            <td class="py-4">
                                                <?php if($s['status'] === 'active'): ?>
                                                    <span class="bg-green-100 text-green-700 px-2.5 py-1 rounded-md text-[10px] font-bold uppercase">Ativa</span>
                                                <?php elseif($s['status'] === 'scheduled'): ?>
                                                    <span class="bg-amber-100 text-amber-800 px-2.5 py-1 rounded-md text-[10px] font-bold uppercase">Agendada</span>
                                                <?php elseif($s['status'] === 'completed'): ?>
                                                    <span class="bg-indigo-100 text-indigo-700 px-2.5 py-1 rounded-md text-[10px] font-bold uppercase">Finalizada</span>
                                                <?php else: ?>
                                                    <span class="bg-slate-100 text-slate-600 px-2.5 py-1 rounded-md text-[10px] font-bold uppercase">Rascunho</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-4 font-semibold text-senai-blue">
                                                <i class="ph-fill ph-users mr-1"></i> <?= $s['students_started'] ?> iniciaram
                                            </td>
                                            <td class="py-4 text-right">
                                                <div class="inline-flex items-center justify-end gap-1.5">
                                                    <a href="exam_print_senai.php?sprint_id=<?= $s['id'] ?>" target="_blank" class="inline-flex items-center gap-1 bg-slate-100 hover:bg-[#004b8d] text-slate-600 hover:text-white px-2.5 py-1.5 rounded-lg font-bold transition-all text-xs" title="Baixar/Imprimir esta Prova em PDF no Padrão SENAI">
                                                        <i class="ph-bold ph-file-pdf"></i> PDF Prova
                                                    </a>
                                                    <a href="sprint_report.php?id=<?= $s['id'] ?>" class="inline-flex items-center gap-1.5 bg-senai-blue/10 hover:bg-senai-blue text-senai-blue hover:text-white px-3 py-1.5 rounded-lg font-bold transition-all text-xs">
                                                        <i class="ph-bold ph-chart-bar"></i> Relatório
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
    </main>
</div>

<?php include 'includes/footer.php'; ?>
