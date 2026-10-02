<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

$currentPage = 'import_saep';
include 'includes/header.php';
?>

<div id="app-screen" class="absolute inset-0 z-40 flex w-full h-full bg-[#F8FAFC]">
    
    <!-- Background Decorativo -->
    <div class="fixed inset-0 w-full h-full overflow-hidden pointer-events-none z-0">
        <div class="absolute top-[-5%] right-[-5%] w-[500px] h-[500px] bg-senai-cyan/15 rounded-full mix-blend-multiply filter blur-[80px] opacity-70 animate-blob"></div>
        <div class="absolute bottom-[-5%] left-[-5%] w-[600px] h-[600px] bg-senai-blue/10 rounded-full mix-blend-multiply filter blur-[80px] opacity-50 animate-blob"></div>
    </div>

    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <main id="main-content" class="flex-1 flex flex-col relative h-full w-full">
        <!-- Header Mobile -->
        <header class="h-14 flex items-center px-4 md:px-6 absolute top-0 w-full z-20 pointer-events-none">
            <button onclick="toggleSidebar()" class="pointer-events-auto md:hidden w-10 h-10 flex items-center justify-center bg-white/50 hover:bg-white rounded-xl backdrop-blur-md shadow-sm border border-white/60 transition-all active:scale-95 text-senai-dark">
                <i id="sidebar-icon" class="ph-bold ph-list text-xl"></i>
            </button>
        </header>

        <div class="flex-1 overflow-y-auto w-full flex flex-col pt-20 pb-20 relative z-10 px-4 md:px-8">
            <div class="w-full max-w-4xl mx-auto animate-fade-in">
                
                <div class="mb-8">
                    <h1 class="text-3xl font-bold tracking-tight mb-2 text-slate-800">Importação do SAEP</h1>
                    <p class="text-slate-500 font-medium">Faça o upload da planilha oficial do SAEP para alimentar o aiS automaticamente.</p>
                </div>

                <div class="bg-white/80 backdrop-blur-xl border border-white/80 p-8 rounded-3xl shadow-lg relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-indigo-500/10 rounded-full blur-2xl"></div>
                    
                    <div class="relative z-10">
                        <div class="bg-indigo-50 border border-indigo-100 rounded-2xl p-5 mb-8 flex gap-4">
                            <div class="w-12 h-12 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center shrink-0">
                                <i class="ph-fill ph-info text-2xl"></i>
                            </div>
                            <div>
                                <h4 class="font-bold text-slate-800 text-sm mb-1">Qual arquivo devo enviar?</h4>
                                <p class="text-sm text-slate-600 leading-relaxed">Você deve enviar o arquivo <strong>"Desemp. Ind. por Registro.csv"</strong> gerado pelo portal do SAEP. Ao fazer isso, o aiS irá automaticamente criar as Turmas, os Alunos, o Caderno de Provas e computar as respostas de cada um, gerando os relatórios avançados!</p>
                            </div>
                        </div>

                        <form action="import_saep_process.php" method="POST" enctype="multipart/form-data" id="import-form">
                            <!-- Drag & Drop Area -->
                            <div id="drop-area" class="border-2 border-dashed border-slate-300 rounded-2xl p-10 flex flex-col items-center justify-center text-center cursor-pointer hover:bg-slate-50 hover:border-indigo-400 transition-all group">
                                <input type="file" name="saep_file" id="saep_file" accept=".csv" class="hidden" required onchange="handleFile(this.files)">
                                
                                <div class="w-20 h-20 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mb-4 group-hover:bg-indigo-50 group-hover:text-indigo-500 transition-colors">
                                    <i class="ph-bold ph-upload-simple text-3xl"></i>
                                </div>
                                <h3 class="text-lg font-bold text-slate-700 mb-2" id="file-name">Clique para escolher ou arraste o CSV aqui</h3>
                                <p class="text-sm text-slate-500">Apenas arquivos .csv são suportados.</p>
                            </div>

                            <div class="mt-8 flex justify-end">
                                <button type="submit" id="submit-btn" disabled class="bg-senai-blue text-white px-8 py-3.5 rounded-xl font-bold shadow-sm shadow-senai-blue/30 hover:shadow-md hover:bg-blue-700 transition-all active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2">
                                    <i class="ph-bold ph-magic-wand"></i> Processar Importação
                                </button>
                            </div>
                        </form>
                        
                        <!-- Overlay de Carregamento -->
                        <div id="loading-overlay" class="absolute inset-0 bg-white/90 backdrop-blur-sm z-20 flex flex-col items-center justify-center hidden rounded-3xl">
                            <div class="w-16 h-16 border-4 border-indigo-500 border-t-transparent rounded-full animate-spin mb-4"></div>
                            <h3 class="text-lg font-bold text-slate-800">Processando planilha...</h3>
                            <p class="text-slate-500 font-medium text-sm mt-2">Isso pode levar alguns segundos. Por favor, não feche a janela.</p>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>
</div>

<script>
    const dropArea = document.getElementById('drop-area');
    const fileInput = document.getElementById('saep_file');
    const fileNameDisplay = document.getElementById('file-name');
    const submitBtn = document.getElementById('submit-btn');
    const form = document.getElementById('import-form');
    const loadingOverlay = document.getElementById('loading-overlay');

    // Clicar na área aciona o input invisível
    dropArea.addEventListener('click', () => {
        fileInput.click();
    });

    // Drag and Drop
    dropArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropArea.classList.add('bg-indigo-50', 'border-indigo-500');
    });

    dropArea.addEventListener('dragleave', () => {
        dropArea.classList.remove('bg-indigo-50', 'border-indigo-500');
    });

    dropArea.addEventListener('drop', (e) => {
        e.preventDefault();
        dropArea.classList.remove('bg-indigo-50', 'border-indigo-500');
        
        if (e.dataTransfer.files.length) {
            fileInput.files = e.dataTransfer.files;
            handleFile(e.dataTransfer.files);
        }
    });

    function handleFile(files) {
        if (files.length > 0) {
            const file = files[0];
            if (file.type !== 'text/csv' && !file.name.endsWith('.csv')) {
                alert("Por favor, envie apenas arquivos .csv");
                fileInput.value = "";
                submitBtn.disabled = true;
                fileNameDisplay.innerText = "Clique para escolher ou arraste o CSV aqui";
                return;
            }
            fileNameDisplay.innerText = file.name;
            fileNameDisplay.classList.add('text-indigo-600');
            submitBtn.disabled = false;
        }
    }

    form.addEventListener('submit', () => {
        if(!submitBtn.disabled) {
            loadingOverlay.classList.remove('hidden');
        }
    });
</script>

<?php include 'includes/footer.php'; ?>
