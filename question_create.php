<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
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
            
            // Processa imagem de apoio se enviada
            $image_url = null;
            if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['image_file']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
                if (in_array($ext, $allowed) && $_FILES['image_file']['size'] <= 10 * 1024 * 1024) {
                    $uploadDir = __DIR__ . '/uploads/questions/';
                    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
                    $newFileName = 'q_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (move_uploaded_file($_FILES['image_file']['tmp_name'], $uploadDir . $newFileName)) {
                        $image_url = 'uploads/questions/' . $newFileName;
                    }
                }
            }

            // Insert Question com image_url opcional
            $stmtQ = $pdo->prepare("INSERT INTO questions (module_id, capacity, context, command, difficulty, image_url, teacher_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmtQ->execute([$module_id, $capacity, $context, $command, $difficulty, $image_url, $_SESSION['user_id']]);
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

<div id="app-screen" class="absolute inset-0 z-40 flex w-full h-full bg-[#F8FAFC]">
    
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

                <form method="POST" action="question_create.php" enctype="multipart/form-data" class="bg-white/80 backdrop-blur-md border border-white/80 rounded-3xl p-6 md:p-10 shadow-sm animate-fade-in delay-200">
                    
                    <div class="space-y-8">
                        <!-- Metadados da Questão -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div class="md:col-span-2">
                                <label class="block text-sm font-bold text-slate-700 mb-2">Unidade Curricular (Módulo)</label>
                                <select name="module_id" required class="search-select w-full px-5 py-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-senai-blue/20 focus:border-senai-blue bg-slate-50/50 font-medium text-slate-700">
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

                        <!-- Imagem de Apoio (Opcional) -->
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2 flex items-center justify-between">
                                <span class="flex items-center gap-2">
                                    <i class="ph-bold ph-image text-indigo-600"></i> Imagem de Apoio
                                    <span class="text-slate-400 font-normal text-xs">(Opcional)</span>
                                </span>
                                <span class="text-[11px] text-slate-400 font-medium">Circuitos, diagramas, esquemas ou tabelas</span>
                            </label>
                            
                            <div class="border-2 border-dashed border-slate-200 hover:border-senai-blue/50 rounded-2xl p-5 text-center transition-all bg-slate-50/50 cursor-pointer" onclick="document.getElementById('image_file_input').click()">
                                <input type="file" id="image_file_input" name="image_file" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" class="hidden" onchange="previewManualImage(this)">
                                
                                <div id="image-placeholder">
                                    <div class="w-12 h-12 rounded-full bg-indigo-50 text-indigo-600 flex items-center justify-center mx-auto mb-2 shadow-inner">
                                        <i class="ph-bold ph-cloud-arrow-up text-2xl"></i>
                                    </div>
                                    <p class="text-xs font-bold text-slate-700">Clique para selecionar uma imagem da sua máquina</p>
                                    <p class="text-[10px] text-slate-400 mt-0.5">Formatos aceitos: PNG, JPG, WEBP, GIF, SVG (Máx: 10MB)</p>
                                </div>
                                
                                <div id="image-preview-container" class="hidden relative">
                                    <img id="image-preview" src="#" alt="Preview da Imagem" class="max-h-56 mx-auto rounded-xl border border-slate-200 shadow-sm object-contain bg-white">
                                    <button type="button" onclick="removeManualImage(event)" class="absolute top-2 right-2 bg-red-500 hover:bg-red-600 text-white rounded-full p-2 shadow-md transition-colors" title="Remover imagem">
                                        <i class="ph-bold ph-trash text-xs"></i>
                                    </button>
                                </div>
                            </div>
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

<script>
function previewManualImage(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        if (file.size > 10 * 1024 * 1024) {
            alert('A imagem deve ter no máximo 10MB.');
            input.value = '';
            return;
        }
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('image-preview').src = e.target.result;
            document.getElementById('image-preview-container').classList.remove('hidden');
            document.getElementById('image-placeholder').classList.add('hidden');
        };
        reader.readAsDataURL(file);
    }
}

function removeManualImage(e) {
    e.stopPropagation();
    const input = document.getElementById('image_file_input');
    input.value = '';
    document.getElementById('image-preview').src = '#';
    document.getElementById('image-preview-container').classList.add('hidden');
    document.getElementById('image-placeholder').classList.remove('hidden');
}
</script>

<?php include 'includes/footer.php'; ?>
