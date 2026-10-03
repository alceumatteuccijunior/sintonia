<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

require_once 'config.php';
$pdo = getPDOConnection(true);

$msg = '';
$msgType = '';

// Processamento de Formulários (CRUD)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // ==================== REGIONiaS ====================
    if ($action === 'create_regional') {
        $name = trim($_POST['name'] ?? '');
        if ($name) {
            try {
                $stmt = $pdo->prepare("INSERT INTO regionals (name) VALUES (?)");
                $stmt->execute([$name]);
                $msg = "Regional '$name' cadastrada com sucesso!";
                $msgType = 'success';
            } catch (PDOException $e) {
                $msg = "Erro ao cadastrar: Regional já existe.";
                $msgType = 'error';
            }
        }
    } elseif ($action === 'edit_regional') {
        $id = $_POST['id'] ?? 0;
        $name = trim($_POST['name'] ?? '');
        if ($id && $name) {
            try {
                $stmt = $pdo->prepare("UPDATE regionals SET name = ? WHERE id = ?");
                $stmt->execute([$name, $id]);
                $msg = "Regional atualizada com sucesso!";
                $msgType = 'success';
            } catch (PDOException $e) {
                $msg = "Erro ao atualizar Regional.";
                $msgType = 'error';
            }
        }
    } elseif ($action === 'delete_regional') {
        $id = $_POST['id'] ?? 0;
        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM regionals WHERE id = ?");
            $stmt->execute([$id]);
            $msg = "Regional (e suas unidades) excluída com sucesso!";
            $msgType = 'success';
        }
    }
    // ==================== UNIDADES ====================
    elseif ($action === 'create_unit') {
        $regional_id = $_POST['regional_id'] ?? 0;
        $name = trim($_POST['name'] ?? '');
        if ($regional_id && $name) {
            try {
                $stmt = $pdo->prepare("INSERT INTO units (regional_id, name) VALUES (?, ?)");
                $stmt->execute([$regional_id, $name]);
                $msg = "Unidade '$name' cadastrada com sucesso!";
                $msgType = 'success';
            } catch (PDOException $e) {
                $msg = "Erro ao cadastrar: Unidade já existe nesta Regional.";
                $msgType = 'error';
            }
        }
    } elseif ($action === 'edit_unit') {
        $id = $_POST['id'] ?? 0;
        $regional_id = $_POST['regional_id'] ?? 0;
        $name = trim($_POST['name'] ?? '');
        if ($id && $regional_id && $name) {
            try {
                $stmt = $pdo->prepare("UPDATE units SET regional_id = ?, name = ? WHERE id = ?");
                $stmt->execute([$regional_id, $name, $id]);
                $msg = "Unidade atualizada com sucesso!";
                $msgType = 'success';
            } catch (PDOException $e) {
                $msg = "Erro ao atualizar Unidade.";
                $msgType = 'error';
            }
        }
    } elseif ($action === 'delete_unit') {
        $id = $_POST['id'] ?? 0;
        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM units WHERE id = ?");
            $stmt->execute([$id]);
            $msg = "Unidade excluída com sucesso!";
            $msgType = 'success';
        }
    }
}

// Buscar todas as regioniaS e suas unidades
$stmtReg = $pdo->query("SELECT * FROM regionals ORDER BY name ASC");
$regionals = $stmtReg->fetchAll();

