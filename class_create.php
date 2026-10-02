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
    $name = $_POST['name'] ?? '';
    $course_id = $_POST['course_id'] ?? '';
    $year = $_POST['year'] ?? '';
    $semester = $_POST['semester'] ?? '';
    
    if ($name && $course_id && $year && $semester) {
        try {
            $unit_id = $_SESSION['unit_id'] ?? null;
            $stmt = $pdo->prepare("INSERT INTO classes (unit_id, course_id, name, year, semester) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$unit_id, $course_id, $name, $year, $semester]);
            $success = "Turma criada com sucesso!";
            header("refresh:2;url=classes.php"); // Redirect after 2s
        } catch (PDOException $e) {
            $error = "Erro ao criar turma: " . $e->getMessage();
        }
    } else {
        $error = "Preencha todos os campos.";
    }
}

// Buscar cursos para o select
$stmtCourses = $pdo->query("SELECT id, name FROM courses ORDER BY name");
$courses = $stmtCourses->fetchAll();

include 'includes/header.php';
?>

<div id="app-screen" class="absolute inset-0 z-40 flex w-full h-full">
    <!-- Fundo Vivo -->
    <div class="fixed inset-0 w-full h-full overflow-hidden pointer-events-none z-0 bg-[#F8FAFC]">
        <div id="blob1" class="absolute top-[-5%] right-[-5%] w-[500px] h-[500px] bg-senai-cyan/15 rounded-full mix-blend-multiply filter blur-[80px] opacity-70 animate-blob"></div>
        <div id="blob3" class="absolute top-[25%] left-[25%] w-[400px] h-[400px] bg-senai-blue/10 rounded-full mix-blend-multiply filter blur-[80px] opacity-50 animate-blob"></div>
    </div>

    <?php
    $currentPage = 'classes';
    include 'includes/sidebar.php'; 
    ?>

    <main id="main-content" class="flex-1 flex flex-col relative h-full w-full">
        <header class="h-14 flex items-center justify-between px-4 md:px-6 absolute top-0 w-full z-20 pointer-events-none">
            <button onclick="toggleSidebar()" class="pointer-events-auto w-10 h-10 flex items-center justify-center bg-white/50 hover:bg-white rounded-xl backdrop-blur-md shadow-sm border border-white/60 transition-all active:scale-95 group text-senai-dark hover:text-senai-cyan">
                <i id="sidebar-icon" class="ph-bold ph-list text-xl"></i>
            </button>
            <div class="flex-1"></div>
            <a href="classes.php" class="pointer-events-auto text-[11px] font-bold text-slate-600 hover:text-senai-blue flex items-center gap-1.5 bg-white/70 backdrop-blur-xl border border-white/80 shadow-sm px-4 py-2 rounded-full hover:shadow transition-all duration-300 active:scale-95">
                <i class="ph-fill ph-arrow-left text-slate-400"></i> Voltar às Turmas
            </a>
        </header>

        <div id="content-scroll-area" class="flex-1 overflow-y-auto no-scrollbar w-full flex flex-col items-center pt-24 pb-20 relative z-10 px-4 md:px-8">
            <div class="w-full max-w-2xl">
                <div class="mb-8 animate-slide-up">
                    <h1 class="text-3xl font-bold tracking-tight mb-2 text-slate-800">Nova Turma</h1>
                    <p class="text-slate-500 font-medium">Cadastre uma nova turma vinculada a um curso do sistema.</p>
                </div>

                <?php if ($error): ?>
                    <div class="bg-red-50 text-red-500 p-4 rounded-xl text-sm font-medium mb-6 animate-fade-in border border-red-100">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="bg-green-50 text-green-600 p-4 rounded-xl text-sm font-medium mb-6 animate-fade-in border border-green-100 flex items-center gap-2">
                        <i class="ph-fill ph-check-circle text-lg"></i> <?= htmlspecialchars($success) ?> Redirecionando...
                    </div>
                <?php else: ?>

                <form method="POST" action="class_create.php" class="bg-white/80 backdrop-blur-md border border-white/80 rounded-3xl p-8 shadow-sm animate-fade-in delay-200">
                    <div class="space-y-6">
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">Nome da Turma</label>
                            <input type="text" name="name" required placeholder="Ex: TII 2026 Manhã" class="w-full px-5 py-3.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-senai-blue/20 focus:border-senai-blue transition-all bg-white/50 text-slate-800 font-medium placeholder:text-slate-400">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">Curso Vinculado</label>
                            <select name="course_id" required class="search-select w-full px-5 py-3.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-senai-blue/20 focus:border-senai-blue transition-all bg-white/50 text-slate-800 font-medium">
                                <option value="" disabled selected>Selecione o curso...</option>
                                <?php foreach ($courses as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-[11px] text-slate-400 font-semibold mt-2 ml-1">
                                Importante: A turma só poderá ver e responder questões que pertencem aos módulos deste curso.
                            </p>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4 mt-6">
                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-2">Ano</label>
                                <input type="number" name="year" required value="<?= date('Y') ?>" min="2020" max="2100" class="w-full px-5 py-3.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-senai-blue/20 focus:border-senai-blue transition-all bg-white/50 text-slate-800 font-medium">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-2">Semestre</label>
                                <select name="semester" required class="w-full px-5 py-3.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-senai-blue/20 focus:border-senai-blue transition-all bg-white/50 text-slate-800 font-medium">
                                    <option value="1">1º Semestre</option>
                                    <option value="2">2º Semestre</option>
                                </select>
                            </div>
                        </div>

                        <!-- Aviso Transparente da Unidade (Lotagem) -->
                        <?php
                            $teacher_unit_id = $_SESSION['unit_id'] ?? null;
                            $unit_name = 'Unidade Global (Administração)';
                            if ($teacher_unit_id) {
                                $stmtUnit = $pdo->prepare("SELECT u.name as unit_name, r.name as regional_name FROM units u JOIN regionals r ON u.regional_id = r.id WHERE u.id = ?");
                                $stmtUnit->execute([$teacher_unit_id]);
                                $uData = $stmtUnit->fetch();
                                if ($uData) {
                                    $unit_name = $uData['unit_name'] . ' (' . $uData['regional_name'] . ')';
                                }
                            }
                        ?>
                        <div class="mt-8 p-5 bg-indigo-50 border border-indigo-100 rounded-2xl flex gap-4 items-center">
                            <div class="w-12 h-12 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-500 shrink-0">
                                <i class="ph-fill ph-map-pin text-2xl"></i>
                            </div>
                            <div>
                                <h4 class="text-xs font-bold text-indigo-800 uppercase tracking-wider mb-1">Local de Vinculação</h4>
                                <p class="text-sm text-indigo-600/80 font-medium leading-relaxed">
                                    Esta turma será alocada automaticamente em: <br>
                                    <strong class="text-indigo-900"><?= htmlspecialchars($unit_name) ?></strong>
                                </p>
                            </div>
                        </div>

                        <div class="pt-8 flex justify-end">
                            <button type="submit" class="bg-senai-blue text-white px-8 py-3.5 rounded-xl font-bold shadow-[0_4px_15px_-3px_rgba(26,66,138,0.4)] hover:shadow-[0_8px_20px_-3px_rgba(26,66,138,0.5)] hover:-translate-y-0.5 transition-all w-full md:w-auto flex items-center gap-2 group">
                                Criar Turma <i class="ph-bold ph-check group-hover:scale-110 transition-transform"></i>
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
