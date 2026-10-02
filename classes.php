<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
require_once 'config.php';
$pdo = getPDOConnection(true);

// Excluir Turma
if (isset($_GET['delete_id'])) {
    $del_id = $_GET['delete_id'];
    $stmtDel = $pdo->prepare("DELETE FROM classes WHERE id = ?");
    $stmtDel->execute([$del_id]);
    header("Location: classes.php");
    exit;
}

// Editar Turma
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_class') {
    $class_id = $_POST['class_id'];
    $name = $_POST['name'];
    $course_id = $_POST['course_id'];
    $year = $_POST['year'];
    $semester = $_POST['semester'];
    $new_unit_id = !empty($_POST['unit_id']) ? $_POST['unit_id'] : null;

    if ($_SESSION['user_role'] === 'admin' && $new_unit_id) {
        // Atualiza a turma para a nova unidade
        $stmt = $pdo->prepare("UPDATE classes SET name = ?, course_id = ?, year = ?, semester = ?, unit_id = ? WHERE id = ?");
        $stmt->execute([$name, $course_id, $year, $semester, $new_unit_id, $class_id]);

        // Migra todos os ALUNOS dessa turma para a nova unidade também!
        $stmtUpdateStudents = $pdo->prepare("
            UPDATE users 
            SET unit_id = ? 
            WHERE role = 'student' AND id IN (SELECT student_id FROM class_students WHERE class_id = ?)
        ");
        $stmtUpdateStudents->execute([$new_unit_id, $class_id]);
    } else {
        // Professor editando (não pode mudar de unidade)
        $stmt = $pdo->prepare("UPDATE classes SET name = ?, course_id = ?, year = ?, semester = ? WHERE id = ?");
        $stmt->execute([$name, $course_id, $year, $semester, $class_id]);
    }
    header("Location: classes.php");
    exit;
}

// Buscar cursos e unidades para o Modal
$courses = $pdo->query("SELECT * FROM courses ORDER BY name ASC")->fetchAll();
$units = [];
if ($_SESSION['user_role'] === 'admin') {
    $units = $pdo->query("SELECT u.id, u.name as unit_name, r.name as regional_name FROM units u JOIN regionals r ON u.regional_id = r.id ORDER BY r.name ASC, u.name ASC")->fetchAll();
}

// Buscar Turmas
$unit_id = $_SESSION['unit_id'] ?? null;
$whereClause = $unit_id ? "WHERE c.unit_id = ?" : "WHERE 1=1";
$params = $unit_id ? [$unit_id] : [];