$stmtUnits = $pdo->query("
    SELECT u.*, r.name as regional_name,
    (SELECT count(*) FROM users WHERE unit_id = u.id) as total_users,
    (SELECT count(*) FROM classes WHERE unit_id = u.id) as total_classes
    FROM units u
    JOIN regionals r ON u.regional_id = r.id
    ORDER BY r.name ASC, u.name ASC
");
$units = $stmtUnits->fetchAll();

$currentPage = 'admin_units';
include 'includes/header.php';
?>

<div id="app-screen" class="absolute inset-0 z-40 flex w-full h-full bg-[#F8FAFC]">
    
    <div class="fixed inset-0 w-full h-full overflow-hidden pointer-events-none z-0">
        <div class="absolute top-[-5%] right-[-5%] w-[500px] h-[500px] bg-senai-cyan/15 rounded-full mix-blend-multiply filter blur-[80px] opacity-70 animate-blob"></div>
        <div class="absolute bottom-[-5%] left-[-5%] w-[600px] h-[600px] bg-senai-orange/10 rounded-full mix-blend-multiply filter blur-[90px] opacity-60 animate-blob" style="animation-delay: -5s;"></div>
    </div>

    <?php include 'includes/sidebar.php'; ?>

    <main id="main-content" class="flex-1 flex flex-col relative h-full w-full">
        
        <header class="h-14 flex items-center justify-between px-4 md:px-6 absolute top-0 w-full z-20 pointer-events-none">
            <button onclick="toggleSidebar()" class="pointer-events-auto w-10 h-10 flex items-center justify-center bg-white/50 hover:bg-white rounded-xl backdrop-blur-md shadow-sm border border-white/60 transition-all active:scale-95 text-senai-dark">
                <i id="sidebar-icon" class="ph-bold ph-list text-xl"></i>
            </button>
        </header>

        <div id="content-scroll-area" class="flex-1 overflow-y-auto no-scrollbar w-full flex flex-col items-center pt-24 pb-20 relative z-10 px-4 md:px-8">
            
            <div class="w-full max-w-6xl">
                <div class="flex flex-col md:flex-row md:items-center justify-between mb-8 animate-slide-up">
                    <div>
                        <h1 class="text-3xl md:text-4xl font-bold tracking-tight mb-2 drop-shadow-sm text-slate-800">
                            RegioniaS e Unidades
                        </h1>
                        <p class="text-base text-slate-500 font-medium opacity-0 animate-fade-in delay-200">
                            Configure o catálogo de escolas do SENAI.
                        </p>
                    </div>
                </div>

                <?php if ($msg): ?>
                    <div class="<?= $msgType === 'success' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200' ?> border p-4 rounded-2xl font-medium mb-6 animate-fade-in flex items-center gap-2">
                        <i class="ph-fill <?= $msgType === 'success' ? 'ph-check-circle' : 'ph-warning-circle' ?> text-xl"></i>
                        <?= htmlspecialchars($msg) ?>
                    </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Coluna RegioniaS -->
                    <div class="lg:col-span-1">
                        <div class="bg-white/70 backdrop-blur-md border border-white/80 rounded-3xl p-6 shadow-sm overflow-hidden animate-slide-up delay-200">
                            <div class="flex justify-between items-center mb-6">
                                <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                                    <i class="ph-fill ph-map-pin text-senai-orange"></i> RegioniaS (DR)
                                </h2>
                                <button onclick="openModal('createRegionalModal')" class="w-8 h-8 rounded-full bg-senai-orange/10 text-senai-orange hover:bg-senai-orange hover:text-white flex items-center justify-center transition-colors shadow-sm" title="Nova Regional">
                                    <i class="ph-bold ph-plus"></i>
                                </button>
                            </div>
                            
                            <div class="space-y-3">
                                <?php foreach($regionals as $r): ?>
                                    <div class="flex justify-between items-center p-3 rounded-xl hover:bg-slate-50 border border-transparent hover:border-slate-100 transition-colors group">
                                        <span class="font-bold text-slate-700 text-sm"><?= htmlspecialchars($r['name']) ?></span>
                                        <div class="flex gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                            <button onclick='openEditRegional(<?= json_encode($r) ?>)' class="text-slate-400 hover:text-indigo-500"><i class="ph-bold ph-pencil-simple"></i></button>
                                            <form method="POST" class="inline" onsubmit="return confirm('Excluir esta regional também excluirá todas as unidades atreladas a ela. Tem certeza?')">
                                                <input type="hidden" name="action" value="delete_regional">
                                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                                <button type="submit" class="text-slate-400 hover:text-red-500"><i class="ph-bold ph-trash"></i></button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Coluna Unidades -->
                    <div class="lg:col-span-2">
                        <div class="bg-white/70 backdrop-blur-md border border-white/80 rounded-3xl p-6 shadow-sm overflow-hidden animate-slide-up delay-300">
                            <div class="flex justify-between items-center mb-6">
                                <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                                    <i class="ph-fill ph-buildings text-senai-blue"></i> Unidades Escolares
                                </h2>
                                <button onclick="openModal('createUnitModal')" class="bg-senai-blue text-white px-4 py-2 rounded-xl text-sm font-bold shadow-sm hover:bg-blue-800 transition-colors flex items-center gap-2">
                                    <i class="ph-bold ph-plus"></i> Nova Unidade
                                </button>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full text-left border-collapse">
                                    <thead>
                                        <tr class="border-b border-slate-200 text-[10px] uppercase tracking-widest text-slate-400 bg-slate-50/50">
                                            <th class="p-3 font-semibold">Unidade</th>
                                            <th class="p-3 font-semibold">Regional</th>
                                            <th class="p-3 font-semibold text-center">Usuários</th>
                                            <th class="p-3 font-semibold text-center">Turmas</th>
                                            <th class="p-3 font-semibold text-right">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100/50">
                                        <?php foreach($units as $u): ?>
                                            <tr class="hover:bg-slate-50/50 transition-colors group text-sm">
                                                <td class="p-3 font-bold text-slate-700"><?= htmlspecialchars($u['name']) ?></td>
                                                <td class="p-3 text-slate-500"><?= htmlspecialchars($u['regional_name']) ?></td>
                                                <td class="p-3 text-center text-slate-500 font-medium"><?= $u['total_users'] ?></td>
                                                <td class="p-3 text-center text-slate-500 font-medium"><?= $u['total_classes'] ?></td>
                                                <td class="p-3 text-right">
                                                    <div class="flex items-center justify-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                                        <button onclick='openEditUnit(<?= json_encode($u) ?>)' class="w-8 h-8 rounded-lg bg-slate-100 text-slate-500 flex items-center justify-center hover:bg-indigo-500 hover:text-white transition-colors">
                                                            <i class="ph-bold ph-pencil-simple"></i>
                                                        </button>
                                                        <form method="POST" class="inline" onsubmit="return confirm('Ao excluir esta unidade, todos os alunos, turmas e professores ficarão órfãos. Tem certeza?')">
                                                            <input type="hidden" name="action" value="delete_unit">
                                                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                                            <button type="submit" class="w-8 h-8 rounded-lg bg-slate-100 text-slate-500 flex items-center justify-center hover:bg-red-500 hover:text-white transition-colors">
                                                                <i class="ph-bold ph-trash"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if(empty($units)): ?>
                                            <tr><td colspan="5" class="p-6 text-center text-slate-400">Nenhuma unidade cadastrada.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>
</div>

<!-- ==================== MODiaS REGIONiaS ==================== -->
<div id="createRegionalModal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-xl w-full max-w-sm overflow-hidden scale-95 opacity-0 transition-all duration-300" id="createRegionalModalContent">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
            <h3 class="font-bold text-lg text-slate-800 flex items-center gap-2"><i class="ph-bold ph-map-pin text-senai-orange"></i> Nova Regional</h3>
            <button onclick="closeModal('createRegionalModal')" class="text-slate-400 hover:text-red-500 transition-colors"><i class="ph-bold ph-x text-xl"></i></button>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="create_regional">
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Nome da Regional</label>
                <input type="text" name="name" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-senai-orange transition-all" placeholder="Ex: SC">
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="closeModal('createRegionalModal')" class="px-4 py-2 rounded-xl font-bold text-sm text-slate-500 hover:bg-slate-100 transition-colors">Cancelar</button>
                <button type="submit" class="px-4 py-2 rounded-xl font-bold text-sm bg-senai-orange text-white hover:bg-orange-600 transition-all shadow-sm">Salvar</button>
            </div>
        </form>
    </div>
</div>

<div id="editRegionalModal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-xl w-full max-w-sm overflow-hidden scale-95 opacity-0 transition-all duration-300" id="editRegionalModalContent">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
            <h3 class="font-bold text-lg text-slate-800 flex items-center gap-2"><i class="ph-bold ph-pencil-simple text-indigo-500"></i> Editar Regional</h3>
            <button onclick="closeModal('editRegionalModal')" class="text-slate-400 hover:text-red-500 transition-colors"><i class="ph-bold ph-x text-xl"></i></button>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="edit_regional">
            <input type="hidden" name="id" id="edit_reg_id">
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Nome da Regional</label>
                <input type="text" name="name" id="edit_reg_name" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-indigo-500 transition-all">
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="closeModal('editRegionalModal')" class="px-4 py-2 rounded-xl font-bold text-sm text-slate-500 hover:bg-slate-100 transition-colors">Cancelar</button>
                <button type="submit" class="px-4 py-2 rounded-xl font-bold text-sm bg-indigo-600 text-white hover:bg-indigo-700 transition-all shadow-sm">Atualizar</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== MODiaS UNIDADES ==================== -->
<div id="createUnitModal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-xl w-full max-w-md overflow-hidden scale-95 opacity-0 transition-all duration-300" id="createUnitModalContent">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
            <h3 class="font-bold text-lg text-slate-800 flex items-center gap-2"><i class="ph-bold ph-buildings text-senai-blue"></i> Nova Unidade Escolar</h3>
            <button onclick="closeModal('createUnitModal')" class="text-slate-400 hover:text-red-500 transition-colors"><i class="ph-bold ph-x text-xl"></i></button>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="create_unit">
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Regional Pertencente</label>
                    <select name="regional_id" required class="search-select w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-senai-blue transition-all">
                        <option value="">Selecione a Regional...</option>
                        <?php foreach($regionals as $r): ?>
                            <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Nome da Unidade</label>
                    <input type="text" name="name" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-senai-blue transition-all" placeholder="Ex: SENAI Luzerna">
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="closeModal('createUnitModal')" class="px-4 py-2 rounded-xl font-bold text-sm text-slate-500 hover:bg-slate-100 transition-colors">Cancelar</button>
                <button type="submit" class="px-4 py-2 rounded-xl font-bold text-sm bg-senai-blue text-white hover:bg-blue-800 transition-all shadow-sm">Cadastrar Unidade</button>
            </div>
        </form>
    </div>
</div>

<div id="editUnitModal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-xl w-full max-w-md overflow-hidden scale-95 opacity-0 transition-all duration-300" id="editUnitModalContent">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
            <h3 class="font-bold text-lg text-slate-800 flex items-center gap-2"><i class="ph-bold ph-pencil-simple text-indigo-500"></i> Editar Unidade Escolar</h3>
            <button onclick="closeModal('editUnitModal')" class="text-slate-400 hover:text-red-500 transition-colors"><i class="ph-bold ph-x text-xl"></i></button>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="edit_unit">
            <input type="hidden" name="id" id="edit_unit_id">
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Regional Pertencente</label>
                    <select name="regional_id" id="edit_unit_regional" required class="search-select w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-indigo-500 transition-all">
                        <?php foreach($regionals as $r): ?>
                            <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Nome da Unidade</label>
                    <input type="text" name="name" id="edit_unit_name" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-indigo-500 transition-all">
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="closeModal('editUnitModal')" class="px-4 py-2 rounded-xl font-bold text-sm text-slate-500 hover:bg-slate-100 transition-colors">Cancelar</button>
                <button type="submit" class="px-4 py-2 rounded-xl font-bold text-sm bg-indigo-600 text-white hover:bg-indigo-700 transition-all shadow-sm">Salvar Alterações</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal(id) {
        const modal = document.getElementById(id);
        const content = document.getElementById(id + 'Content');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
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
    
    function openEditRegional(reg) {
        document.getElementById('edit_reg_id').value = reg.id;
        document.getElementById('edit_reg_name').value = reg.name;
        openModal('editRegionalModal');
    }

    function openEditUnit(unit) {
        document.getElementById('edit_unit_id').value = unit.id;
        document.getElementById('edit_unit_regional').value = unit.regional_id;
        document.getElementById('edit_unit_name').value = unit.name;
        openModal('editUnitModal');
    }
</script>

<?php include 'includes/footer.php'; ?>
