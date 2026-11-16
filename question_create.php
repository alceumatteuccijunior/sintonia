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
    
    if ($module_id && $capacity && $command && $correct_index !== '') {
        // Garante coluna image_url em question_options ANTES da transação
        try {
            $stmtCol = $pdo->query("SHOW COLUMNS FROM question_options LIKE 'image_url'");
            if ($stmtCol && !$stmtCol->fetch()) {
                $pdo->exec("ALTER TABLE question_options ADD COLUMN image_url VARCHAR(500) NULL DEFAULT NULL AFTER text");
            }
        } catch (Exception $e) {}

        try {
            $pdo->beginTransaction();
            
            $uploadDir = __DIR__ . '/uploads/questions/';
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
            $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];

            // Processa imagem de apoio se enviada
            $image_url = null;
            if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['image_file']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, $allowed) && $_FILES['image_file']['size'] <= 10 * 1024 * 1024) {
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
            
            // Insert Options (com texto e/ou imagem por alternativa)
            $stmtO = $pdo->prepare("INSERT INTO question_options (question_id, text, image_url, is_correct) VALUES (?, ?, ?, ?)");
            for ($index = 0; $index < 5; $index++) {
                $text = isset($options[$index]) ? trim($options[$index]) : '';
                $opt_img_url = null;
                if (isset($_FILES['option_images']['error'][$index]) && $_FILES['option_images']['error'][$index] === UPLOAD_ERR_OK) {
                    $oExt = strtolower(pathinfo($_FILES['option_images']['name'][$index], PATHINFO_EXTENSION));
                    if (in_array($oExt, $allowed) && $_FILES['option_images']['size'][$index] <= 10 * 1024 * 1024) {
                        $oFileName = 'opt_' . $question_id . '_' . $index . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $oExt;
                        if (move_uploaded_file($_FILES['option_images']['tmp_name'][$index], $uploadDir . $oFileName)) {
                            $opt_img_url = 'uploads/questions/' . $oFileName;
                        }
                    }
                }
                if ($text !== '' || $opt_img_url !== null) {
                    $is_correct = ($index == $correct_index) ? 1 : 0;
                    $stmtO->execute([$question_id, $text, $opt_img_url, $is_correct]);
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
$stmt = $pdo->query("SELECT m.id, m.course_id, m.name as module_name, c.name as course_name FROM modules m JOIN courses c ON m.course_id = c.id ORDER BY c.name, m.name");
$modules = $stmt->fetchAll();

// Carrega capacidades oficiais da Matriz SAEP por curso
$courseCapsByCourseId = [];
try {
    $stmtCC = $pdo->query("
        SELECT course_id, capacity_code, description
        FROM course_capacities
        ORDER BY course_id, CAST(SUBSTRING(capacity_code, 2) AS UNSIGNED) ASC, capacity_code ASC
    ");
    if ($stmtCC) {
        foreach ($stmtCC->fetchAll() as $rowCC) {
            $courseCapsByCourseId[$rowCC['course_id']][] = [
                'code' => $rowCC['capacity_code'],
                'desc' => $rowCC['description']
            ];
        }
    }
} catch (Exception $e) {}
$moduleToCourseMap = [];
foreach ($modules as $modRow) {
    $moduleToCourseMap[$modRow['id']] = (int)$modRow['course_id'];
}

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
                                <select name="module_id" id="manual-module-select" onchange="onManualModuleChange(this.value)" required class="search-select w-full px-5 py-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-senai-blue/20 focus:border-senai-blue bg-slate-50/50 font-medium text-slate-700">
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
                                        <option value="<?= $m['id'] ?>" data-course-id="<?= $m['course_id'] ?>"><?= htmlspecialchars($m['module_name']) ?></option>
                                    <?php endforeach; if ($current_course !== '') echo "</optgroup>"; ?>
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

                        <!-- Estrutura da Questão: Capacidade Oficial da Matriz -->
                        <div class="p-4 rounded-2xl bg-emerald-50/60 border border-emerald-200">
                            <label class="block text-sm font-bold text-emerald-950 mb-2 flex items-center gap-1.5">
                                <i class="ph-fill ph-lock-key text-emerald-600"></i> Capacidade Oficial da Matriz SAEP (Inviolável)
                            </label>
                            <select name="capacity" id="manual-capacity-select" onchange="onManualCapacityChange(this.value)" required class="w-full px-4 py-3 rounded-xl border border-emerald-300 focus:outline-none focus:ring-2 focus:ring-emerald-500 bg-white font-bold text-slate-800 text-sm">
                                <option value="" disabled selected>Selecione primeiro a Unidade Curricular acima...</option>
                            </select>
                            <div id="manual-capacity-desc" class="mt-2 p-3 rounded-xl bg-white/90 border border-emerald-100 text-xs text-emerald-900 font-medium leading-relaxed hidden"></div>
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
                            <h3 class="font-bold text-slate-800 mb-2 flex items-center gap-2">
                                <i class="ph-fill ph-list-dashes text-senai-orange"></i> Alternativas (Texto e/ou Imagem)
                            </h3>
                            <p class="text-xs text-slate-500 mb-4">Escreva o texto e/ou anexe uma imagem para cada alternativa, e marque a bolinha verde correspondente à resposta correta.</p>
                            
                            <div class="space-y-3" id="options-container">
                                <?php for ($i = 0; $i < 5; $i++): $letter = chr(65 + $i); ?>
                                    <div class="bg-slate-50/50 p-2.5 pl-4 rounded-xl border border-slate-200 hover:border-slate-300 transition-colors">
                                        <div class="flex items-center gap-3">
                                            <span class="font-bold text-slate-400 w-4"><?= $letter ?></span>
                                            <input type="text" name="options[<?= $i ?>]" placeholder="Texto da alternativa <?= $letter ?> (opcional se anexar imagem)..." class="flex-1 bg-transparent border-none focus:ring-0 text-slate-700 font-medium py-1.5 text-sm">
                                            <input type="file" id="opt_img_<?= $i ?>" name="option_images[<?= $i ?>]" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" class="hidden" onchange="previewManualOptionImage(<?= $i ?>, this)">
                                            <button type="button" onclick="document.getElementById('opt_img_<?= $i ?>').click()" class="px-2.5 py-1.5 rounded-lg bg-white hover:bg-indigo-50 text-indigo-600 border border-indigo-200 text-xs font-bold flex items-center gap-1 shrink-0" title="Anexar imagem na alternativa <?= $letter ?>">
                                                <i class="ph-bold ph-image"></i> <span id="opt_img_btn_lbl_<?= $i ?>">+ Imagem</span>
                                            </button>
                                            <div class="pr-3 pl-3 border-l border-slate-200 flex items-center justify-center">
                                                <input type="radio" name="correct_index" value="<?= $i ?>" <?= $i === 0 ? 'required' : '' ?> class="w-5 h-5 text-green-500 border-slate-300 focus:ring-green-500 hover:scale-110 transition-transform cursor-pointer" title="Marcar como Correta">
                                            </div>
                                        </div>
                                        <div id="opt_img_preview_box_<?= $i ?>" class="hidden pl-7 pt-2 flex items-center gap-3">
                                            <img id="opt_img_preview_<?= $i ?>" src="#" alt="Preview <?= $letter ?>" class="h-14 w-auto object-contain rounded-lg border border-slate-200 bg-white p-0.5 shadow-sm">
                                            <button type="button" onclick="removeManualOptionImage(<?= $i ?>)" class="text-xs font-bold text-red-500 hover:underline flex items-center gap-1">
                                                <i class="ph-bold ph-trash"></i> Remover imagem
                                            </button>
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
const courseCapsByCourseId = <?= json_encode($courseCapsByCourseId, JSON_UNESCAPED_UNICODE) ?>;
const moduleToCourseMap = <?= json_encode($moduleToCourseMap) ?>;
let currentManualCourseCaps = [];

function onManualModuleChange(moduleId) {
    const courseId = moduleToCourseMap[moduleId];
    const capSelect = document.getElementById('manual-capacity-select');
    const descBox = document.getElementById('manual-capacity-desc');
    capSelect.innerHTML = '';
    descBox.classList.add('hidden');

    currentManualCourseCaps = (courseId && courseCapsByCourseId[courseId]) ? courseCapsByCourseId[courseId] : [];
    if (currentManualCourseCaps.length === 0) {
        capSelect.innerHTML = '<option value="" disabled selected>Este curso ainda não possui Matriz SAEP (.xls) vinculada no Banco de Questões</option>';
        return;
    }

    currentManualCourseCaps.forEach((c, idx) => {
        const opt = document.createElement('option');
        opt.value = c.code;
        const shortD = c.desc.length > 90 ? c.desc.substring(0, 90) + '...' : c.desc;
        opt.textContent = `${c.code} — ${shortD}`;
        if (idx === 0) opt.selected = true;
        capSelect.appendChild(opt);
    });
    onManualCapacityChange(currentManualCourseCaps[0].code);
}

function onManualCapacityChange(code) {
    const descBox = document.getElementById('manual-capacity-desc');
    const found = currentManualCourseCaps.find(c => c.code === code);
    if (found) {
        descBox.textContent = `${found.code}: ${found.desc}`;
        descBox.classList.remove('hidden');
    } else {
        descBox.classList.add('hidden');
    }
}

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
function previewManualOptionImage(idx, input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        if (file.size > 10 * 1024 * 1024) {
            alert('A imagem deve ter no máximo 10MB.');
            input.value = '';
            return;
        }
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('opt_img_preview_' + idx).src = e.target.result;
            document.getElementById('opt_img_preview_box_' + idx).classList.remove('hidden');
            document.getElementById('opt_img_btn_lbl_' + idx).textContent = 'Trocar Img';
        };
        reader.readAsDataURL(file);
    }
}

function removeManualOptionImage(idx) {
    document.getElementById('opt_img_' + idx).value = '';
    document.getElementById('opt_img_preview_' + idx).src = '#';
    document.getElementById('opt_img_preview_box_' + idx).classList.add('hidden');
    document.getElementById('opt_img_btn_lbl_' + idx).textContent = '+ Imagem';
}
</script>

<?php include 'includes/footer.php'; ?>
