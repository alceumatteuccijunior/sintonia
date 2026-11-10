<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
require_once 'config.php';
$pdo = getPDOConnection(true);

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
    $currentPage = 'questions';
    include 'includes/sidebar.php'; 
    ?>

    <!-- Main Content -->
    <main id="main-content" class="flex-1 flex flex-col relative h-full w-full">
        
        <header class="h-14 flex items-center justify-between px-4 md:px-6 absolute top-0 w-full z-20 pointer-events-none">
            <button onclick="toggleSidebar()" class="pointer-events-auto w-10 h-10 flex items-center justify-center bg-white/50 hover:bg-white rounded-xl backdrop-blur-md shadow-sm border border-white/60 transition-all active:scale-95 group text-senai-dark hover:text-senai-cyan">
                <i id="sidebar-icon" class="ph-bold ph-list text-xl transition-transform duration-300"></i>
            </button>
            <div class="flex-1"></div>
        </header>

        <!-- Area de Scroll Dinâmica -->
        <div id="content-scroll-area" class="flex-1 overflow-y-auto no-scrollbar w-full flex flex-col items-center pt-24 pb-20 relative z-10 px-4 md:px-8">
            
            <div class="w-full max-w-5xl">
                <div class="flex flex-col md:flex-row md:items-center justify-between mb-8 animate-slide-up">
                    <div>
                        <h1 class="text-3xl font-bold tracking-tight mb-2 text-slate-800 flex items-center gap-3">
                            Banco de Questões
                            <button onclick="openHelpModal('Banco de Questões', 'Este é o repositório central das avaliações.<br><br><b>Como funciona:</b><br>- As questões são agrupadas por Cursos.<br>- Clique em um curso para abrir o catálogo específico dele e ver os detalhes, módulos e alternativas.<br>- Você também pode adicionar novas questões ou importar em lote.')" class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 hover:text-senai-blue hover:bg-blue-50 transition-colors shadow-inner" title="Como Usar">
                                <i class="ph-bold ph-question text-lg"></i>
                            </button>
                        </h1>
                        <p class="text-slate-500 font-medium">
                            Gerencie as questões estruturadas por Curso e Módulo.
                        </p>
                    </div>
                    <?php if ($_SESSION['user_role'] === 'teacher' || $_SESSION['user_role'] === 'admin'): ?>
                    <div class="mt-4 md:mt-0 flex flex-wrap items-center gap-2.5">
                        <button onclick="openModal('importModal', 'doc')" class="bg-gradient-to-r from-violet-600 to-indigo-600 text-white hover:from-violet-700 hover:to-indigo-700 px-4 py-2.5 rounded-xl font-bold text-sm transition-all shadow-[0_4px_14px_-3px_rgba(99,102,241,0.45)] hover:-translate-y-0.5 flex items-center gap-2">
                            <i class="ph-fill ph-sparkle text-amber-300"></i> Importar Word / PDF (IA)
                        </button>
                        <button onclick="openModal('importModal', 'csv')" class="bg-indigo-50 text-indigo-600 hover:bg-indigo-100 border border-indigo-200 px-4 py-2.5 rounded-xl font-bold text-sm transition-all flex items-center gap-2">
                            <i class="ph-bold ph-file-csv"></i> Importar CSV
                        </button>
                        <a href="question_create.php" class="bg-senai-blue text-white px-5 py-2.5 rounded-xl font-semibold shadow-[0_4px_15px_-3px_rgba(26,66,138,0.4)] hover:shadow-[0_8px_20px_-3px_rgba(26,66,138,0.5)] hover:-translate-y-0.5 transition-all flex items-center gap-2 text-sm">
                            <i class="ph-bold ph-plus"></i> Nova Questão
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="bg-white/70 backdrop-blur-md border border-white/80 rounded-2xl p-6 shadow-sm mb-10 animate-fade-in delay-200">
                    
                    <?php
                    $stmtCursos = $pdo->query("SELECT * FROM courses ORDER BY name");
                    $cursos = $stmtCursos->fetchAll();

                    $stmtModulosAll = $pdo->query("
                        SELECT m.id, m.course_id, m.name, c.name AS course_name
                        FROM modules m
                        JOIN courses c ON m.course_id = c.id
                        ORDER BY m.name
                    ");
                    $modulosAll = $stmtModulosAll->fetchAll();
                    
                    if (empty($cursos)):
                    ?>
                        <div class="text-center text-slate-500 py-10">
                            <i class="ph-thin ph-database text-4xl mb-3 opacity-50"></i>
                            <p class="font-medium">O Banco de Questões está vazio.</p>
                            <p class="text-sm mt-1">Nenhum curso cadastrado.</p>
                        </div>
                    <?php else: ?>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <?php foreach ($cursos as $curso): 
                                // Conta total de questões nesse curso
                                $stmtCount = $pdo->prepare("
                                    SELECT count(q.id) 
                                    FROM questions q 
                                    JOIN modules m ON q.module_id = m.id 
                                    WHERE m.course_id = ?
                                ");
                                $stmtCount->execute([$curso['id']]);
                                $qtdQuestoes = $stmtCount->fetchColumn();
                            ?>
                                <a href="questions_view.php?course_id=<?= $curso['id'] ?>" class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm hover:shadow-lg hover:border-senai-blue/30 hover:-translate-y-1 transition-all group flex flex-col h-full">
                                    <div class="w-12 h-12 rounded-full bg-senai-blue/10 flex items-center justify-center text-senai-blue mb-4 group-hover:bg-senai-blue group-hover:text-white transition-colors">
                                        <i class="ph-fill ph-books text-2xl"></i>
                                    </div>
                                    <h3 class="text-lg font-bold text-slate-800 mb-2 line-clamp-2">
                                        <?= htmlspecialchars($curso['name']) ?>
                                    </h3>
                                    <p class="text-sm text-slate-500 font-medium mt-auto mb-4 line-clamp-2">
                                        <?= htmlspecialchars($curso['description']) ?>
                                    </p>
                                    <div class="pt-4 border-t border-slate-100 flex justify-between items-center mt-auto">
                                        <span class="text-xs font-bold text-senai-orange bg-senai-orange/10 px-2 py-1 rounded-md">
                                            <?= $qtdQuestoes ?> questões
                                        </span>
                                        <span class="text-senai-blue text-sm font-bold flex items-center gap-1 group-hover:translate-x-1 transition-transform">
                                            Acessar banco <i class="ph-bold ph-arrow-right"></i>
                                        </span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </main>
</div>

<!-- Modal de Importação em Massa (Word/PDF com IA + Planilha CSV) -->
<div id="importModal" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-4xl overflow-hidden scale-95 opacity-0 transition-all duration-300 flex flex-col max-h-[92vh]" id="importModalContent">
        
        <div class="p-5 md:p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/70 shrink-0">
            <div>
                <h3 class="font-bold text-lg text-slate-800 flex items-center gap-2" id="import-modal-title">
                    <i class="ph-fill ph-sparkle text-violet-600"></i> Importação Inteligente de Questões
                </h3>
                <p class="text-xs text-slate-500 mt-0.5" id="import-modal-subtitle">Importe provas em Word (.docx), PDF (.pdf) com Inteligência Artificial ou via Planilha CSV</p>
            </div>
            <button onclick="closeModal('importModal')" class="text-slate-400 hover:text-red-500 transition-colors rounded-full p-1 hover:bg-slate-100">
                <i class="ph-bold ph-x text-xl"></i>
            </button>
        </div>

        <!-- Barra de Abas (Exibida na Etapa 1) -->
        <div id="import-tabs-bar" class="px-6 pt-4 bg-slate-50/40 border-b border-slate-100 flex gap-2 shrink-0">
            <button type="button" id="tab-btn-doc" onclick="switchImportTab('doc')" class="px-4 py-2.5 rounded-t-xl font-bold text-xs md:text-sm border-b-2 border-violet-600 text-violet-700 bg-white shadow-sm flex items-center gap-2 transition-all">
                <i class="ph-fill ph-sparkle text-amber-500"></i> Prova Word (.docx) ou PDF (IA)
            </button>
            <button type="button" id="tab-btn-csv" onclick="switchImportTab('csv')" class="px-4 py-2.5 rounded-t-xl font-bold text-xs md:text-sm border-b-2 border-transparent text-slate-500 hover:text-slate-700 hover:bg-white/60 flex items-center gap-2 transition-all">
                <i class="ph-bold ph-file-csv text-indigo-600"></i> Planilha Padrão (.csv)
            </button>
        </div>

        <!-- ================================================================== -->
        <!-- STEP 1A: Upload de Documento Word (.docx) / PDF (.pdf) com IA      -->
        <!-- ================================================================== -->
        <div id="step-doc-upload" class="p-6 overflow-y-auto flex-1">
            <div class="bg-gradient-to-r from-violet-50 via-indigo-50 to-blue-50 border border-violet-100 rounded-2xl p-4 mb-5 text-xs md:text-sm text-slate-700">
                <div class="flex items-start gap-3">
                    <div class="w-9 h-9 rounded-xl bg-violet-600 text-white flex items-center justify-center shrink-0 mt-0.5 shadow-sm">
                        <i class="ph-fill ph-magic-wand text-lg"></i>
                    </div>
                    <div>
                        <h4 class="font-bold text-violet-950 mb-1">Como funciona a Importação por Documento com IA?</h4>
                        <p class="text-slate-600 leading-relaxed">
                            Envie qualquer prova ou lista de exercícios em <b>.docx (Word)</b> ou <b>.pdf</b>. A Inteligência Artificial identifica automaticamente cada enunciado, separa as alternativas, detecta quais questões citam <b>figuras/circuitos/diagramas</b> e abre uma tela de conferência antes de salvar. Na etapa seguinte, você poderá <b>vincular as imagens</b> com 1 clique!
                        </p>
                    </div>
                </div>
            </div>

            <form id="docImportForm" onsubmit="submitDocExtract(event)" class="space-y-4">
                <!-- Datalists de Cursos e Módulos existentes -->
                <datalist id="courses-datalist">
                    <?php foreach ($cursos as $c): ?>
                        <option value="<?= htmlspecialchars($c['name']) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <datalist id="modules-datalist">
                    <?php foreach ($modulosAll as $m): ?>
                        <option value="<?= htmlspecialchars($m['name']) ?>"><?= htmlspecialchars($m['course_name']) ?></option>
                    <?php endforeach; ?>
                </datalist>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                            Curso de Destino <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <i class="ph-bold ph-books absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"></i>
                            <input type="text" id="doc_course_name" list="courses-datalist" required placeholder="Selecione da lista ou digite um novo curso..." oninput="updateModuleSuggestions(this.value)" class="w-full pl-10 pr-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm text-slate-800 font-medium focus:bg-white focus:ring-2 focus:ring-violet-500 outline-none transition-all">
                        </div>
                        <p class="text-[11px] text-slate-400 mt-1">Se o curso não existir, será criado automaticamente.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                            Módulo / Unidade Curricular Padrão <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <i class="ph-bold ph-folders absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"></i>
                            <input type="text" id="doc_default_module" list="modules-datalist" required placeholder="Ex: Eletrotécnica Básica, Lógica de Programação..." class="w-full pl-10 pr-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm text-slate-800 font-medium focus:bg-white focus:ring-2 focus:ring-violet-500 outline-none transition-all">
                        </div>
                        <p class="text-[11px] text-slate-400 mt-1">Usado quando a questão não trouxer o módulo explícito.</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                            Tag da Prova / Lote (Opcional)
                        </label>
                        <input type="text" id="doc_import_tag" placeholder="Ex: SAEP2026_ELETRO" class="w-full px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm text-slate-800 focus:bg-white focus:ring-2 focus:ring-violet-500 outline-none transition-all">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                            Capacidade Padrão (Opcional)
                        </label>
                        <input type="text" id="doc_default_capacity" placeholder="Ex: C1, H2 (se não houver no texto)" class="w-full px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm text-slate-800 focus:bg-white focus:ring-2 focus:ring-violet-500 outline-none transition-all">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5" title="Se o documento não tiver as respostas marcadas, informe ex: 1-C, 2-A, 3-D ou deixe vazio para a IA resolver">
                            Gabarito Rápido (Opcional)
                        </label>
                        <input type="text" id="doc_answer_key" placeholder="Ex: 1-C, 2-A, 3-B (ou vazio p/ IA)" class="w-full px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm text-slate-800 focus:bg-white focus:ring-2 focus:ring-violet-500 outline-none transition-all">
                    </div>
                </div>

                <!-- Dropzone Word / PDF -->
                <div class="border-2 border-dashed border-violet-300 bg-violet-50/30 rounded-2xl p-6 text-center hover:bg-violet-50/70 hover:border-violet-500 transition-all cursor-pointer relative" id="doc-dropzone">
                    <input type="file" id="doc_file" name="doc_file" accept=".docx,.pdf,.txt" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" onchange="updateDocFileName(this)">
                    
                    <div class="w-14 h-14 rounded-2xl bg-violet-100 flex items-center justify-center text-violet-600 mx-auto mb-3 shadow-inner">
                        <i class="ph-fill ph-file-doc text-3xl" id="doc-upload-icon"></i>
                    </div>
                    
                    <h4 class="font-bold text-slate-700 text-base mb-1" id="doc-file-title">Selecione o arquivo Word (.docx) ou PDF (.pdf)</h4>
                    <p class="text-xs text-slate-500" id="doc-file-desc">Clique para procurar ou arraste a prova aqui</p>
                </div>

                <!-- Opção expansível para colar texto manualmente -->
                <details class="bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-xs">
                    <summary class="font-bold text-slate-600 cursor-pointer select-none flex items-center gap-1.5">
                        <i class="ph-bold ph-clipboard-text text-violet-600"></i> Prefere colar o texto das questões diretamente? (Opcional)
                    </summary>
                    <div class="mt-3">
                        <textarea id="doc_manual_text" rows="4" placeholder="Cole aqui as questões copiadas do Word, PDF ou Google Docs caso não queira anexar o arquivo..." class="w-full p-3 bg-white border border-slate-200 rounded-xl text-xs text-slate-700 outline-none focus:ring-2 focus:ring-violet-500"></textarea>
                    </div>
                </details>

                <!-- Configuração Permanente da Chave OpenAI no Banco de Dados (Exibida se o servidor estiver sem chave após deploy) -->
                <?php $hasConfiguredOpenAiKey = (defined('OPENAI_API_KEY') && trim(OPENAI_API_KEY) !== '' && trim(OPENAI_API_KEY) !== 'sua_chave_aqui'); ?>
                <div id="api-key-setup-box" class="<?= $hasConfiguredOpenAiKey ? 'hidden' : '' ?> bg-amber-50 border border-amber-300 rounded-2xl p-4 text-xs">
                    <div class="flex items-start gap-2.5 mb-2.5">
                        <i class="ph-fill ph-key text-lg text-amber-600 mt-0.5"></i>
                        <div class="flex-1">
                            <h5 class="font-bold text-amber-950 text-sm">Configurar Chave da OpenAI no Servidor (Única Vez)</h5>
                            <p class="text-amber-800 mt-0.5 leading-relaxed">
                                O GitHub bloqueia o envio de chaves <code>sk-proj-...</code> pelo Git, por isso o servidor de produção estava com <code>sua_chave_aqui</code>. Cole sua chave abaixo para <b>salvá-la diretamente no Banco de Dados MySQL do servidor</b> — assim ela nunca mais será perdida ao fazer <code>git push</code>!
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-col sm:flex-row gap-2">
                        <input type="password" id="doc_api_key_override" placeholder="Cole aqui sua chave sk-proj-..." class="flex-1 px-3.5 py-2 bg-white border border-amber-300 rounded-xl text-xs text-slate-800 font-mono outline-none focus:ring-2 focus:ring-amber-500">
                        <button type="button" onclick="saveServerOpenAiKey()" id="btn-save-api-key" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-xl font-bold text-xs transition-all flex items-center justify-center gap-1.5 shrink-0 shadow-sm">
                            <i class="ph-bold ph-floppy-disk"></i> Salvar Chave Permanente
                        </button>
                    </div>
                </div>

                <!-- Alerta e Progresso -->
                <div id="doc-import-alert" class="p-4 rounded-xl text-sm font-medium hidden"></div>

                <div class="pt-2 flex justify-end gap-3">
                    <button type="button" onclick="closeModal('importModal')" class="px-5 py-2.5 rounded-xl font-bold text-sm text-slate-500 hover:bg-slate-100 transition-colors">Cancelar</button>
                    <button type="submit" id="btn-doc-extract" class="px-6 py-2.5 rounded-xl font-bold text-sm bg-gradient-to-r from-violet-600 to-indigo-600 text-white hover:from-violet-700 hover:to-indigo-700 transition-all shadow-md flex items-center gap-2 disabled:opacity-50">
                        <i class="ph-fill ph-sparkle"></i> Analisar e Extrair Questões com IA
                    </button>
                </div>
            </form>
        </div>

        <!-- ================================================================== -->
        <!-- STEP 1B: Revisão Visual das Questões Extraídas (Pré-Salvamento)    -->
        <!-- ================================================================== -->
        <div id="step-doc-review" class="p-6 overflow-y-auto flex-1 hidden flex-col">
            <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4 mb-4 flex flex-col md:flex-row md:items-center justify-between gap-3 shrink-0">
                <div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-xs font-extrabold px-2.5 py-1 rounded-full bg-violet-100 text-violet-700" id="review-count-badge">0 questões extraídas</span>
                        <span class="text-xs font-bold px-2.5 py-1 rounded-full bg-amber-100 text-amber-800 hidden" id="review-images-badge">📷 0 pedem imagem</span>
                        <span class="text-xs font-bold px-2.5 py-1 rounded-full bg-blue-100 text-blue-700 hidden" id="review-ai-key-badge">🤖 0 gabaritos sugeridos pela IA</span>
                    </div>
                    <p class="text-xs text-slate-600 mt-1.5">
                        Confira os enunciados e alternativas abaixo. Você pode <b>editar qualquer texto</b>, clicar em outra opção para <b>alterar a resposta correta</b> ou marcar quais questões receberão imagem no próximo passo.
                    </p>
                </div>
                <button type="button" onclick="backToDocUpload()" class="px-3.5 py-2 rounded-xl border border-slate-200 bg-white hover:bg-slate-100 text-slate-600 text-xs font-bold flex items-center gap-1.5 shrink-0 self-start md:self-center">
                    <i class="ph-bold ph-arrow-left"></i> Voltar ao Arquivo
                </button>
            </div>

            <div id="doc-review-alert" class="mb-4 p-3.5 rounded-xl text-xs font-bold hidden"></div>

            <!-- Lista de Cards de Revisão -->
            <div id="doc-review-list" class="space-y-4 flex-1 overflow-y-auto max-h-[420px] pr-1">
                <!-- Preenchido via JS -->
            </div>

            <!-- Rodapé da Revisão -->
            <div class="mt-5 pt-4 border-t border-slate-100 flex flex-col sm:flex-row justify-between items-center gap-3 shrink-0">
                <span class="text-xs text-slate-500 font-medium" id="review-footer-summary">
                    Após salvar, você poderá anexar as imagens de apoio.
                </span>
                <div class="flex items-center gap-3 w-full sm:w-auto justify-end">
                    <button type="button" onclick="backToDocUpload()" class="px-4 py-2.5 rounded-xl font-bold text-sm text-slate-500 hover:bg-slate-100 transition-colors">
                        Voltar
                    </button>
                    <button type="button" id="btn-save-reviewed" onclick="saveReviewedQuestions()" class="px-6 py-2.5 rounded-xl font-bold text-sm bg-emerald-600 text-white hover:bg-emerald-700 transition-all shadow-md flex items-center gap-2 disabled:opacity-50">
                        <i class="ph-bold ph-floppy-disk"></i> Confirmar e Salvar Questões
                    </button>
                </div>
            </div>
        </div>
        
        <!-- ================================================================== -->
        <!-- STEP 1C: Upload CSV Tradicional                                    -->
        <!-- ================================================================== -->
        <div id="step-csv-upload" class="p-6 overflow-y-auto flex-1 hidden">
            <div class="bg-indigo-50 border border-indigo-100 rounded-2xl p-5 mb-6 text-sm text-indigo-900">
                <h4 class="font-bold mb-2 flex items-center gap-2"><i class="ph-fill ph-info"></i> Como Importar via CSV?</h4>
                <p class="mb-3">Para cadastrar dezenas de questões via planilha, siga as regras do modelo:</p>
                <ul class="list-disc pl-5 space-y-1.5 mb-4 text-indigo-800 text-xs md:text-sm">
                    <li>Baixe a planilha modelo abaixo e não altere a ordem das colunas (Curso, Módulo, Capacidade, Enunciado, etc).</li>
                    <li>Se o <b>Curso</b> ou <b>Módulo</b> digitado no CSV não existir no sistema, eles serão <b>criados automaticamente</b>.</li>
                    <li>A coluna <b>Tag</b> serve para identificar as questões de uma prova específica (ex: "SAEP2026TI2"), facilitando na hora de criar uma Sprint!</li>
                    <li>Salve o arquivo no formato <code>.csv</code> (separado por vírgula ou ponto-e-vírgula) e faça o upload abaixo.</li>
                </ul>
                
                <a href="template_questoes.csv" download class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-xl text-xs md:text-sm font-bold transition-all shadow-sm">
                    <i class="ph-bold ph-download-simple"></i> Baixar Template CSV
                </a>
            </div>

            <form id="importForm" onsubmit="submitImport(event)">
                <div class="border-2 border-dashed border-slate-300 rounded-2xl p-8 text-center hover:bg-slate-50 hover:border-indigo-400 transition-all cursor-pointer relative" id="dropzone">
                    <input type="file" id="csv_file" name="csv_file" accept=".csv" required class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" onchange="updateFileName(this)">
                    
                    <div id="upload-icon" class="w-16 h-16 rounded-full bg-indigo-50 flex items-center justify-center text-indigo-600 mx-auto mb-4">
                        <i class="ph-fill ph-file-csv text-3xl"></i>
                    </div>
                    
                    <h4 class="font-bold text-slate-700 text-lg mb-1" id="file-title">Selecione o arquivo .csv</h4>
                    <p class="text-sm text-slate-500" id="file-desc">ou arraste e solte ele aqui</p>
                </div>

                <!-- Alertas de Sucesso/Erro -->
                <div id="import-alert" class="mt-4 p-4 rounded-xl text-sm font-medium hidden"></div>

                <div class="mt-8 flex justify-end gap-3">
                    <button type="button" onclick="closeModal('importModal')" class="px-5 py-2.5 rounded-xl font-bold text-sm text-slate-500 hover:bg-slate-100 transition-colors">Cancelar</button>
                    <button type="submit" id="btn-importar" class="px-5 py-2.5 rounded-xl font-bold text-sm bg-indigo-600 text-white hover:bg-indigo-700 transition-all shadow-sm flex items-center gap-2 disabled:opacity-50">
                        <i class="ph-bold ph-check"></i> Processar Importação
                    </button>
                </div>
            </form>
        </div>

        <!-- ================================================================== -->
        <!-- STEP 2: Vincular Imagens às Questões (Pós-Importação CSV ou Doc)   -->
        <!-- ================================================================== -->
        <div id="step-link-images" class="p-6 overflow-y-auto flex-1 hidden flex-col">
            <!-- Banner de Pergunta -->
            <div class="bg-gradient-to-r from-emerald-500/10 via-indigo-500/10 to-blue-500/10 border border-indigo-200/60 rounded-2xl p-5 mb-4">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-xl bg-indigo-600 text-white flex items-center justify-center shrink-0 shadow-sm mt-0.5">
                        <i class="ph-bold ph-image text-xl"></i>
                    </div>
                    <div class="flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-xs font-bold px-2.5 py-0.5 rounded-full bg-emerald-100 text-emerald-700" id="imported-count-badge">✓ Questões salvas no banco</span>
                            <span class="text-xs font-bold px-2.5 py-0.5 rounded-full bg-amber-100 text-amber-800 hidden" id="imported-needs-image-badge">📷 0 pedem imagem</span>
                            <span class="text-xs text-slate-400">Etapa Opcional</span>
                        </div>
                        <h4 class="text-base font-bold text-slate-800 mt-1">Vincular imagens de apoio às questões importadas</h4>
                        <p class="text-xs md:text-sm text-slate-600 mt-1 leading-relaxed">
                            As questões marcadas com <b class="text-amber-700">📷 Pede imagem no enunciado</b> foram colocadas no topo da lista para facilitar. Basta clicar em <b>Vincular Imagem</b> na questão correspondente.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Busca rápida e filtro de questões que pedem imagem -->
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-2.5 mb-4">
                <div class="relative flex-1">
                    <i class="ph-bold ph-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                    <input type="text" id="filter-imported-input" placeholder="Filtrar por trecho do enunciado ou módulo..." oninput="filterImportedList(this.value)" class="w-full pl-9 pr-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs md:text-sm text-slate-700 outline-none focus:ring-1 focus:ring-indigo-500 transition-all">
                </div>
                <button type="button" id="btn-filter-needs-img" onclick="toggleFilterNeedsImage()" class="px-3.5 py-2 rounded-xl border border-amber-300 bg-amber-50 text-amber-800 hover:bg-amber-100 text-xs font-bold transition-all flex items-center justify-center gap-1.5 shrink-0">
                    <i class="ph-fill ph-camera"></i> <span id="btn-filter-needs-img-text">Apenas que pedem imagem</span>
                </button>
                <span class="text-xs font-bold text-slate-400 shrink-0 text-right" id="imported-list-counter">0 questões</span>
            </div>

            <!-- Lista de Questões Importadas com Botão de Upload -->
            <div class="space-y-3 flex-1 overflow-y-auto max-h-[380px] pr-1" id="imported-questions-list">
                <!-- Injetado dinamicamente via JS -->
            </div>

            <!-- Rodapé com Ações -->
            <div class="mt-6 pt-4 border-t border-slate-100 flex justify-between items-center shrink-0">
                <button type="button" onclick="window.location.reload()" class="px-5 py-2.5 rounded-xl font-bold text-sm text-slate-500 hover:bg-slate-100 transition-colors">
                    Concluir Mais Tarde
                </button>
                <button type="button" onclick="window.location.reload()" class="px-6 py-2.5 rounded-xl font-bold text-sm bg-senai-blue text-white hover:bg-[#153673] transition-all shadow-md flex items-center gap-2">
                    <i class="ph-bold ph-check"></i> Finalizar e Ver Cursos
                </button>
            </div>
        </div>

    </div>
</div>

<!-- Bibliotecas Client-Side para leitura instantânea de Word (.docx) e PDF (.pdf) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.6.0/mammoth.browser.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
    if (typeof pdfjsLib !== 'undefined') {
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    }

    const allModulesFromDb = <?= json_encode($modulosAll, JSON_UNESCAPED_UNICODE) ?>;
    let importedQuestionsGlobal = [];
    let extractedDocQuestions = [];
    let onlyNeedsImageFilter = false;

    function updateModuleSuggestions(courseName) {
        const datalist = document.getElementById('modules-datalist');
        if (!datalist) return;
        const cleanCourse = (courseName || '').trim().toLowerCase();
        datalist.innerHTML = '';

        const filtered = cleanCourse
            ? allModulesFromDb.filter(m => m.course_name.toLowerCase() === cleanCourse)
            : allModulesFromDb;

        const source = filtered.length > 0 ? filtered : allModulesFromDb;
        const seen = new Set();
        source.forEach(m => {
            if (!seen.has(m.name)) {
                seen.add(m.name);
                const opt = document.createElement('option');
                opt.value = m.name;
                opt.textContent = m.course_name;
                datalist.appendChild(opt);
            }
        });
    }

    function switchImportTab(mode) {
        const tabDoc = document.getElementById('tab-btn-doc');
        const tabCsv = document.getElementById('tab-btn-csv');
        const stepDoc = document.getElementById('step-doc-upload');
        const stepReview = document.getElementById('step-doc-review');
        const stepCsv = document.getElementById('step-csv-upload');
        const stepImages = document.getElementById('step-link-images');

        stepReview.classList.add('hidden');
        stepReview.classList.remove('flex');
        stepImages.classList.add('hidden');
        stepImages.classList.remove('flex');

        if (mode === 'csv') {
            tabCsv.className = 'px-4 py-2.5 rounded-t-xl font-bold text-xs md:text-sm border-b-2 border-indigo-600 text-indigo-700 bg-white shadow-sm flex items-center gap-2 transition-all';
            tabDoc.className = 'px-4 py-2.5 rounded-t-xl font-bold text-xs md:text-sm border-b-2 border-transparent text-slate-500 hover:text-slate-700 hover:bg-white/60 flex items-center gap-2 transition-all';
            stepDoc.classList.add('hidden');
            stepCsv.classList.remove('hidden');
            document.getElementById('import-modal-title').innerHTML = '<i class="ph-bold ph-file-csv text-indigo-600"></i> Importação em Massa (CSV)';
        } else {
            tabDoc.className = 'px-4 py-2.5 rounded-t-xl font-bold text-xs md:text-sm border-b-2 border-violet-600 text-violet-700 bg-white shadow-sm flex items-center gap-2 transition-all';
            tabCsv.className = 'px-4 py-2.5 rounded-t-xl font-bold text-xs md:text-sm border-b-2 border-transparent text-slate-500 hover:text-slate-700 hover:bg-white/60 flex items-center gap-2 transition-all';
            stepCsv.classList.add('hidden');
            stepDoc.classList.remove('hidden');
            document.getElementById('import-modal-title').innerHTML = '<i class="ph-fill ph-sparkle text-violet-600"></i> Importação Inteligente (Word / PDF com IA)';
        }
    }

    function openModal(id, mode = 'doc') {
        const modal = document.getElementById(id);
        const content = document.getElementById(id + 'Content');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        
        // Reset forms
        document.getElementById('importForm').reset();
        document.getElementById('file-title').textContent = 'Selecione o arquivo .csv';
        document.getElementById('file-desc').textContent = 'ou arraste e solte ele aqui';
        document.getElementById('import-alert').classList.add('hidden');

        document.getElementById('docImportForm').reset();
        document.getElementById('doc-file-title').textContent = 'Selecione o arquivo Word (.docx) ou PDF (.pdf)';
        document.getElementById('doc-file-desc').textContent = 'Clique para procurar ou arraste a prova aqui';
        document.getElementById('doc-import-alert').classList.add('hidden');
        document.getElementById('import-tabs-bar').classList.remove('hidden');

        switchImportTab(mode);
        
        setTimeout(() => {
            content.classList.remove('scale-95', 'opacity-0');
        }, 10);
    }

    function closeModal(id) {
        const modal = document.getElementById(id);
        const content = document.getElementById(id + 'Content');
        content.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            if (importedQuestionsGlobal.length > 0) {
                window.location.reload();
            }
        }, 300);
    }

    function updateFileName(input) {
        if (input.files && input.files[0]) {
            document.getElementById('file-title').textContent = input.files[0].name;
            document.getElementById('file-desc').textContent = (input.files[0].size / 1024).toFixed(2) + ' KB';
        }
    }

    function updateDocFileName(input) {
        if (input.files && input.files[0]) {
            const f = input.files[0];
            document.getElementById('doc-file-title').textContent = f.name;
            document.getElementById('doc-file-desc').textContent = (f.size / 1024).toFixed(1) + ' KB • Pronto para análise com IA';
            const icon = document.getElementById('doc-upload-icon');
            if (f.name.toLowerCase().endsWith('.pdf')) {
                icon.className = 'ph-fill ph-file-pdf text-3xl text-red-500';
            } else {
                icon.className = 'ph-fill ph-file-doc text-3xl text-violet-600';
            }
        }
    }

    // Extrai texto de um PDF diretamente no navegador via PDF.js
    async function extractTextFromPdfClient(file) {
        const arrayBuffer = await file.arrayBuffer();
        const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
        let fullText = '';

        for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
            const page = await pdf.getPage(pageNum);
            const textContent = await page.getTextContent();
            let lastY = null;
            let pageText = '';
            for (const item of textContent.items) {
                if (lastY !== null && Math.abs(item.transform[5] - lastY) > 4) {
                    pageText += '\n';
                } else if (pageText.length > 0 && !pageText.endsWith(' ') && !pageText.endsWith('\n')) {
                    pageText += ' ';
                }
                pageText += item.str;
                lastY = item.transform[5];
            }
            fullText += `\n--- PÁGINA ${pageNum} ---\n` + pageText + '\n';
        }
        return fullText.trim();
    }

    // Extrai texto de um DOCX diretamente no navegador via Mammoth.js
    async function extractTextFromDocxClient(file) {
        const arrayBuffer = await file.arrayBuffer();
        const result = await mammoth.extractRawText({ arrayBuffer: arrayBuffer });
        return (result.value || '').trim();
    }

    async function saveServerOpenAiKey() {
        const keyInput = document.getElementById('doc_api_key_override');
        const btn = document.getElementById('btn-save-api-key');
        const alertBox = document.getElementById('doc-import-alert');
        const val = (keyInput ? keyInput.value : '').trim();

        if (!val || !val.startsWith('sk-')) {
            alert('Por favor, cole uma chave válida da OpenAI (deve começar com "sk-").');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<i class="ph-bold ph-spinner animate-spin"></i> Salvando...';

        try {
            const res = await fetch('questions_import_doc_ajax.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'save_api_key', api_key: val })
            });
            const data = await res.json();
            if (data.success) {
                document.getElementById('api-key-setup-box').classList.add('hidden');
                alertBox.className = 'p-4 rounded-xl text-sm font-medium bg-emerald-50 text-emerald-800 border border-emerald-200';
                alertBox.innerHTML = '<div class="flex items-center gap-2"><i class="ph-fill ph-check-circle text-lg text-emerald-600"></i> <span>' + data.message + ' Agora clique em <b>Analisar e Extrair Questões com IA</b>!</span></div>';
                alertBox.classList.remove('hidden');
            } else {
                alert(data.message || 'Erro ao salvar chave.');
            }
        } catch (err) {
            alert('Erro de comunicação ao salvar chave.');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="ph-bold ph-floppy-disk"></i> Salvar Chave Permanente';
        }
    }

    async function submitDocExtract(e) {
        e.preventDefault();

        const courseName = document.getElementById('doc_course_name').value.trim();
        const defaultModule = document.getElementById('doc_default_module').value.trim();
        const importTag = document.getElementById('doc_import_tag').value.trim();
        const defaultCap = document.getElementById('doc_default_capacity').value.trim();
        const answerKey = document.getElementById('doc_answer_key').value.trim();
        const apiKeyOverride = (document.getElementById('doc_api_key_override')?.value || '').trim();
        const fileInput = document.getElementById('doc_file');
        const manualText = document.getElementById('doc_manual_text').value.trim();
        const btn = document.getElementById('btn-doc-extract');
        const alertBox = document.getElementById('doc-import-alert');

        if (!courseName || !defaultModule) {
            alert('Por favor, informe o Curso de Destino e o Módulo Padrão.');
            return;
        }

        const hasFile = fileInput.files && fileInput.files[0];
        if (!hasFile && manualText.length < 20) {
            alertBox.className = 'p-4 rounded-xl text-sm font-medium bg-amber-50 text-amber-800 border border-amber-200';
            alertBox.innerHTML = '<i class="ph-fill ph-warning-circle"></i> Selecione um arquivo <b>.docx</b> ou <b>.pdf</b> (ou cole o texto das questões manualmente).';
            alertBox.classList.remove('hidden');
            return;
        }

        btn.disabled = true;
        alertBox.className = 'p-4 rounded-xl text-sm font-medium bg-violet-50 text-violet-800 border border-violet-200';
        alertBox.innerHTML = '<div class="flex items-center gap-2.5"><i class="ph-bold ph-spinner animate-spin text-lg text-violet-600"></i> <span id="doc-progress-msg">Lendo documento...</span></div>';
        alertBox.classList.remove('hidden');

        try {
            let extractedText = manualText;
            const formData = new FormData();
            formData.append('action', 'parse_text');
            formData.append('course_name', courseName);
            formData.append('default_module', defaultModule);
            formData.append('default_capacity', defaultCap);
            formData.append('answer_key', answerKey);
            if (apiKeyOverride) {
                formData.append('api_key_override', apiKeyOverride);
            }

            if (hasFile) {
                const file = fileInput.files[0];
                const ext = file.name.split('.').pop().toLowerCase();

                if (ext === 'pdf') {
                    document.getElementById('doc-progress-msg').textContent = 'Extraindo texto das páginas do PDF...';
                    extractedText = (await extractTextFromPdfClient(file)) + (manualText ? '\n\n' + manualText : '');
                } else if (ext === 'docx') {
                    document.getElementById('doc-progress-msg').textContent = 'Extraindo texto do arquivo Word (.docx)...';
                    try {
                        extractedText = (await extractTextFromDocxClient(file)) + (manualText ? '\n\n' + manualText : '');
                    } catch (docxErr) {
                        // Se falhar no client, envia o arquivo para o fallback ZipArchive do PHP
                        formData.append('doc_file', file);
                    }
                } else if (ext === 'txt') {
                    extractedText = (await file.text()) + (manualText ? '\n\n' + manualText : '');
                } else {
                    throw new Error('Formato não suportado. Use arquivos .docx, .pdf ou .txt.');
                }
            }

            formData.append('raw_text', extractedText);
            document.getElementById('doc-progress-msg').innerHTML = 'A IA está estruturando os enunciados, alternativas e identificando figuras... <span class="text-xs opacity-75">(pode levar de 10 a 30 segundos)</span>';

            const response = await fetch('questions_import_doc_ajax.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (!result.success) {
                if (result.needs_api_key) {
                    const keyBox = document.getElementById('api-key-setup-box');
                    if (keyBox) keyBox.classList.remove('hidden');
                }
                throw new Error(result.message || 'Erro ao analisar o documento.');
            }

            // Se salvou chave via override com sucesso, oculta o box
            const keyBox = document.getElementById('api-key-setup-box');
            if (keyBox) keyBox.classList.add('hidden');

            extractedDocQuestions = result.questions || [];
            showDocReviewStep();

        } catch (err) {
            alertBox.className = 'p-4 rounded-xl text-sm font-medium bg-red-50 text-red-700 border border-red-200';
            alertBox.innerHTML = '<div class="flex items-start gap-2"><i class="ph-fill ph-warning-circle text-lg mt-0.5"></i> <div>' + (err.message || 'Erro ao processar documento.') + '</div></div>';
        } finally {
            btn.disabled = false;
        }
    }

    function backToDocUpload() {
        document.getElementById('step-doc-review').classList.add('hidden');
        document.getElementById('step-doc-review').classList.remove('flex');
        document.getElementById('step-doc-upload').classList.remove('hidden');
        document.getElementById('import-tabs-bar').classList.remove('hidden');
        document.getElementById('doc-import-alert').classList.add('hidden');
        document.getElementById('import-modal-title').innerHTML = '<i class="ph-fill ph-sparkle text-violet-600"></i> Importação Inteligente (Word / PDF com IA)';
    }

    function showDocReviewStep() {
        document.getElementById('import-tabs-bar').classList.add('hidden');
        document.getElementById('step-doc-upload').classList.add('hidden');
        const reviewStep = document.getElementById('step-doc-review');
        reviewStep.classList.remove('hidden');
        reviewStep.classList.add('flex');

        const courseName = document.getElementById('doc_course_name').value.trim();
        document.getElementById('import-modal-title').innerHTML = `<i class="ph-bold ph-list-checks text-violet-600"></i> Revisão das Questões Extraídas (${escapeHtml(courseName)})`;

        renderDocReviewList();
    }

    function escapeHtml(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function renderDocReviewList() {
        const container = document.getElementById('doc-review-list');
        container.innerHTML = '';

        const total = extractedDocQuestions.length;
        const needsImgCount = extractedDocQuestions.filter(q => q.needs_image).length;
        const aiKeyCount = extractedDocQuestions.filter(q => q.ai_solved_key).length;

        document.getElementById('review-count-badge').textContent = `✓ ${total} questão(ões) extraídas`;

        const imgBadge = document.getElementById('review-images-badge');
        if (needsImgCount > 0) {
            imgBadge.textContent = `📷 ${needsImgCount} pedem imagem`;
            imgBadge.classList.remove('hidden');
        } else {
            imgBadge.classList.add('hidden');
        }

        const aiKeyBadge = document.getElementById('review-ai-key-badge');
        if (aiKeyCount > 0) {
            aiKeyBadge.textContent = `🤖 ${aiKeyCount} gabarito(s) sugerido(s) pela IA`;
            aiKeyBadge.classList.remove('hidden');
        } else {
            aiKeyBadge.classList.add('hidden');
        }

        const btnSave = document.getElementById('btn-save-reviewed');
        btnSave.innerHTML = `<i class="ph-bold ph-floppy-disk"></i> Salvar ${total} Questão(ões) e Avançar`;
        btnSave.disabled = (total === 0);

        if (total === 0) {
            container.innerHTML = '<div class="text-center py-10 text-slate-400 text-sm">Nenhuma questão restante na lista. Volte para enviar outro arquivo.</div>';
            return;
        }

        const letters = ['A', 'B', 'C', 'D', 'E', 'F'];

        extractedDocQuestions.forEach((q, qIdx) => {
            const card = document.createElement('div');
            card.className = 'p-4 rounded-2xl border transition-all ' + (q.needs_image ? 'bg-amber-50/40 border-amber-300' : 'bg-white border-slate-200 shadow-sm');

            let optionsHtml = '';
            (q.options || []).forEach((opt, optIdx) => {
                const isCorr = !!opt.is_correct;
                const letter = letters[optIdx] || (optIdx + 1);
                optionsHtml += `
                    <div class="flex items-start gap-2.5 p-2 rounded-xl border transition-all ${isCorr ? 'bg-emerald-50 border-emerald-300' : 'bg-slate-50/70 border-slate-200'}">
                        <button type="button" onclick="setReviewCorrectOption(${qIdx}, ${optIdx})" title="Clique para definir esta como a alternativa correta" class="mt-0.5 w-6 h-6 rounded-lg font-extrabold text-xs flex items-center justify-center shrink-0 transition-all ${isCorr ? 'bg-emerald-600 text-white shadow-sm' : 'bg-white border border-slate-300 text-slate-500 hover:border-emerald-400'}">
                            ${letter}
                        </button>
                        <input type="text" value="${escapeHtml(opt.text)}" oninput="extractedDocQuestions[${qIdx}].options[${optIdx}].text = this.value" class="flex-1 bg-transparent text-xs font-medium ${isCorr ? 'text-emerald-950 font-bold' : 'text-slate-700'} outline-none border-b border-transparent focus:border-slate-300 py-0.5">
                        ${isCorr ? '<span class="text-[10px] font-extrabold text-emerald-700 bg-emerald-100 px-2 py-0.5 rounded-md shrink-0">Correta</span>' : ''}
                    </div>
                `;
            });

            card.innerHTML = `
                <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-xs font-extrabold px-2.5 py-0.5 rounded-lg bg-slate-800 text-white">Questão #${qIdx + 1}</span>
                        <button type="button" onclick="toggleReviewNeedsImage(${qIdx})" class="text-[11px] font-bold px-2.5 py-0.5 rounded-lg border transition-all flex items-center gap-1 ${q.needs_image ? 'bg-amber-100 text-amber-800 border-amber-300' : 'bg-slate-100 text-slate-500 border-slate-200 hover:bg-slate-200'}" title="Clique para marcar/desmarcar se esta questão precisa de imagem">
                            <i class="ph-fill ph-camera"></i> ${q.needs_image ? 'Pede Imagem (Destacada no próx. passo)' : 'Sem Imagem'}
                        </button>
                        ${q.ai_solved_key ? '<span class="text-[10px] font-bold px-2 py-0.5 rounded-lg bg-blue-100 text-blue-700" title="O documento não tinha gabarito explícito, então a IA resolveu a questão. Confira se a alternativa marcada em verde está correta.">🤖 Gabarito sugerido pela IA</span>' : ''}
                    </div>
                    <button type="button" onclick="removeReviewQuestion(${qIdx})" class="text-xs font-bold text-slate-400 hover:text-red-600 flex items-center gap-1 px-2 py-1 rounded-lg hover:bg-red-50 transition-colors">
                        <i class="ph-bold ph-trash"></i> Remover
                    </button>
                </div>

                <div class="mb-3">
                    <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Enunciado da Questão</label>
                    <textarea rows="3" oninput="extractedDocQuestions[${qIdx}].command = this.value" class="w-full p-2.5 bg-white border border-slate-200 rounded-xl text-xs text-slate-800 font-medium leading-relaxed outline-none focus:ring-2 focus:ring-violet-500">${escapeHtml(q.command)}</textarea>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5 mb-3">
                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-0.5">Módulo</label>
                        <input type="text" list="modules-datalist" value="${escapeHtml(q.module)}" oninput="extractedDocQuestions[${qIdx}].module = this.value" class="w-full px-2.5 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-xs text-slate-700 font-medium outline-none focus:bg-white focus:border-violet-400">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-0.5">Capacidade / Comp.</label>
                        <input type="text" value="${escapeHtml(q.capacity)}" placeholder="Ex: C1, H2..." oninput="extractedDocQuestions[${qIdx}].capacity = this.value" class="w-full px-2.5 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-xs text-slate-700 font-medium outline-none focus:bg-white focus:border-violet-400">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-0.5">Dificuldade</label>
                        <select onchange="extractedDocQuestions[${qIdx}].difficulty = this.value" class="w-full px-2.5 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-xs text-slate-700 font-medium outline-none focus:bg-white focus:border-violet-400">
                            <option value="Fácil" ${q.difficulty === 'Fácil' ? 'selected' : ''}>Fácil</option>
                            <option value="Médio" ${q.difficulty === 'Médio' ? 'selected' : ''}>Médio</option>
                            <option value="Difícil" ${q.difficulty === 'Difícil' ? 'selected' : ''}>Difícil</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1.5">Alternativas (Clique na letra A, B, C, D para trocar a correta)</label>
                    <div class="space-y-1.5">
                        ${optionsHtml}
                    </div>
                </div>
            `;

            container.appendChild(card);
        });
    }

    function setReviewCorrectOption(qIdx, correctOptIdx) {
        if (!extractedDocQuestions[qIdx]) return;
        extractedDocQuestions[qIdx].options.forEach((opt, idx) => {
            opt.is_correct = (idx === correctOptIdx);
        });
        extractedDocQuestions[qIdx].ai_solved_key = false; // Confirmado pelo professor
        renderDocReviewList();
    }

    function toggleReviewNeedsImage(qIdx) {
        if (!extractedDocQuestions[qIdx]) return;
        extractedDocQuestions[qIdx].needs_image = !extractedDocQuestions[qIdx].needs_image;
        renderDocReviewList();
    }

    function removeReviewQuestion(qIdx) {
        extractedDocQuestions.splice(qIdx, 1);
        renderDocReviewList();
    }

    async function saveReviewedQuestions() {
        const courseName = document.getElementById('doc_course_name').value.trim();
        const defaultModule = document.getElementById('doc_default_module').value.trim();
        const importTag = document.getElementById('doc_import_tag').value.trim();
        const btn = document.getElementById('btn-save-reviewed');
        const alertBox = document.getElementById('doc-review-alert');

        if (extractedDocQuestions.length === 0) return;

        btn.disabled = true;
        btn.innerHTML = '<i class="ph-bold ph-spinner animate-spin"></i> Salvando no Banco de Questões...';
        alertBox.classList.add('hidden');

        try {
            const response = await fetch('questions_import_doc_ajax.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'save_questions',
                    course_name: courseName,
                    default_module: defaultModule,
                    import_tag: importTag,
                    questions: extractedDocQuestions
                })
            });

            const result = await response.json();

            if (!result.success) {
                throw new Error(result.message || 'Erro ao salvar questões.');
            }

            importedQuestionsGlobal = result.imported_questions || [];
            showLinkImagesStep(result.count || importedQuestionsGlobal.length);

        } catch (err) {
            alertBox.className = 'mb-4 p-3.5 rounded-xl text-xs font-bold bg-red-50 text-red-700 border border-red-200';
            alertBox.innerHTML = '<i class="ph-fill ph-warning-circle"></i> ' + (err.message || 'Erro de comunicação ao salvar questões.');
            alertBox.classList.remove('hidden');
            btn.disabled = false;
            btn.innerHTML = `<i class="ph-bold ph-floppy-disk"></i> Confirmar e Salvar Questões`;
        }
    }

    async function submitImport(e) {
        e.preventDefault();
        
        const form = document.getElementById('importForm');
        const btn = document.getElementById('btn-importar');
        const alertBox = document.getElementById('import-alert');
        const formData = new FormData(form);
        
        btn.disabled = true;
        btn.innerHTML = '<i class="ph-bold ph-spinner animate-spin"></i> Processando...';
        alertBox.classList.add('hidden');
        alertBox.className = 'mt-4 p-4 rounded-xl text-sm font-medium';
        
        try {
            const response = await fetch('questions_import_ajax.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                importedQuestionsGlobal = result.imported_questions || [];
                
                if (importedQuestionsGlobal.length > 0) {
                    showLinkImagesStep(result.count || importedQuestionsGlobal.length);
                } else {
                    alertBox.classList.remove('hidden');
                    alertBox.classList.add('bg-green-50', 'text-green-700', 'border', 'border-green-200');
                    alertBox.innerHTML = '<div class="flex items-start gap-2"><i class="ph-fill ph-check-circle text-lg mt-0.5"></i> <div>' + result.message + '</div></div>';
                    setTimeout(() => window.location.reload(), 2000);
                }
            } else {
                alertBox.classList.remove('hidden');
                alertBox.classList.add('bg-red-50', 'text-red-700', 'border', 'border-red-200');
                alertBox.innerHTML = '<div class="flex items-start gap-2"><i class="ph-fill ph-warning-circle text-lg mt-0.5"></i> <div>' + result.message + '</div></div>';
                btn.disabled = false;
                btn.innerHTML = '<i class="ph-bold ph-check"></i> Processar Importação';
            }
        } catch (error) {
            alertBox.classList.remove('hidden');
            alertBox.classList.add('bg-red-50', 'text-red-700', 'border', 'border-red-200');
            alertBox.innerHTML = '<div class="flex items-start gap-2"><i class="ph-fill ph-warning-circle text-lg mt-0.5"></i> <div>Erro de comunicação com o servidor.</div></div>';
            btn.disabled = false;
            btn.innerHTML = '<i class="ph-bold ph-check"></i> Processar Importação';
        }
    }

    function showLinkImagesStep(count) {
        document.getElementById('import-tabs-bar').classList.add('hidden');
        document.getElementById('step-csv-upload').classList.add('hidden');
        document.getElementById('step-doc-upload').classList.add('hidden');
        document.getElementById('step-doc-review').classList.add('hidden');
        document.getElementById('step-doc-review').classList.remove('flex');

        const step2 = document.getElementById('step-link-images');
        step2.classList.remove('hidden');
        step2.classList.add('flex');

        // Ordena para que as questões que pedem imagem apareçam primeiro no topo!
        importedQuestionsGlobal.sort((a, b) => (b.needs_image ? 1 : 0) - (a.needs_image ? 1 : 0));

        const needsImgTotal = importedQuestionsGlobal.filter(q => q.needs_image).length;
        const needsBadge = document.getElementById('imported-needs-image-badge');
        if (needsImgTotal > 0) {
            needsBadge.textContent = `📷 ${needsImgTotal} questão(ões) pedem imagem no texto`;
            needsBadge.classList.remove('hidden');
        } else {
            needsBadge.classList.add('hidden');
        }

        onlyNeedsImageFilter = false;
        updateNeedsImgFilterBtn();
        
        document.getElementById('import-modal-title').innerHTML = '<i class="ph-bold ph-images text-indigo-600"></i> Vincular Imagens às Questões Importadas';
        document.getElementById('imported-count-badge').textContent = `✓ ${count} questão(ões) salvas com sucesso`;
        document.getElementById('imported-list-counter').textContent = `${importedQuestionsGlobal.length} questão(ões)`;
        
        renderImportedQuestionsList(importedQuestionsGlobal);
    }

    function toggleFilterNeedsImage() {
        onlyNeedsImageFilter = !onlyNeedsImageFilter;
        updateNeedsImgFilterBtn();
        filterImportedList(document.getElementById('filter-imported-input').value);
    }

    function updateNeedsImgFilterBtn() {
        const btn = document.getElementById('btn-filter-needs-img');
        const txt = document.getElementById('btn-filter-needs-img-text');
        if (!btn || !txt) return;
        if (onlyNeedsImageFilter) {
            btn.className = 'px-3.5 py-2 rounded-xl border border-amber-500 bg-amber-500 text-white text-xs font-bold transition-all flex items-center justify-center gap-1.5 shrink-0 shadow-sm';
            txt.textContent = 'Mostrando apenas que pedem imagem (Ver todas)';
        } else {
            btn.className = 'px-3.5 py-2 rounded-xl border border-amber-300 bg-amber-50 text-amber-800 hover:bg-amber-100 text-xs font-bold transition-all flex items-center justify-center gap-1.5 shrink-0';
            txt.textContent = 'Apenas que pedem imagem';
        }
    }

    function renderImportedQuestionsList(list) {
        const container = document.getElementById('imported-questions-list');
        container.innerHTML = '';
        
        if (list.length === 0) {
            container.innerHTML = '<div class="text-center py-8 text-slate-400 text-sm">Nenhuma questão encontrada com o filtro atual.</div>';
            return;
        }
        
        list.forEach((q, index) => {
            const card = document.createElement('div');
            card.id = `q-imported-card-${q.id}`;
            const highlightClass = q.needs_image
                ? 'bg-amber-50/60 hover:bg-amber-50 border-amber-300'
                : 'bg-slate-50/70 hover:bg-slate-50 border-slate-200';
            card.className = `p-3.5 border rounded-2xl transition-all flex flex-col md:flex-row md:items-center justify-between gap-3 ${highlightClass}`;
            
            card.innerHTML = `
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1.5 flex-wrap">
                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-slate-200 text-slate-700 uppercase">#${index + 1}</span>
                        ${q.needs_image ? `<span class="text-[10px] font-extrabold px-2 py-0.5 rounded-md bg-amber-200/80 text-amber-900 flex items-center gap-1"><i class="ph-fill ph-camera"></i> Pede imagem no enunciado</span>` : ''}
                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-indigo-100 text-indigo-700 truncate max-w-[200px]" title="${escapeHtml(q.module)}">${escapeHtml(q.module)}</span>
                        ${q.capacity ? `<span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-senai-cyan/15 text-senai-blue">CAP: ${escapeHtml(q.capacity)}</span>` : ''}
                    </div>
                    <p class="text-xs font-semibold text-slate-800 line-clamp-2" title="${escapeHtml(q.command)}">${escapeHtml(q.command)}</p>
                </div>
                
                <div class="shrink-0 flex items-center gap-2" id="q-upload-zone-${q.id}">
                    <input type="file" id="import-file-input-${q.id}" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" class="hidden" onchange="uploadImageForImportedQ(${q.id}, this)">
                    <button type="button" onclick="document.getElementById('import-file-input-${q.id}').click()" class="px-3.5 py-2 bg-white hover:bg-indigo-50 text-indigo-600 hover:text-indigo-700 border border-indigo-200 rounded-xl text-xs font-bold transition-all flex items-center gap-1.5 shadow-sm active:scale-95">
                        <i class="ph-bold ph-cloud-arrow-up text-sm"></i> Vincular Imagem
                    </button>
                </div>
            `;
            container.appendChild(card);
        });
    }

    function filterImportedList(term) {
        term = (term || '').toLowerCase().trim();
        let filtered = importedQuestionsGlobal;

        if (onlyNeedsImageFilter) {
            filtered = filtered.filter(q => !!q.needs_image);
        }

        if (term) {
            filtered = filtered.filter(q => 
                (q.command && q.command.toLowerCase().includes(term)) ||
                (q.module && q.module.toLowerCase().includes(term)) ||
                (q.capacity && q.capacity.toLowerCase().includes(term))
            );
        }

        document.getElementById('imported-list-counter').textContent = `${filtered.length} questão(ões)`;
        renderImportedQuestionsList(filtered);
    }

    async function uploadImageForImportedQ(questionId, input) {
        if (!input.files || !input.files[0]) return;
        const file = input.files[0];
        
        if (file.size > 10 * 1024 * 1024) {
            alert('A imagem deve ter no máximo 10MB.');
            input.value = '';
            return;
        }
        
        const zone = document.getElementById(`q-upload-zone-${questionId}`);
        const originalContent = zone.innerHTML;
        zone.innerHTML = `<span class="text-xs font-bold text-indigo-600 flex items-center gap-1"><i class="ph-bold ph-spinner animate-spin"></i> Enviando...</span>`;
        
        const formData = new FormData();
        formData.append('action', 'upload');
        formData.append('question_id', questionId);
        formData.append('image', file);
        
        try {
            const res = await fetch('question_image_ajax.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            
            if (data.success) {
                zone.innerHTML = `
                    <div class="flex items-center gap-2">
                        <img src="${data.image_url}" alt="Preview" class="w-10 h-10 object-contain rounded-lg border border-slate-200 bg-white">
                        <span class="text-xs font-bold text-emerald-600 flex items-center gap-1"><i class="ph-bold ph-check-circle text-emerald-500"></i> Vinculada</span>
                        <button type="button" onclick="removeImageForImportedQ(${questionId})" class="p-1.5 text-slate-400 hover:text-red-500 rounded-lg hover:bg-red-50 transition-colors" title="Remover imagem">
                            <i class="ph-bold ph-trash"></i>
                        </button>
                    </div>
                `;
            } else {
                alert(data.error || 'Erro ao enviar imagem.');
                zone.innerHTML = originalContent;
            }
        } catch (err) {
            alert('Erro de comunicação ao enviar imagem.');
            zone.innerHTML = originalContent;
        }
    }

    async function removeImageForImportedQ(questionId) {
        if (!confirm('Deseja remover a imagem vinculada a esta questão?')) return;
        
        const zone = document.getElementById(`q-upload-zone-${questionId}`);
        zone.innerHTML = `<span class="text-xs font-bold text-slate-400 flex items-center gap-1"><i class="ph-bold ph-spinner animate-spin"></i> Removendo...</span>`;
        
        try {
            const formData = new FormData();
            formData.append('action', 'remove');
            formData.append('question_id', questionId);
            
            const res = await fetch('question_image_ajax.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            
            if (data.success) {
                zone.innerHTML = `
                    <input type="file" id="import-file-input-${questionId}" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" class="hidden" onchange="uploadImageForImportedQ(${questionId}, this)">
                    <button type="button" onclick="document.getElementById('import-file-input-${questionId}').click()" class="px-3.5 py-2 bg-white hover:bg-indigo-50 text-indigo-600 hover:text-indigo-700 border border-indigo-200 rounded-xl text-xs font-bold transition-all flex items-center gap-1.5 shadow-sm active:scale-95">
                        <i class="ph-bold ph-cloud-arrow-up text-sm"></i> Vincular Imagem
                    </button>
                `;
            } else {
                alert(data.error || 'Erro ao remover imagem.');
            }
        } catch (err) {
            alert('Erro de comunicação.');
        }
    }
</script>

<?php include 'includes/footer.php'; ?>
