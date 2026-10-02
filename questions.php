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
                    <div class="mt-4 md:mt-0 flex flex-wrap items-center gap-3">
                        <button onclick="openModal('importModal')" class="bg-indigo-50 text-indigo-600 hover:bg-indigo-100 border border-indigo-200 px-4 py-2.5 rounded-xl font-bold text-sm transition-all flex items-center gap-2">
                            <i class="ph-bold ph-upload-simple"></i> Importar JSON
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

<!-- Modal de Importação JSON -->
<div id="importModal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-xl w-full max-w-2xl overflow-hidden scale-95 opacity-0 transition-all duration-300 flex flex-col max-h-[90vh]" id="importModalContent">
        
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50 shrink-0">
            <h3 class="font-bold text-lg text-slate-800 flex items-center gap-2"><i class="ph-bold ph-upload-simple text-indigo-500"></i> Importação em Massa (JSON)</h3>
            <button onclick="closeModal('importModal')" class="text-slate-400 hover:text-red-500 transition-colors"><i class="ph-bold ph-x text-xl"></i></button>
        </div>
        
        <div class="p-6 overflow-y-auto flex-1">
            <div class="bg-indigo-50 border border-indigo-100 rounded-xl p-5 mb-6 text-sm text-indigo-900">
                <h4 class="font-bold mb-2 flex items-center gap-2"><i class="ph-fill ph-info"></i> Como Importar?</h4>
                <p class="mb-3">Para cadastrar dezenas de questões de uma vez, você precisa preencher um arquivo estruturado no formato JSON. Siga as regras:</p>
                <ul class="list-disc pl-5 space-y-1.5 mb-4 text-indigo-800">
                    <li>Baixe o template abaixo e não altere o nome das "chaves" estruturais (ex: "curso", "questoes", "alternativas").</li>
                    <li>Se o <b>Curso</b> ou <b>Módulo</b> digitado no JSON já existir no sistema (com a exata mesma escrita), as questões serão <b>adicionadas</b> a ele.</li>
                    <li>Se não existirem, o sistema os criará automaticamente.</li>
                    <li>Salve o arquivo no formato <code>.json</code> e faça o upload abaixo.</li>
                </ul>
                
                <a href="template_questoes.json" download class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg font-bold transition-all shadow-sm">
                    <i class="ph-bold ph-download-simple"></i> Baixar Template JSON
                </a>
            </div>

            <form id="importForm" onsubmit="submitImport(event)">
                <div class="border-2 border-dashed border-slate-300 rounded-2xl p-8 text-center hover:bg-slate-50 hover:border-indigo-400 transition-all cursor-pointer relative" id="dropzone">
                    <input type="file" id="json_file" name="json_file" accept=".json" required class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" onchange="updateFileName(this)">
                    
                    <div id="upload-icon" class="w-16 h-16 rounded-full bg-indigo-50 flex items-center justify-center text-indigo-500 mx-auto mb-4">
                        <i class="ph-fill ph-file-json text-3xl"></i>
                    </div>
                    
                    <h4 class="font-bold text-slate-700 text-lg mb-1" id="file-title">Selecione o arquivo .json</h4>
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
    </div>
</div>

<script>
    function openModal(id) {
        const modal = document.getElementById(id);
        const content = document.getElementById(id + 'Content');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        
        // Reset form
        document.getElementById('importForm').reset();
        document.getElementById('file-title').textContent = 'Selecione o arquivo .json';
        document.getElementById('file-desc').textContent = 'ou arraste e solte ele aqui';
        document.getElementById('import-alert').classList.add('hidden');
        
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
        }, 300);
    }

    function updateFileName(input) {
        if(input.files && input.files[0]) {
            document.getElementById('file-title').textContent = input.files[0].name;
            document.getElementById('file-desc').textContent = (input.files[0].size / 1024).toFixed(2) + ' KB';
        }
    }

    async function submitImport(e) {
        e.preventDefault();
        
        const form = document.getElementById('importForm');
        const btn = document.getElementById('btn-importar');
        const alertBox = document.getElementById('import-alert');
        const formData = new FormData(form);
        
        // Loading state
        btn.disabled = true;
        btn.innerHTML = '<i class="ph-bold ph-spinner animate-spin"></i> Processando...';
        alertBox.classList.add('hidden');
        alertBox.className = 'mt-4 p-4 rounded-xl text-sm font-medium'; // reset classes
        
        try {
            const response = await fetch('questions_import_ajax.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            alertBox.classList.remove('hidden');
            if(result.success) {
                alertBox.classList.add('bg-green-50', 'text-green-700', 'border', 'border-green-200');
                alertBox.innerHTML = '<div class="flex items-start gap-2"><i class="ph-fill ph-check-circle text-lg mt-0.5"></i> <div>' + result.message + '</div></div>';
                
                // Recarrega a página após 2 segundos para atualizar a lista
                setTimeout(() => window.location.reload(), 2000);
            } else {
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
</script>

<?php include 'includes/footer.php'; ?>