$search = trim($_GET['search'] ?? '');
if ($search) {
    $whereClause .= " AND (c.name LIKE ? OR co.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$stmt = $pdo->prepare("
    SELECT c.*, co.name as course_name,
           (SELECT COUNT(*) FROM class_students cs WHERE cs.class_id = c.id) as total_students
    FROM classes c
    JOIN courses co ON c.course_id = co.id
    $whereClause
    ORDER BY c.year DESC, c.semester DESC, c.name ASC
");
$stmt->execute($params);
$classes = $stmt->fetchAll();

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
    $currentPage = 'classes';
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
                <div class="flex flex-col md:flex-row md:items-center justify-between mb-8 animate-slide-up gap-4">
                    <div>
                        <h1 class="text-3xl font-bold tracking-tight mb-2 text-slate-800">
                            Turmas & Alunos
                        </h1>
                        <p class="text-slate-500 font-medium">
                            Gerencie as turmas, matricule alunos e acompanhe o engajamento.
                        </p>
                    </div>
                    <div class="flex items-center gap-3 w-full md:w-auto">
                        <form method="GET" action="classes.php" class="relative flex-1 md:w-72">
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Buscar turma ou curso..." class="w-full pl-10 pr-4 py-2.5 bg-white/70 backdrop-blur-md border border-white/80 rounded-xl text-sm focus:outline-none focus:border-senai-blue focus:ring-1 focus:ring-senai-blue transition-all shadow-sm">
                            <i class="ph-bold ph-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"></i>
                            <?php if ($search): ?>
                                <a href="classes.php" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-red-500"><i class="ph-bold ph-x"></i></a>
                            <?php endif; ?>
                        </form>
                        <?php if ($_SESSION['user_role'] === 'teacher' || $_SESSION['user_role'] === 'admin'): ?>
                        <a href="class_create.php" class="bg-senai-blue text-white px-5 py-2.5 rounded-xl font-semibold shadow-[0_4px_15px_-3px_rgba(26,66,138,0.4)] hover:shadow-[0_8px_20px_-3px_rgba(26,66,138,0.5)] hover:-translate-y-0.5 transition-all flex items-center gap-2 whitespace-nowrap">
                            <i class="ph-bold ph-plus"></i> Nova Turma
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bg-white/70 backdrop-blur-md border border-white/80 rounded-2xl p-6 shadow-sm mb-10 animate-fade-in delay-200">
                    
                    <?php
                    if (empty($classes)):
                    ?>
                        <div class="text-center text-slate-500 py-10">
                            <i class="ph-thin ph-users-three text-4xl mb-3 opacity-50"></i>
                            <p class="font-medium">Nenhuma turma cadastrada no momento.</p>
                            <p class="text-sm mt-1">Clique em "Nova Turma" para começar.</p>
                        </div>
                    <?php else: ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <?php foreach ($classes as $classe): 
                                $stmtCount = $pdo->prepare("SELECT count(*) FROM class_students WHERE class_id = ?");
                                $stmtCount->execute([$classe['id']]);
                                $alunos = $stmtCount->fetchColumn();
                            ?>
                                <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm hover:shadow-md transition-shadow group relative">
                                    <div class="flex justify-between items-start mb-3">
                                        <div class="w-10 h-10 rounded-xl bg-senai-orange/10 flex items-center justify-center text-senai-orange">
                                            <i class="ph-fill ph-users-three text-xl"></i>
                                        </div>
                                        <span class="text-[10px] font-bold px-2 py-1 rounded-md bg-slate-100 text-slate-600 uppercase tracking-wider">
                                            <?= htmlspecialchars($classe['year']) ?>/<?= htmlspecialchars($classe['semester']) ?>
                                        </span>
                                    </div>
                                    <h3 class="text-lg font-bold text-slate-800 mb-1">
                                        <?= htmlspecialchars($classe['name']) ?>
                                    </h3>
                                    <p class="text-xs text-senai-blue font-bold uppercase tracking-wider mb-4 flex items-center gap-2">
                                        <?= htmlspecialchars($classe['course_name']) ?>
                                        <?php if ($_SESSION['user_role'] === 'admin'): ?>
                                            <?php
                                            $stmtU = $pdo->prepare("SELECT name FROM units WHERE id = ?");
                                            $stmtU->execute([$classe['unit_id']]);
                                            $unitN = $stmtU->fetchColumn();
                                            ?>
                                            <span class="bg-slate-100 text-slate-500 text-[9px] px-2 py-0.5 rounded-full" title="Unidade"><?= htmlspecialchars($unitN ?? 'Global') ?></span>
                                        <?php endif; ?>
                                    </p>
                                    
                                    <div class="pt-4 border-t border-slate-100 flex justify-between items-center mt-auto">
                                        <span class="text-xs font-semibold text-slate-500 flex items-center gap-1">
                                            <i class="ph-fill ph-student text-slate-400"></i> <?= $alunos ?> alunos
                                        </span>
                                        <div class="flex items-center gap-3">
                                            <a href="class_manage.php?id=<?= $classe['id'] ?>" class="text-senai-blue hover:text-senai-orange text-sm font-bold flex items-center gap-1 transition-colors">
                                                Gerenciar <i class="ph-bold ph-arrow-right"></i>
                                            </a>
                                            <?php if ($_SESSION['user_role'] === 'admin'): ?>
                                                <button onclick='openEditClassModal(<?= json_encode($classe) ?>)' class="text-slate-300 hover:text-indigo-500 transition-colors p-1" title="Editar Turma">
                                                    <i class="ph-bold ph-pencil-simple"></i>
                                                </button>
                                            <?php endif; ?>
                                            <a href="?delete_id=<?= $classe['id'] ?>" onclick="return confirm('ATENÇÃO: Excluir esta turma apagará TODAS as provas, alunos e respostas vinculadas a ela. Continuar?')" class="text-slate-300 hover:text-red-500 transition-colors p-1" title="Excluir Turma">
                                                <i class="ph-bold ph-trash"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                </div>

            </div>
        </div>
    </main>
</div>

<!-- Modal Editar Turma -->
<div id="editClassModal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-xl w-full max-w-lg overflow-hidden scale-95 opacity-0 transition-all duration-300" id="editClassModalContent">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
            <h3 class="font-bold text-lg text-slate-800 flex items-center gap-2"><i class="ph-bold ph-pencil-simple text-indigo-500"></i> Editar Turma</h3>
            <button onclick="closeEditModal()" class="text-slate-400 hover:text-red-500 transition-colors"><i class="ph-bold ph-x text-xl"></i></button>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="edit_class">
            <input type="hidden" name="class_id" id="edit_class_id">
            
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Nome da Turma</label>
                    <input type="text" name="name" id="edit_name" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-indigo-500 transition-all">
                </div>
                
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Curso</label>
                    <select name="course_id" id="edit_course_id" required class="search-select w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-indigo-500 transition-all">
                        <?php foreach($courses as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Ano</label>
                        <input type="number" name="year" id="edit_year" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-indigo-500 transition-all">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Semestre</label>
                        <select name="semester" id="edit_semester" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-indigo-500 transition-all">
                            <option value="1">1º Semestre</option>
                            <option value="2">2º Semestre</option>
                        </select>
                    </div>
                </div>

                <?php if ($_SESSION['user_role'] === 'admin'): ?>
                <div class="p-4 bg-indigo-50 border border-indigo-100 rounded-xl mt-4">
                    <label class="block text-xs font-bold text-indigo-700 uppercase tracking-wider mb-1.5"><i class="ph-bold ph-swap"></i> Transferência de Unidade</label>
                    <p class="text-[10px] text-indigo-600/70 mb-3 font-medium">Ao alterar a unidade, todos os alunos matriculados nesta turma também serão migrados para a nova unidade.</p>
                    <select name="unit_id" id="edit_unit_id" class="search-select w-full px-4 py-2.5 bg-white border border-indigo-200 rounded-xl text-sm focus:outline-none focus:border-indigo-500 transition-all text-indigo-900 font-medium">
                        <option value="">Sem Unidade (Global)</option>
                        <?php
                        $current_reg = '';
                        foreach ($units as $unit) {
                            if ($current_reg !== $unit['regional_name']) {
                                if ($current_reg !== '') echo "</optgroup>";
                                $current_reg = $unit['regional_name'];
                                echo "<optgroup label='" . htmlspecialchars($current_reg) . "'>";
                            }
                            echo "<option value='{$unit['id']}'>" . htmlspecialchars($unit['unit_name']) . "</option>";
                        }
                        if ($current_reg !== '') echo "</optgroup>";
                        ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="mt-8 flex justify-end gap-3">
                <button type="button" onclick="closeEditModal()" class="px-5 py-2.5 rounded-xl font-bold text-sm text-slate-500 hover:bg-slate-100 transition-colors">Cancelar</button>
                <button type="submit" class="px-5 py-2.5 rounded-xl font-bold text-sm bg-indigo-600 text-white hover:bg-indigo-700 transition-all shadow-sm">Salvar Alterações</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openEditClassModal(classe) {
        document.getElementById('edit_class_id').value = classe.id;
        document.getElementById('edit_name').value = classe.name;
        document.getElementById('edit_course_id').value = classe.course_id;
        document.getElementById('edit_year').value = classe.year;
        document.getElementById('edit_semester').value = classe.semester;
        
        const unitSelect = document.getElementById('edit_unit_id');
        if (unitSelect) {
            unitSelect.value = classe.unit_id ? classe.unit_id : '';
        }

        const modal = document.getElementById('editClassModal');
        const content = document.getElementById('editClassModalContent');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setTimeout(() => {
            content.classList.remove('scale-95', 'opacity-0');
        }, 10);
    }

    function closeEditModal() {
        const modal = document.getElementById('editClassModal');
        const content = document.getElementById('editClassModalContent');
        content.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }, 300);
    }
</script>

<?php include 'includes/footer.php'; ?>
