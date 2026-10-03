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
    
    if ($action === 'create') {
        require_once 'includes/mailer.php';
        $unit_id = !empty($_POST['unit_id']) ? $_POST['unit_id'] : null;
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'student';
        
        // Gerar senha temporária automática de 8 caracteres
        $temp_password = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#*'), 0, 8);
        
        if ($name && $email && in_array($role, ['admin', 'teacher', 'student'])) {
            try {
                $hash = password_hash($temp_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (unit_id, name, email, password, role, must_change_password) VALUES (?, ?, ?, ?, ?, 1)");
                $stmt->execute([$unit_id, $name, $email, $hash, $role]);
                
                // Montar link de login dinâmico
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                $host = $_SERVER['HTTP_HOST'];
                $uri = rtrim(dirname($_SERVER['REQUEST_URI']), '/\\');
                $loginLink = "$protocol://$host$uri/";
                
                // Enviar E-mail
                $emailBody = "Olá <strong>{$name}</strong>,<br><br>Sua conta na plataforma aiS acaba de ser criada!<br><br>Suas credenciais de acesso temporárias são:<br><strong>E-mail:</strong> {$email}<br><strong>Senha:</strong> {$temp_password}<br><br><a href='{$loginLink}' style='display:inline-block; padding:10px 20px; background-color:#4f46e5; color:white; text-decoration:none; border-radius:5px;'>Acessar o aiS</a><br><br><em>Aviso: Por questões de segurança, no seu primeiro login o sistema exigirá que você crie uma nova senha definitiva.</em>";
                
                send_system_email($email, "Bem-vindo ao aiS - Suas Credenciais", $emailBody);
                
                $msg = "Usuário '$name' cadastrado com sucesso! Um e-mail com a senha foi enviado para o usuário.";
                $msgType = 'success';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $msg = "O email '$email' já está cadastrado no sistema.";
                    $msgType = 'error';
                } else {
                    $msg = "Erro ao cadastrar usuário: " . $e->getMessage();
                    $msgType = 'error';
                }
            }
        } else {
            $msg = "Preencha todos os campos obrigatórios.";
            $msgType = 'error';
        }
    } elseif ($action === 'edit') {
        $id = $_POST['user_id'] ?? 0;
        $unit_id = !empty($_POST['unit_id']) ? $_POST['unit_id'] : null;
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'student';
        
        if ($id && $name && $email && in_array($role, ['admin', 'teacher', 'student'])) {
            try {
                if (!empty($password)) {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET unit_id = ?, name = ?, email = ?, password = ?, role = ? WHERE id = ?");
                    $stmt->execute([$unit_id, $name, $email, $hash, $role, $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET unit_id = ?, name = ?, email = ?, role = ? WHERE id = ?");
                    $stmt->execute([$unit_id, $name, $email, $role, $id]);
                }
                
                // Se o próprio admin mudar seus dados, atualiza a sessão
                if ($id == $_SESSION['user_id']) {
                    $_SESSION['user_name'] = $name;
                    $_SESSION['user_role'] = $role;
                    if ($role !== 'admin') {
                        header("Location: dashboard.php");
                        exit;
                    }
                }
                
                $msg = "Usuário '$name' atualizado com sucesso!";
                $msgType = 'success';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $msg = "O email '$email' já está sendo usado por outra conta.";
                    $msgType = 'error';
                } else {
                    $msg = "Erro ao atualizar usuário.";
                    $msgType = 'error';
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = $_POST['user_id'] ?? 0;
        if ($id && $id != $_SESSION['user_id']) {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $msg = "Usuário excluído permanentemente.";
            $msgType = 'success';
        } else {
            $msg = "Você não pode deletar a própria conta de administrador que está em uso.";
            $msgType = 'error';
        }
    }
}

// Filtros
$search = $_GET['search'] ?? '';
$roleFilter = $_GET['role'] ?? '';

$sql = "SELECT u.id, u.name, u.email, u.role, u.created_at, un.name as unit_name, r.name as regional_name 
        FROM users u 
        LEFT JOIN units un ON u.unit_id = un.id 
        LEFT JOIN regionals r ON un.regional_id = r.id 
        WHERE 1=1";
$params = [];

if ($search) {
    $sql .= " AND (u.name LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($roleFilter) {
    $sql .= " AND u.role = ?";
    $params[] = $roleFilter;
}
$sql .= " ORDER BY u.name ASC";

$stmtUsers = $pdo->prepare($sql);
$stmtUsers->execute($params);
$users = $stmtUsers->fetchAll();

$currentPage = 'admin_users';
include 'includes/header.php';
?>

<div id="app-screen" class="absolute inset-0 z-40 flex w-full h-full bg-[#F8FAFC]">
    <div class="fixed inset-0 w-full h-full overflow-hidden pointer-events-none z-0">
        <div class="absolute top-[-5%] right-[-5%] w-[500px] h-[500px] bg-senai-cyan/15 rounded-full mix-blend-multiply filter blur-[80px] opacity-70 animate-blob"></div>
        <div class="absolute bottom-[-5%] left-[-5%] w-[600px] h-[600px] bg-senai-orange/10 rounded-full mix-blend-multiply filter blur-[80px] opacity-50 animate-blob"></div>
    </div>

    <?php include 'includes/sidebar.php'; ?>

    <main class="flex-1 flex flex-col relative h-full w-full">
        <!-- Header Top -->
        <header class="h-14 flex items-center justify-between px-4 md:px-6 absolute top-0 w-full z-20 pointer-events-none">
            <button onclick="toggleSidebar()" class="pointer-events-auto w-10 h-10 flex items-center justify-center bg-white/50 hover:bg-white rounded-xl backdrop-blur-md shadow-sm border border-white/60 transition-all active:scale-95 text-senai-dark">
                <i id="sidebar-icon" class="ph-bold ph-list text-xl"></i>
            </button>
        </header>

        <div class="flex-1 overflow-y-auto w-full flex flex-col items-center pt-24 pb-20 relative z-10 px-4 md:px-8">
            <div class="w-full max-w-6xl animate-fade-in">
                
                <div class="flex flex-col md:flex-row justify-between items-start md:items-end mb-8 gap-4">
                    <div>
                        <div class="inline-flex items-center gap-2 px-3 py-1 bg-senai-orange/10 text-senai-orange rounded-full text-xs font-bold tracking-widest uppercase mb-3 border border-senai-orange/20">
                            <i class="ph-fill ph-shield-check"></i> Painel Administrativo
                        </div>
                        <h1 class="text-3xl font-bold tracking-tight mb-2 text-slate-800 flex items-center gap-3">
                            Gestão de Usuários
                            <button onclick="openHelpModal('Gestão de Usuários', 'Controle total sobre o acesso ao sistema.<br><br><b>Como utilizar:</b><br>- Adicione novos professores ou administradores.<br>- Atribua os usuários às suas respectivas Unidades SENAI.<br>- Resete senhas se necessário.<br>- Use a barra de pesquisa para encontrar alguém rapidamente.')" class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 hover:text-senai-blue hover:bg-blue-50 transition-colors shadow-inner" title="Como Usar">
                                <i class="ph-bold ph-question text-lg"></i>
                            </button>
                        </h1>
                        <p class="text-slate-500 font-medium">Controle total de professores, alunos e administradores do sistema.</p>
                    </div>
                    
                    <button onclick="openModal('createModal')" class="bg-senai-blue hover:bg-blue-800 text-white px-5 py-3 rounded-xl font-bold shadow-sm transition-all active:scale-95 text-sm flex items-center gap-2 hover:shadow-md">
                        <i class="ph-bold ph-user-plus text-lg"></i>
                        Cadastrar Usuário
                    </button>
                </div>

                <?php if($msg): ?>
                    <div class="mb-6 p-4 rounded-xl flex items-center gap-3 font-medium animate-slide-up <?= $msgType === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200' ?>">
                        <i class="<?= $msgType === 'success' ? 'ph-fill ph-check-circle' : 'ph-fill ph-warning-circle' ?> text-xl"></i>
                        <?= htmlspecialchars($msg) ?>
                    </div>
                <?php endif; ?>

                <div class="glass-panel p-6 mb-8 flex flex-col md:flex-row gap-4">
                    <form method="GET" class="flex-1 flex gap-2">
                        <div class="relative flex-1">
                            <i class="ph ph-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Buscar por nome ou email..." class="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-senai-blue focus:ring-1 focus:ring-senai-blue transition-all">
                        </div>
                        <select name="role" class="px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-senai-blue transition-all">
                            <option value="">Todos os Perfis</option>
                            <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Administradores</option>
                            <option value="teacher" <?= $roleFilter === 'teacher' ? 'selected' : '' ?>>Professores</option>
                            <option value="student" <?= $roleFilter === 'student' ? 'selected' : '' ?>>Alunos</option>
                        </select>
                        <button type="submit" class="px-5 py-2.5 bg-slate-800 text-white rounded-xl font-bold text-sm hover:bg-slate-700 transition-colors">
                            Filtrar
                        </button>
                        <?php if($search || $roleFilter): ?>
                            <a href="admin_users.php" class="px-4 py-2.5 bg-slate-100 text-slate-500 rounded-xl font-bold text-sm hover:bg-slate-200 transition-colors flex items-center">
                                Limpar
                            </a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="glass-panel overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="border-b border-slate-200 text-xs uppercase tracking-widest text-slate-400 bg-slate-50/50">
                                    <th class="p-4 font-semibold">Usuário</th>
                                    <th class="p-4 font-semibold">Email</th>
                                    <th class="p-4 font-semibold">Unidade Escolar</th>
                                    <th class="p-4 font-semibold">Perfil (Role)</th>
                                    <th class="p-4 font-semibold text-right">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="text-sm">
                                <?php if(empty($users)): ?>
                                <tr>
                                    <td colspan="4" class="p-8 text-center text-slate-400 font-medium">Nenhum usuário encontrado.</td>
                                </tr>
                                <?php endif; ?>
                                <?php foreach($users as $u): 
                                    $roleBadge = '';
                                    if ($u['role'] === 'admin') $roleBadge = '<span class="bg-senai-orange/10 text-senai-orange px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-wider flex items-center gap-1 w-max"><i class="ph-fill ph-shield-star"></i> Admin</span>';
                                    elseif ($u['role'] === 'teacher') $roleBadge = '<span class="bg-senai-blue/10 text-senai-blue px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-wider flex items-center gap-1 w-max"><i class="ph-fill ph-chalkboard-teacher"></i> Professor</span>';
                                    else $roleBadge = '<span class="bg-slate-100 text-slate-600 px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-wider flex items-center gap-1 w-max"><i class="ph-fill ph-student"></i> Aluno</span>';
                                ?>
                                <tr class="border-b border-slate-50 hover:bg-slate-50/50 transition-colors">
                                    <td class="p-4 font-bold text-slate-700 flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-full bg-slate-200 flex items-center justify-center text-slate-500 font-bold shadow-inner">
                                            <?= substr($u['name'], 0, 1) ?>
                                        </div>
                                        <?= htmlspecialchars($u['name']) ?>
                                    </td>
                                    <td class="p-4 text-slate-500 font-medium"><?= htmlspecialchars($u['email']) ?></td>
                                    <td class="p-4 text-slate-500 text-xs">
                                        <?php if ($u['unit_name']): ?>
                                            <div class="font-bold text-slate-700"><?= htmlspecialchars($u['unit_name']) ?></div>
                                            <div><?= htmlspecialchars($u['regional_name']) ?></div>
                                        <?php else: ?>
                                            <span class="opacity-50 italic">Sem vínculo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-4"><?= $roleBadge ?></td>
                                    <td class="p-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <button onclick='openEditModal(<?= json_encode($u) ?>)' class="w-8 h-8 rounded-lg flex items-center justify-center bg-indigo-50 text-indigo-600 hover:bg-indigo-100 transition-colors" title="Editar Usuário">
                                                <i class="ph-bold ph-pencil-simple"></i>
                                            </button>
                                            <?php if($u['id'] != $_SESSION['user_id']): ?>
                                                <form method="POST" class="inline" onsubmit="return confirm('Tem certeza que deseja excluir O USUÁRIO E TODOS OS SEUS DADOS (Provas, Respostas, etc)? Esta ação é IRREVERSÍVEL!')">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                    <button type="submit" class="w-8 h-8 rounded-lg flex items-center justify-center bg-red-50 text-red-500 hover:bg-red-100 transition-colors" title="Excluir Permanentemente">
                                                        <i class="ph-bold ph-trash"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <div class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-300" title="Você não pode se excluir">
                                                    <i class="ph-bold ph-lock"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Modal Criar -->
<div id="createModal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-xl w-full max-w-md overflow-hidden scale-95 opacity-0 transition-all duration-300" id="createModalContent">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
            <h3 class="font-bold text-lg text-slate-800 flex items-center gap-2"><i class="ph-bold ph-user-plus text-senai-blue"></i> Novo Usuário</h3>
            <button onclick="closeModal('createModal')" class="text-slate-400 hover:text-red-500 transition-colors"><i class="ph-bold ph-x text-xl"></i></button>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="create">
            
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Nome Completo</label>
                    <input type="text" name="name" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-senai-blue focus:ring-1 focus:ring-senai-blue transition-all" placeholder="Ex: João da Silva">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Unidade Escolar</label>
                    <select name="unit_id" class="search-select w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-senai-blue focus:ring-1 focus:ring-senai-blue transition-all">
                        <option value="">Sem Unidade (Global)</option>
                        <?php
                        $stmtUnits = $pdo->query("SELECT u.id, u.name as unit_name, r.name as regional_name FROM units u JOIN regionals r ON u.regional_id = r.id ORDER BY r.name ASC, u.name ASC");
                        $current_reg = '';
                        while ($unit = $stmtUnits->fetch()) {
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
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Email (Login)</label>
                    <input type="email" name="email" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-senai-blue focus:ring-1 focus:ring-senai-blue transition-all" placeholder="Ex: joao@senai.br">
                </div>
                <!-- A senha inicial é gerada automaticamente e enviada por e-mail -->
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Perfil de Acesso</label>
                    <div class="grid grid-cols-3 gap-2">
                        <label class="border border-slate-200 rounded-xl p-3 flex flex-col items-center gap-1 cursor-pointer hover:bg-slate-50 transition-colors has-[:checked]:border-senai-orange has-[:checked]:bg-orange-50/50 has-[:checked]:text-senai-orange">
                            <input type="radio" name="role" value="admin" class="sr-only">
                            <i class="ph-fill ph-shield-star text-xl"></i>
                            <span class="text-[10px] font-bold uppercase">Admin</span>
                        </label>
                        <label class="border border-slate-200 rounded-xl p-3 flex flex-col items-center gap-1 cursor-pointer hover:bg-slate-50 transition-colors has-[:checked]:border-senai-blue has-[:checked]:bg-blue-50/50 has-[:checked]:text-senai-blue">
                            <input type="radio" name="role" value="teacher" class="sr-only">
                            <i class="ph-fill ph-chalkboard-teacher text-xl"></i>
                            <span class="text-[10px] font-bold uppercase">Professor</span>
                        </label>
                        <label class="border border-slate-200 rounded-xl p-3 flex flex-col items-center gap-1 cursor-pointer hover:bg-slate-50 transition-colors has-[:checked]:border-slate-500 has-[:checked]:bg-slate-100 has-[:checked]:text-slate-700">
                            <input type="radio" name="role" value="student" checked class="sr-only">
                            <i class="ph-fill ph-student text-xl"></i>
                            <span class="text-[10px] font-bold uppercase">Aluno</span>
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="mt-8 flex justify-end gap-3">
                <button type="button" onclick="closeModal('createModal')" class="px-5 py-2.5 rounded-xl font-bold text-sm text-slate-500 hover:bg-slate-100 transition-colors">Cancelar</button>
                <button type="submit" class="px-5 py-2.5 rounded-xl font-bold text-sm bg-senai-blue text-white hover:bg-blue-800 transition-all shadow-sm hover:shadow-md">Salvar Usuário</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Editar -->
<div id="editModal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-xl w-full max-w-md overflow-hidden scale-95 opacity-0 transition-all duration-300" id="editModalContent">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
            <h3 class="font-bold text-lg text-slate-800 flex items-center gap-2"><i class="ph-bold ph-pencil-simple text-indigo-500"></i> Editar Usuário</h3>
            <button onclick="closeModal('editModal')" class="text-slate-400 hover:text-red-500 transition-colors"><i class="ph-bold ph-x text-xl"></i></button>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="user_id" id="edit_user_id">
            
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Nome Completo</label>
                    <input type="text" name="name" id="edit_name" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Unidade Escolar</label>
                    <select name="unit_id" id="edit_unit_id" class="search-select w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all">
                        <option value="">Sem Unidade (Global)</option>
                        <?php
                        $stmtUnits->execute(); // reseta o cursor
                        $current_reg = '';
                        while ($unit = $stmtUnits->fetch()) {
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
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Email (Login)</label>
                    <input type="email" name="email" id="edit_email" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Nova Senha <span class="text-[10px] text-slate-400 normal-case ml-1">(Deixe em branco para não alterar)</span></label>
                    <input type="password" name="password" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all" placeholder="••••••••">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Perfil de Acesso</label>
                    <div class="grid grid-cols-3 gap-2">
                        <label class="border border-slate-200 rounded-xl p-3 flex flex-col items-center gap-1 cursor-pointer hover:bg-slate-50 transition-colors has-[:checked]:border-senai-orange has-[:checked]:bg-orange-50/50 has-[:checked]:text-senai-orange">
                            <input type="radio" name="role" value="admin" id="edit_role_admin" class="sr-only">
                            <i class="ph-fill ph-shield-star text-xl"></i>
                            <span class="text-[10px] font-bold uppercase">Admin</span>
                        </label>
                        <label class="border border-slate-200 rounded-xl p-3 flex flex-col items-center gap-1 cursor-pointer hover:bg-slate-50 transition-colors has-[:checked]:border-senai-blue has-[:checked]:bg-blue-50/50 has-[:checked]:text-senai-blue">
                            <input type="radio" name="role" value="teacher" id="edit_role_teacher" class="sr-only">
                            <i class="ph-fill ph-chalkboard-teacher text-xl"></i>
                            <span class="text-[10px] font-bold uppercase">Professor</span>
                        </label>
                        <label class="border border-slate-200 rounded-xl p-3 flex flex-col items-center gap-1 cursor-pointer hover:bg-slate-50 transition-colors has-[:checked]:border-slate-500 has-[:checked]:bg-slate-100 has-[:checked]:text-slate-700">
                            <input type="radio" name="role" value="student" id="edit_role_student" class="sr-only">
                            <i class="ph-fill ph-student text-xl"></i>
                            <span class="text-[10px] font-bold uppercase">Aluno</span>
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="mt-8 flex justify-end gap-3">
                <button type="button" onclick="closeModal('editModal')" class="px-5 py-2.5 rounded-xl font-bold text-sm text-slate-500 hover:bg-slate-100 transition-colors">Cancelar</button>
                <button type="submit" class="px-5 py-2.5 rounded-xl font-bold text-sm bg-indigo-600 text-white hover:bg-indigo-700 transition-all shadow-sm hover:shadow-md">Salvar Alterações</button>
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
    
    function openEditModal(user) {
        document.getElementById('edit_user_id').value = user.id;
        document.getElementById('edit_name').value = user.name;
        document.getElementById('edit_email').value = user.email;
        if(user.unit_id) {
            document.getElementById('edit_unit_id').value = user.unit_id;
        } else {
            document.getElementById('edit_unit_id').value = '';
        }
        
        // Selecionar o role correto
        if(user.role === 'admin') document.getElementById('edit_role_admin').checked = true;
        else if(user.role === 'teacher') document.getElementById('edit_role_teacher').checked = true;
        else document.getElementById('edit_role_student').checked = true;
        
        openModal('editModal');
    }
</script>

<?php include 'includes/footer.php'; ?>
