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

/**
 * Padroniza nomes de usuários com a primeira letra de cada palavra em maiúsculo e o restante em minúsculo
 * Ex: "JOÃO DA SILVA" ou "joão da silva" -> "João Da Silva"
 */
function standardizeUserName(string $rawName): string {
    $clean = preg_replace('/\s+/u', ' ', trim($rawName));
    if ($clean === '') {
        return '';
    }
    return mb_convert_case($clean, MB_CASE_TITLE, 'UTF-8');
}

// Padroniza automaticamente nomes já existentes no banco que estejam fora do padrão (ex: CAIXA ALTA ou minúsculo)
try {
    $stmtCheckNames = $pdo->query("SELECT id, name FROM users");
    $stmtFixName = null;
    while ($uRow = $stmtCheckNames->fetch()) {
        $origName = (string)($uRow['name'] ?? '');
        $stdName  = standardizeUserName($origName);
        if ($stdName !== '' && $stdName !== $origName) {
            if ($stmtFixName === null) {
                $stmtFixName = $pdo->prepare("UPDATE users SET name = ? WHERE id = ?");
            }
            $stmtFixName->execute([$stdName, $uRow['id']]);
            if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$uRow['id']) {
                $_SESSION['user_name'] = $stdName;
            }
        }
    }
} catch (Exception $e) {}

// Buscar todas as turmas cadastradas para vínculos e importação (incluindo course_id e regional_id para cascata de filtros)
$stmtAllClasses = $pdo->query("
    SELECT c.id, c.name, c.year, c.semester, c.unit_id, c.course_id,
           un.regional_id, co.name as course_name 
    FROM classes c 
    LEFT JOIN courses co ON c.course_id = co.id 
    LEFT JOIN units un ON c.unit_id = un.id
    ORDER BY c.year DESC, c.semester DESC, c.name ASC
");
$allClasses = $stmtAllClasses->fetchAll();

$classLookupById = [];
$classLookupByName = [];
$classLookupByNameNormalized = [];
foreach ($allClasses as $c) {
    $classLookupById[(int)$c['id']] = $c;
    $classLookupByName[trim($c['name'])] = (int)$c['id'];
    $normName = mb_strtolower(trim($c['name']), 'UTF-8');
    $classLookupByNameNormalized[$normName] = (int)$c['id'];
}

// Processamento de Formulários (CRUD)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        require_once 'includes/mailer.php';
        $unit_id = !empty($_POST['unit_id']) ? $_POST['unit_id'] : null;
        $name = standardizeUserName($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'student';
        $class_id = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null;
        
        // Se for aluno e turma selecionada e sem unidade, herda unidade da turma se houver
        if ($role === 'student' && $class_id && empty($unit_id) && !empty($classLookupById[$class_id]['unit_id'])) {
            $unit_id = $classLookupById[$class_id]['unit_id'];
        }
        
        // Gerar senha temporária automática de 8 caracteres
        $temp_password = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#*'), 0, 8);
        
        if ($name && $email && in_array($role, ['admin', 'teacher', 'student'])) {
            try {
                $hash = password_hash($temp_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (unit_id, name, email, password, role, must_change_password) VALUES (?, ?, ?, ?, ?, 1)");
                $stmt->execute([$unit_id, $name, $email, $hash, $role]);
                $newUserId = (int)$pdo->lastInsertId();
                
                // Se for aluno e turma foi especificada, vincula na turma
                if ($role === 'student' && $class_id) {
                    $pdo->prepare("INSERT IGNORE INTO class_students (class_id, student_id) VALUES (?, ?)")->execute([$class_id, $newUserId]);
                }
                
                // Montar link de login dinâmico
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                $host = $_SERVER['HTTP_HOST'];
                $uri = rtrim(dirname($_SERVER['REQUEST_URI']), '/\\');
                $loginLink = "$protocol://$host$uri/";
                
                // Enviar E-mail
                $emailBody = "Olá <strong>{$name}</strong>,<br><br>Sua conta na plataforma iaS acaba de ser criada!<br><br>Suas credenciais de acesso temporárias são:<br><strong>E-mail:</strong> {$email}<br><strong>Senha:</strong> {$temp_password}<br><br><a href='{$loginLink}' style='display:inline-block; padding:10px 20px; background-color:#4f46e5; color:white; text-decoration:none; border-radius:5px;'>Acessar o iaS</a><br><br><em>Aviso: Por questões de segurança, no seu primeiro login o sistema exigirá que você crie uma nova senha definitiva.</em>";
                
                send_system_email($email, "Bem-vindo ao iaS - Suas Credenciais", $emailBody);
                
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
    } elseif ($action === 'import_csv') {
        require_once 'includes/mailer.php';
        $unit_id = !empty($_POST['unit_id']) ? $_POST['unit_id'] : null;
        $default_class_id = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null;
        
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['csv_file']['tmp_name'];
            if (($handle = fopen($fileTmpPath, "r")) !== FALSE) {
                // Detectar delimitador (, ou ;)
                $firstLine = fgets($handle);
                $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
                // Remove UTF-8 BOM se presente
                $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine);
                
                $successCount = 0;
                $updatedCount = 0;
                $errorCount = 0;
                
                while (($data = fgetcsv($handle, 1000, $delimiter)) !== FALSE) {
                    if (empty($data) || (count($data) === 1 && trim($data[0]) === '')) {
                        continue;
                    }
                    $name = standardizeUserName($data[0] ?? '');
                    $email = trim($data[1] ?? '');
                    $csvClass = trim($data[2] ?? '');
                    
                    // Se for linha de cabeçalho acidental (ex: "nome", "email"), pula
                    if (mb_strtolower($name, 'UTF-8') === 'nome' && mb_strtolower($email, 'UTF-8') === 'email') {
                        continue;
                    }
                    
                    if ($name && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        // Resolução da turma
                        $targetClassId = null;
                        if (!empty($csvClass)) {
                            if (is_numeric($csvClass) && isset($classLookupById[(int)$csvClass])) {
                                $targetClassId = (int)$csvClass;
                            } elseif (isset($classLookupByName[$csvClass])) {
                                $targetClassId = $classLookupByName[$csvClass];
                            } elseif (isset($classLookupByNameNormalized[mb_strtolower($csvClass, 'UTF-8')])) {
                                $targetClassId = $classLookupByNameNormalized[mb_strtolower($csvClass, 'UTF-8')];
                            } else {
                                $stmtSearch = $pdo->prepare("SELECT id FROM classes WHERE name LIKE ? LIMIT 1");
                                $stmtSearch->execute(['%' . $csvClass . '%']);
                                $found = $stmtSearch->fetch();
                                if ($found) {
                                    $targetClassId = (int)$found['id'];
                                }
                            }
                        }
                        
                        // Fallback para turma selecionada no dropdown do modal
                        if (!$targetClassId && $default_class_id) {
                            $targetClassId = $default_class_id;
                        }
                        
                        // Unidade escolar (herdada da turma se não especificada no formulário)
                        $studentUnitId = $unit_id;
                        if (empty($studentUnitId) && $targetClassId && !empty($classLookupById[$targetClassId]['unit_id'])) {
                            $studentUnitId = $classLookupById[$targetClassId]['unit_id'];
                        }
                        
                        // Verifica se email já existe
                        $stmtCheck = $pdo->prepare("SELECT id, role, unit_id FROM users WHERE email = ?");
                        $stmtCheck->execute([$email]);
                        $existingUser = $stmtCheck->fetch();
                        
                        if (!$existingUser) {
                            $temp_password = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#*'), 0, 8);
                            $hash = password_hash($temp_password, PASSWORD_DEFAULT);
                            
                            $stmtInsert = $pdo->prepare("INSERT INTO users (unit_id, name, email, password, role, must_change_password) VALUES (?, ?, ?, ?, 'student', 1)");
                            if ($stmtInsert->execute([$studentUnitId, $name, $email, $hash])) {
                                $newUserId = (int)$pdo->lastInsertId();
                                $successCount++;
                                
                                // Vincular à turma
                                if ($targetClassId) {
                                    $stmtBind = $pdo->prepare("INSERT IGNORE INTO class_students (class_id, student_id) VALUES (?, ?)");
                                    $stmtBind->execute([$targetClassId, $newUserId]);
                                }
                                
                                // Enviar e-mail
                                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                                $host = $_SERVER['HTTP_HOST'];
                                $uri = rtrim(dirname($_SERVER['REQUEST_URI']), '/\\');
                                $loginLink = "$protocol://$host$uri/";
                                
                                $emailBody = "Olá <strong>{$name}</strong>,<br><br>Você acaba de ser cadastrado na plataforma iaS!<br><br>Suas credenciais de acesso provisórias são:<br><strong>E-mail:</strong> {$email}<br><strong>Senha:</strong> {$temp_password}<br><br><a href='{$loginLink}' style='display:inline-block; padding:10px 20px; background-color:#4f46e5; color:white; text-decoration:none; border-radius:5px;'>Acessar o iaS</a><br><br><em>Aviso: No seu primeiro acesso, o sistema pedirá que crie uma senha definitiva.</em>";
                                send_system_email($email, "Bem-vindo ao iaS - Suas Credenciais", $emailBody);
                            } else {
                                $errorCount++;
                            }
                        } else {
                            // Aluno já existente: se uma turma foi informada, atualiza vínculo da turma
                            if ($existingUser['role'] === 'student' && $targetClassId) {
                                $pdo->prepare("DELETE FROM class_students WHERE student_id = ?")->execute([$existingUser['id']]);
                                $pdo->prepare("INSERT INTO class_students (class_id, student_id) VALUES (?, ?)")->execute([$targetClassId, $existingUser['id']]);
                                
                                if (empty($existingUser['unit_id']) && !empty($studentUnitId)) {
                                    $pdo->prepare("UPDATE users SET unit_id = ? WHERE id = ?")->execute([$studentUnitId, $existingUser['id']]);
                                }
                                $updatedCount++;
                            } else {
                                $errorCount++; // Email já existe ou perfil diferente
                            }
                        }
                    } else {
                        $errorCount++; // Dados inválidos
                    }
                }
                fclose($handle);
                
                $msgParts = [];
                if ($successCount > 0) $msgParts[] = "$successCount aluno(s) cadastrado(s)";
                if ($updatedCount > 0) $msgParts[] = "$updatedCount aluno(s) já existente(s) vinculado(s) à turma";
                if ($errorCount > 0) $msgParts[] = "$errorCount ignorado(s) (já existentes ou dados inválidos)";
                
                $msg = "Importação concluída! " . (!empty($msgParts) ? implode(', ', $msgParts) : "Nenhum registro processado") . ".";
                $msgType = ($successCount > 0 || $updatedCount > 0) ? 'success' : 'error';
            } else {
                $msg = "Erro ao ler o arquivo CSV.";
                $msgType = 'error';
            }
        } else {
            $msg = "Nenhum arquivo enviado ou erro no upload.";
            $msgType = 'error';
        }
    } elseif ($action === 'edit') {
        $id = $_POST['user_id'] ?? 0;
        $unit_id = !empty($_POST['unit_id']) ? $_POST['unit_id'] : null;
        $name = standardizeUserName($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'student';
        $class_id = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null;
        
        // Se for aluno e turma selecionada e sem unidade, herda unidade da turma se houver
        if ($role === 'student' && $class_id && empty($unit_id) && !empty($classLookupById[$class_id]['unit_id'])) {
            $unit_id = $classLookupById[$class_id]['unit_id'];
        }
        
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
                
                // Gerenciamento da turma do aluno
                if ($role === 'student') {
                    $pdo->prepare("DELETE FROM class_students WHERE student_id = ?")->execute([$id]);
                    if ($class_id) {
                        $pdo->prepare("INSERT INTO class_students (class_id, student_id) VALUES (?, ?)")->execute([$class_id, $id]);
                    }
                } else {
                    // Se mudou perfil para não-aluno, desvincula de qualquer turma de aluno
                    $pdo->prepare("DELETE FROM class_students WHERE student_id = ?")->execute([$id]);
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

// Dados auxiliares para os filtros avançados (Regionais, Unidades, Cursos)
$allRegionals = $pdo->query("SELECT id, name FROM regionals ORDER BY name ASC")->fetchAll();
$allUnitsList = $pdo->query("
    SELECT u.id, u.name as unit_name, u.regional_id, r.name as regional_name
    FROM units u
    LEFT JOIN regionals r ON u.regional_id = r.id
    ORDER BY r.name ASC, u.name ASC
")->fetchAll();
$allCoursesList = $pdo->query("SELECT id, name FROM courses ORDER BY name ASC")->fetchAll();

// Contadores rápidos por perfil para os cards/atalhos de filtro
$roleCounts = ['total' => 0, 'student' => 0, 'teacher' => 0, 'admin' => 0, 'no_class' => 0];
try {
    $stmtCounts = $pdo->query("
        SELECT u.role,
               COUNT(*) as total,
               SUM(CASE WHEN u.role = 'student' AND NOT EXISTS (SELECT 1 FROM class_students cs WHERE cs.student_id = u.id) THEN 1 ELSE 0 END) as without_class
        FROM users u
        GROUP BY u.role
    ");
    foreach ($stmtCounts->fetchAll() as $rc) {
        $rKey = $rc['role'];
        $cnt  = (int)$rc['total'];
        $roleCounts['total'] += $cnt;
        if (isset($roleCounts[$rKey])) {
            $roleCounts[$rKey] = $cnt;
        }
        $roleCounts['no_class'] += (int)$rc['without_class'];
    }
} catch (Exception $e) {}

// Filtros Avançados com Cascata Inteligente (Regional -> Unidade -> Curso -> Turma)
$search         = trim($_GET['search'] ?? '');
$roleFilter     = trim($_GET['role'] ?? '');
$classFilter    = trim($_GET['class_id'] ?? '');
$courseFilter   = !empty($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$unitFilter     = trim($_GET['unit_id'] ?? '');
$regionalFilter = !empty($_GET['regional_id']) ? (int)$_GET['regional_id'] : 0;

// 1. Filtra Unidades pela Regional selecionada
$filteredUnitsList = $allUnitsList;
if ($regionalFilter > 0) {
    $filteredUnitsList = array_values(array_filter($allUnitsList, function ($un) use ($regionalFilter) {
        return (int)$un['regional_id'] === $regionalFilter;
    }));
    // Se a unidade selecionada não pertence à regional escolhida, reseta a unidade
    if (is_numeric($unitFilter)) {
        $validUnitIds = array_map(fn($u) => (int)$u['id'], $filteredUnitsList);
        if (!in_array((int)$unitFilter, $validUnitIds, true)) {
            $unitFilter = '';
        }
    }
}

// 2. Filtra Cursos que existem na Unidade (ou Regional) selecionada
$filteredCoursesList = $allCoursesList;
if (is_numeric($unitFilter)) {
    $unitCourseIds = [];
    foreach ($allClasses as $clItem) {
        if ((int)$clItem['unit_id'] === (int)$unitFilter && !empty($clItem['course_id'])) {
            $unitCourseIds[(int)$clItem['course_id']] = true;
        }
    }
    $filteredCoursesList = array_values(array_filter($allCoursesList, function ($co) use ($unitCourseIds) {
        return isset($unitCourseIds[(int)$co['id']]);
    }));
} elseif ($unitFilter === '__none__') {
    $filteredCoursesList = [];
} elseif ($regionalFilter > 0) {
    $regCourseIds = [];
    foreach ($allClasses as $clItem) {
        if ((int)($clItem['regional_id'] ?? 0) === $regionalFilter && !empty($clItem['course_id'])) {
            $regCourseIds[(int)$clItem['course_id']] = true;
        }
    }
    $filteredCoursesList = array_values(array_filter($allCoursesList, function ($co) use ($regCourseIds) {
        return isset($regCourseIds[(int)$co['id']]);
    }));
}

// Se o curso selecionado não existe na unidade/regional atual, reseta o curso
if ($courseFilter > 0) {
    $validCourseIds = array_map(fn($co) => (int)$co['id'], $filteredCoursesList);
    if (!in_array($courseFilter, $validCourseIds, true)) {
        $courseFilter = 0;
    }
}

// 3. Filtra Turmas pela Unidade, Regional e Curso selecionados
$filteredClassesList = array_values(array_filter($allClasses, function ($clItem) use ($regionalFilter, $unitFilter, $courseFilter) {
    if ($unitFilter === '__none__') {
        return empty($clItem['unit_id']);
    }
    if (is_numeric($unitFilter) && (int)$clItem['unit_id'] !== (int)$unitFilter) {
        return false;
    }
    if ($regionalFilter > 0 && (int)($clItem['regional_id'] ?? 0) !== $regionalFilter) {
        return false;
    }
    if ($courseFilter > 0 && (int)($clItem['course_id'] ?? 0) !== $courseFilter) {
        return false;
    }
    return true;
}));

// Se a turma selecionada não pertence ao filtro atual de Unidade/Curso, reseta a turma
if (is_numeric($classFilter)) {
    $validClassIds = array_map(fn($cl) => (int)$cl['id'], $filteredClassesList);
    if (!in_array((int)$classFilter, $validClassIds, true)) {
        $classFilter = '';
    }
}
if (in_array($roleFilter, ['teacher', 'admin'], true) && in_array($classFilter, ['__none__', '__with_class__'], true)) {
    $classFilter = '';
}

$sql = "SELECT u.id, u.unit_id, u.name, u.email, u.role, u.created_at, 
               un.name as unit_name, un.regional_id, r.name as regional_name,
               cs.class_id, c.name as class_name, c.course_id, co.name as course_name
        FROM users u 
        LEFT JOIN units un ON u.unit_id = un.id 
        LEFT JOIN regionals r ON un.regional_id = r.id 
        LEFT JOIN (
            SELECT student_id, MAX(class_id) as class_id 
            FROM class_students 
            GROUP BY student_id
        ) cs ON u.id = cs.student_id
        LEFT JOIN classes c ON cs.class_id = c.id
        LEFT JOIN courses co ON c.course_id = co.id
        WHERE 1=1";
$params = [];

if ($search !== '') {
    $sql .= " AND (u.name LIKE ? OR u.email LIKE ? OR c.name LIKE ? OR co.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($roleFilter !== '') {
    $sql .= " AND u.role = ?";
    $params[] = $roleFilter;
}
if ($classFilter !== '') {
    if ($classFilter === '__none__') {
        $sql .= " AND u.role = 'student' AND cs.class_id IS NULL";
    } elseif ($classFilter === '__with_class__') {
        $sql .= " AND u.role = 'student' AND cs.class_id IS NOT NULL";
    } elseif (is_numeric($classFilter)) {
        $sql .= " AND cs.class_id = ?";
        $params[] = (int)$classFilter;
    }
}
if ($courseFilter > 0) {
    $sql .= " AND c.course_id = ?";
    $params[] = $courseFilter;
}
if ($unitFilter !== '') {
    if ($unitFilter === '__none__') {
        $sql .= " AND u.unit_id IS NULL";
    } elseif (is_numeric($unitFilter)) {
        $sql .= " AND (u.unit_id = ? OR c.unit_id = ?)";
        $params[] = (int)$unitFilter;
        $params[] = (int)$unitFilter;
    }
}
if ($regionalFilter > 0) {
    $sql .= " AND un.regional_id = ?";
    $params[] = $regionalFilter;
}

$sql .= " ORDER BY u.name ASC";

$stmtUsers = $pdo->prepare($sql);
$stmtUsers->execute($params);
$users = $stmtUsers->fetchAll();

$hasActiveFilters = ($search !== '' || $roleFilter !== '' || $classFilter !== '' || $courseFilter > 0 || $unitFilter !== '' || $regionalFilter > 0);

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
                            <button onclick="openHelpModal('Gestão de Usuários', 'Controle total sobre o acesso ao sistema.<br><br><b>Como utilizar:</b><br>- Adicione e gerencie professores, alunos ou administradores.<br>- Atribua os usuários às suas Unidades SENAI.<br>- Vincule turmas diretamente aos alunos ao cadastrar ou editar com o campo suspenso de Turma.<br>- Importe alunos em massa via CSV com 2 colunas (Nome,Email) ou 3 colunas (Nome,Email,Turma).<br>- Use a barra de pesquisa para localizar usuários rapidamente.')" class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 hover:text-senai-blue hover:bg-blue-50 transition-colors shadow-inner" title="Como Usar">
                                <i class="ph-bold ph-question text-lg"></i>
                            </button>
                        </h1>
                        <p class="text-slate-500 font-medium">Controle total de professores, alunos e administradores do sistema.</p>
                    </div>
                    
                    <div class="flex gap-2">
                        <button onclick="openModal('importUsersModal')" class="bg-indigo-50 hover:bg-indigo-100 border border-indigo-200 text-indigo-700 px-5 py-3 rounded-xl font-bold transition-all active:scale-95 text-sm flex items-center gap-2">
                            <i class="ph-bold ph-upload-simple text-lg"></i>
                            Importar em Massa
                        </button>
                        <button onclick="openModal('createModal')" class="bg-senai-blue hover:bg-blue-800 text-white px-5 py-3 rounded-xl font-bold shadow-sm transition-all active:scale-95 text-sm flex items-center gap-2 hover:shadow-md">
                            <i class="ph-bold ph-user-plus text-lg"></i>
                            Cadastrar Usuário
                        </button>
                    </div>
                </div>

                <?php if($msg): ?>
                    <div class="mb-6 p-4 rounded-xl flex items-center gap-3 font-medium animate-slide-up <?= $msgType === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200' ?>">
                        <i class="<?= $msgType === 'success' ? 'ph-fill ph-check-circle' : 'ph-fill ph-warning-circle' ?> text-xl"></i>
                        <?= htmlspecialchars($msg) ?>
                    </div>
                <?php endif; ?>

                <!-- Atalhos Rápidos por Tipo de Usuário (Role) e Situação de Turma -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-5">
                    <a href="admin_users.php" class="p-3.5 rounded-2xl border transition-all flex items-center justify-between <?= ($roleFilter === '' && $classFilter === '') ? 'bg-slate-800 text-white border-slate-800 shadow-md' : 'bg-white/80 hover:bg-white text-slate-700 border-slate-200/80 shadow-sm' ?>">
                        <div class="flex items-center gap-2.5">
                            <i class="ph-fill ph-users-three text-lg <?= ($roleFilter === '' && $classFilter === '') ? 'text-senai-cyan' : 'text-slate-400' ?>"></i>
                            <span class="text-xs font-bold">Todos</span>
                        </div>
                        <span class="text-xs font-black px-2 py-0.5 rounded-lg <?= ($roleFilter === '' && $classFilter === '') ? 'bg-white/15 text-white' : 'bg-slate-100 text-slate-700' ?>"><?= $roleCounts['total'] ?></span>
                    </a>

                    <a href="admin_users.php?role=student" class="p-3.5 rounded-2xl border transition-all flex items-center justify-between <?= ($roleFilter === 'student' && $classFilter !== '__none__') ? 'bg-indigo-600 text-white border-indigo-600 shadow-md' : 'bg-white/80 hover:bg-white text-slate-700 border-slate-200/80 shadow-sm' ?>">
                        <div class="flex items-center gap-2.5">
                            <i class="ph-fill ph-student text-lg <?= ($roleFilter === 'student' && $classFilter !== '__none__') ? 'text-white' : 'text-indigo-500' ?>"></i>
                            <span class="text-xs font-bold">Alunos</span>
                        </div>
                        <span class="text-xs font-black px-2 py-0.5 rounded-lg <?= ($roleFilter === 'student' && $classFilter !== '__none__') ? 'bg-white/20 text-white' : 'bg-indigo-50 text-indigo-700' ?>"><?= $roleCounts['student'] ?></span>
                    </a>

                    <a href="admin_users.php?role=teacher" class="p-3.5 rounded-2xl border transition-all flex items-center justify-between <?= $roleFilter === 'teacher' ? 'bg-senai-blue text-white border-senai-blue shadow-md' : 'bg-white/80 hover:bg-white text-slate-700 border-slate-200/80 shadow-sm' ?>">
                        <div class="flex items-center gap-2.5">
                            <i class="ph-fill ph-chalkboard-teacher text-lg <?= $roleFilter === 'teacher' ? 'text-white' : 'text-senai-blue' ?>"></i>
                            <span class="text-xs font-bold">Professores</span>
                        </div>
                        <span class="text-xs font-black px-2 py-0.5 rounded-lg <?= $roleFilter === 'teacher' ? 'bg-white/20 text-white' : 'bg-blue-50 text-senai-blue' ?>"><?= $roleCounts['teacher'] ?></span>
                    </a>

                    <a href="admin_users.php?role=admin" class="p-3.5 rounded-2xl border transition-all flex items-center justify-between <?= $roleFilter === 'admin' ? 'bg-senai-orange text-white border-senai-orange shadow-md' : 'bg-white/80 hover:bg-white text-slate-700 border-slate-200/80 shadow-sm' ?>">
                        <div class="flex items-center gap-2.5">
                            <i class="ph-fill ph-shield-star text-lg <?= $roleFilter === 'admin' ? 'text-white' : 'text-senai-orange' ?>"></i>
                            <span class="text-xs font-bold">Admins</span>
                        </div>
                        <span class="text-xs font-black px-2 py-0.5 rounded-lg <?= $roleFilter === 'admin' ? 'bg-white/20 text-white' : 'bg-orange-50 text-senai-orange' ?>"><?= $roleCounts['admin'] ?></span>
                    </a>

                    <a href="admin_users.php?role=student&class_id=__none__" class="p-3.5 rounded-2xl border transition-all flex items-center justify-between <?= $classFilter === '__none__' ? 'bg-amber-600 text-white border-amber-600 shadow-md' : 'bg-white/80 hover:bg-white text-slate-700 border-slate-200/80 shadow-sm' ?>">
                        <div class="flex items-center gap-2.5">
                            <i class="ph-fill ph-warning-circle text-lg <?= $classFilter === '__none__' ? 'text-white' : 'text-amber-500' ?>"></i>
                            <span class="text-xs font-bold">Sem Turma</span>
                        </div>
                        <span class="text-xs font-black px-2 py-0.5 rounded-lg <?= $classFilter === '__none__' ? 'bg-white/20 text-white' : 'bg-amber-50 text-amber-700' ?>"><?= $roleCounts['no_class'] ?></span>
                    </a>
                </div>

                <!-- Painel Completo de Filtros em Cascata (Regional -> Unidade -> Curso -> Turma -> Perfil) -->
                <div class="glass-panel p-5 mb-6">
                    <form id="usersFilterForm" method="GET" action="admin_users.php" class="space-y-3">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            <!-- 1. Filtro por Regional -->
                            <div>
                                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">1. Regional (DR)</label>
                                <select id="filter_regional_id" name="regional_id" onchange="onCascadeFilterChange('regional')" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs md:text-sm text-slate-700 font-medium focus:outline-none focus:border-senai-blue transition-all">
                                    <option value="">Todas as Regionais</option>
                                    <?php foreach ($allRegionals as $regItem): ?>
                                        <option value="<?= $regItem['id'] ?>" <?= $regionalFilter === (int)$regItem['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($regItem['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- 2. Filtro por Unidade Escolar (filtrado pela Regional) -->
                            <div>
                                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">
                                    2. Unidade Escolar <?= $regionalFilter > 0 ? '<span class="text-senai-blue">(' . count($filteredUnitsList) . ')</span>' : '' ?>
                                </label>
                                <select id="filter_unit_id" name="unit_id" onchange="onCascadeFilterChange('unit')" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs md:text-sm text-slate-700 font-medium focus:outline-none focus:border-senai-blue transition-all">
                                    <option value="">Todas as Unidades<?= $regionalFilter > 0 ? ' da Regional' : '' ?></option>
                                    <option value="__none__" <?= $unitFilter === '__none__' ? 'selected' : '' ?>>Sem Unidade Vinculada</option>
                                    <?php foreach ($filteredUnitsList as $unItem): ?>
                                        <option value="<?= $unItem['id'] ?>" <?= (is_numeric($unitFilter) && (int)$unitFilter === (int)$unItem['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($unItem['unit_name']) ?><?= ($regionalFilter === 0 && !empty($unItem['regional_name'])) ? ' (' . htmlspecialchars($unItem['regional_name']) . ')' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- 3. Filtro por Curso (filtrado pela Unidade / Regional) -->
                            <div>
                                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">
                                    3. Curso <?= (is_numeric($unitFilter) || $regionalFilter > 0) ? '<span class="text-senai-blue">(' . count($filteredCoursesList) . ')</span>' : '' ?>
                                </label>
                                <select id="filter_course_id" name="course_id" onchange="onCascadeFilterChange('course')" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs md:text-sm text-slate-700 font-medium focus:outline-none focus:border-senai-blue transition-all">
                                    <option value="">Todos os Cursos<?= is_numeric($unitFilter) ? ' da Unidade' : ($regionalFilter > 0 ? ' da Regional' : '') ?></option>
                                    <?php foreach ($filteredCoursesList as $coItem): ?>
                                        <option value="<?= $coItem['id'] ?>" <?= $courseFilter === (int)$coItem['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($coItem['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- 4. Filtro por Turma (filtrado pela Unidade e Curso) -->
                            <div>
                                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">
                                    4. Turma <?= ($courseFilter > 0 || is_numeric($unitFilter) || $regionalFilter > 0) ? '<span class="text-senai-blue">(' . count($filteredClassesList) . ')</span>' : '' ?>
                                </label>
                                <select id="filter_class_id" name="class_id" onchange="onCascadeFilterChange('class')" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs md:text-sm text-slate-700 font-medium focus:outline-none focus:border-senai-blue transition-all">
                                    <option value="">Todas as Turmas<?= $courseFilter > 0 ? ' do Curso' : (is_numeric($unitFilter) ? ' da Unidade' : '') ?></option>
                                    <option value="__none__" <?= $classFilter === '__none__' ? 'selected' : '' ?>>⚠️ Alunos Sem Turma Vinculada</option>
                                    <option value="__with_class__" <?= $classFilter === '__with_class__' ? 'selected' : '' ?>>✅ Alunos Com Turma Vinculada</option>
                                    <?php foreach ($filteredClassesList as $c): ?>
                                        <option value="<?= $c['id'] ?>" <?= (is_numeric($classFilter) && (int)$classFilter === (int)$c['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($c['name']) ?><?= ($courseFilter === 0 && !empty($c['course_name'])) ? ' — ' . htmlspecialchars($c['course_name']) : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 pt-2 border-t border-slate-100 items-end">
                            <!-- 5. Tipo de Usuário (Role) -->
                            <div>
                                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">5. Tipo de Usuário</label>
                                <select id="filter_role" name="role" onchange="onCascadeFilterChange('role')" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs md:text-sm text-slate-700 font-medium focus:outline-none focus:border-senai-blue transition-all">
                                    <option value="">Todos os Perfis</option>
                                    <option value="student" <?= $roleFilter === 'student' ? 'selected' : '' ?>>Alunos</option>
                                    <option value="teacher" <?= $roleFilter === 'teacher' ? 'selected' : '' ?>>Professores</option>
                                    <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Administradores</option>
                                </select>
                            </div>

                            <!-- 6. Busca textual -->
                            <div class="sm:col-span-2">
                                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">6. Buscar por Nome / E-mail / Turma</label>
                                <div class="relative">
                                    <i class="ph ph-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"></i>
                                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Digite nome, e-mail, turma ou curso..." class="w-full pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs md:text-sm focus:outline-none focus:border-senai-blue focus:ring-1 focus:ring-senai-blue transition-all">
                                </div>
                            </div>

                            <!-- Botões de Ação do Filtro -->
                            <div class="flex items-center gap-2 justify-end">
                                <?php if ($hasActiveFilters): ?>
                                    <a href="admin_users.php" class="px-4 py-2 bg-slate-100 text-slate-600 rounded-xl font-bold text-xs md:text-sm hover:bg-slate-200 transition-colors flex items-center gap-1.5">
                                        <i class="ph-bold ph-x"></i> Limpar
                                    </a>
                                <?php endif; ?>
                                <button type="submit" class="flex-1 sm:flex-initial px-5 py-2 bg-slate-800 text-white rounded-xl font-bold text-xs md:text-sm hover:bg-slate-700 transition-colors flex items-center justify-center gap-1.5 shadow-sm">
                                    <i class="ph-bold ph-funnel"></i> Filtrar
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                <script>
                    function onCascadeFilterChange(changedLevel) {
                        const form = document.getElementById('usersFilterForm');
                        if (!form) return;
                        const unitSel = document.getElementById('filter_unit_id');
                        const courseSel = document.getElementById('filter_course_id');
                        const classSel = document.getElementById('filter_class_id');

                        if (changedLevel === 'regional') {
                            if (unitSel) unitSel.value = '';
                            if (courseSel) courseSel.value = '';
                            if (classSel) classSel.value = '';
                        } else if (changedLevel === 'unit') {
                            if (courseSel) courseSel.value = '';
                            if (classSel) classSel.value = '';
                        } else if (changedLevel === 'course') {
                            if (classSel) classSel.value = '';
                        }
                        form.submit();
                    }
                </script>

                <div class="glass-panel overflow-hidden">
                    <div class="px-4 py-3 border-b border-slate-100 bg-white/60 flex items-center justify-between text-xs text-slate-500 font-semibold">
                        <span>Exibindo <strong class="text-slate-800"><?= count($users) ?></strong> usuário(s) encontrado(s)</span>
                        <?php if ($hasActiveFilters): ?>
                            <span class="text-indigo-600 bg-indigo-50 px-2.5 py-0.5 rounded-full text-[11px] font-bold">Filtros ativos</span>
                        <?php endif; ?>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="border-b border-slate-200 text-xs uppercase tracking-widest text-slate-400 bg-slate-50/50">
                                    <th class="p-4 font-semibold">Usuário</th>
                                    <th class="p-4 font-semibold">Turma / Curso</th>
                                    <th class="p-4 font-semibold">Email</th>
                                    <th class="p-4 font-semibold">Unidade Escolar</th>
                                    <th class="p-4 font-semibold">Perfil (Role)</th>
                                    <th class="p-4 font-semibold text-right">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="text-sm">
                                <?php if(empty($users)): ?>
                                <tr>
                                    <td colspan="6" class="p-8 text-center text-slate-400 font-medium">Nenhum usuário encontrado para os filtros selecionados.</td>
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
                                        <div class="w-10 h-10 rounded-full bg-slate-200 flex items-center justify-center text-slate-500 font-bold shadow-inner flex-shrink-0">
                                            <?= substr($u['name'], 0, 1) ?>
                                        </div>
                                        <div class="flex flex-col">
                                            <span class="text-slate-800"><?= htmlspecialchars($u['name']) ?></span>
                                        </div>
                                    </td>
                                    <td class="p-4 text-xs">
                                        <?php if ($u['role'] === 'student'): ?>
                                            <?php if (!empty($u['class_name'])): ?>
                                                <a href="admin_users.php?class_id=<?= (int)$u['class_id'] ?>" class="inline-flex items-center gap-1 text-[11px] font-bold text-indigo-700 bg-indigo-50 hover:bg-indigo-100 border border-indigo-200/70 px-2.5 py-1 rounded-lg w-max transition-colors" title="Filtrar por esta turma">
                                                    <i class="ph-bold ph-chalkboard text-indigo-500"></i> <?= htmlspecialchars($u['class_name']) ?>
                                                </a>
                                                <?php if (!empty($u['course_name'])): ?>
                                                    <div class="text-[11px] text-slate-400 font-medium mt-0.5 truncate max-w-[200px]" title="<?= htmlspecialchars($u['course_name']) ?>">
                                                        <?= htmlspecialchars($u['course_name']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <a href="admin_users.php?role=student&class_id=__none__" class="inline-flex items-center gap-1 text-[10px] font-bold text-amber-700 bg-amber-50 hover:bg-amber-100 border border-amber-200/70 px-2 py-0.5 rounded-md w-max transition-colors" title="Aluno ainda sem turma vinculada">
                                                    <i class="ph-bold ph-warning text-amber-500"></i> Sem Turma
                                                </a>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-slate-400 italic">—</span>
                                        <?php endif; ?>
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
        <form method="POST" id="createUserForm" class="p-6">
            <input type="hidden" name="action" value="create">
            
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5 flex items-center justify-between">
                        <span>Nome Completo</span>
                        <span class="text-[10px] text-senai-blue font-semibold normal-case">Iniciais Maiúsculas Automáticas</span>
                    </label>
                    <input type="text" name="name" id="create_name" required onblur="this.value = formatUserNameTitleCase(this.value)" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-senai-blue focus:ring-1 focus:ring-senai-blue transition-all" placeholder="Ex: João Da Silva">
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

                <div id="create_class_container">
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5 flex items-center justify-between">
                        <span>Vincular à Turma</span>
                        <span class="text-[10px] text-slate-400 font-normal lowercase">(opcional para alunos)</span>
                    </label>
                    <select name="class_id" id="create_class_id" class="search-select w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-senai-blue focus:ring-1 focus:ring-senai-blue transition-all">
                        <option value="">Nenhuma turma vinculada</option>
                        <?php foreach ($allClasses as $c): ?>
                            <option value="<?= $c['id'] ?>">
                                <?= htmlspecialchars($c['name']) ?> — <?= htmlspecialchars($c['course_name'] ?? 'Geral') ?> (<?= $c['year'] ?>/<?= $c['semester'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="mt-8 flex justify-end gap-3">
                <button type="button" onclick="closeModal('createModal')" class="px-5 py-2.5 rounded-xl font-bold text-sm text-slate-500 hover:bg-slate-100 transition-colors">Cancelar</button>
                <button type="submit" id="btn-create-user-submit" class="px-5 py-2.5 rounded-xl font-bold text-sm bg-senai-blue text-white hover:bg-blue-800 transition-all shadow-sm hover:shadow-md disabled:opacity-60 disabled:cursor-not-allowed flex items-center gap-2">Salvar Usuário</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Importação -->
<div id="importUsersModal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-xl w-full max-w-md overflow-hidden scale-95 opacity-0 transition-all duration-300" id="importUsersModalContent">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-indigo-50/50">
            <h3 class="font-bold text-lg text-slate-800 flex items-center gap-2"><i class="ph-bold ph-upload-simple text-indigo-500"></i> Importar Alunos</h3>
            <button type="button" onclick="closeModal('importUsersModal')" class="text-slate-400 hover:text-red-500 transition-colors"><i class="ph-bold ph-x text-xl"></i></button>
        </div>
        <form method="POST" id="importUsersForm" enctype="multipart/form-data" class="p-6">
            <input type="hidden" name="action" value="import_csv">
            
            <div class="space-y-4">
                <div class="bg-blue-50 border border-blue-100 p-4 rounded-xl text-sm text-blue-800 flex flex-col gap-2">
                    <p>Baixe a planilha modelo, preencha os dados e faça o upload abaixo. O arquivo pode conter 2 colunas (<code>Nome,Email</code>) ou 3 colunas (<code>Nome,Email,Turma</code>). A senha provisória será gerada e enviada por e-mail automaticamente.</p>
                    <a href="template_alunos.csv?v=<?= file_exists('template_alunos.csv') ? filemtime('template_alunos.csv') : time() ?>" download class="inline-flex items-center justify-center gap-2 bg-white border border-blue-200 text-blue-700 px-3 py-2 rounded-lg font-bold hover:bg-blue-100 transition-colors text-center shadow-sm">
                        <i class="ph-bold ph-download-simple"></i> Baixar Modelo CSV
                    </a>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5 flex items-center justify-between">
                        <span>Vincular à Turma Padrão</span>
                        <span class="text-[10px] text-indigo-500 font-normal lowercase">(opcional se vier no CSV)</span>
                    </label>
                    <select name="class_id" class="search-select w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all">
                        <option value="">Nenhuma turma pré-definida (ler do CSV ou definir depois)</option>
                        <?php foreach ($allClasses as $c): ?>
                            <option value="<?= $c['id'] ?>">
                                <?= htmlspecialchars($c['name']) ?> — <?= htmlspecialchars($c['course_name'] ?? 'Geral') ?> (<?= $c['year'] ?>/<?= $c['semester'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-[11px] text-slate-400 mt-1">
                        Se a linha do CSV trouxer a coluna "Turma", ela terá prioridade sobre esta seleção.
                    </p>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Vincular à Unidade Escolar</label>
                    <select name="unit_id" class="search-select w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all">
                        <option value="">Sem Unidade (Global / Herdado da Turma)</option>
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
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Arquivo CSV</label>
                    <input type="file" name="csv_file" accept=".csv" required class="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-bold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 cursor-pointer">
                </div>
            </div>
            
            <div class="mt-8 flex justify-end gap-3">
                <button type="button" onclick="closeModal('importUsersModal')" class="px-5 py-2.5 rounded-xl font-bold text-sm text-slate-500 hover:bg-slate-100 transition-colors">Cancelar</button>
                <button type="submit" id="btn-import-users-submit" class="px-5 py-2.5 rounded-xl font-bold text-sm bg-indigo-600 text-white hover:bg-indigo-700 transition-all shadow-sm hover:shadow-md disabled:opacity-60 disabled:cursor-not-allowed flex items-center gap-2">
                    <i class="ph-bold ph-upload-simple"></i> Iniciar Importação
                </button>
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
        <form method="POST" id="editUserForm" class="p-6">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="user_id" id="edit_user_id">
            
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5 flex items-center justify-between">
                        <span>Nome Completo</span>
                        <span class="text-[10px] text-indigo-600 font-semibold normal-case">Iniciais Maiúsculas Automáticas</span>
                    </label>
                    <input type="text" name="name" id="edit_name" required onblur="this.value = formatUserNameTitleCase(this.value)" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all">
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

                <div id="edit_class_container">
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5 flex items-center justify-between">
                        <span>Turma do Aluno</span>
                        <span class="text-[10px] text-indigo-600 font-normal lowercase">campo suspenso</span>
                    </label>
                    <select name="class_id" id="edit_class_id" class="search-select w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all">
                        <option value="">Nenhuma turma vinculada</option>
                        <?php foreach ($allClasses as $c): ?>
                            <option value="<?= $c['id'] ?>">
                                <?= htmlspecialchars($c['name']) ?> — <?= htmlspecialchars($c['course_name'] ?? 'Geral') ?> (<?= $c['year'] ?>/<?= $c['semester'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-[11px] text-slate-400 mt-1">Selecione uma turma para vincular ou transferir este aluno.</p>
                </div>
            </div>
            
            <div class="mt-8 flex justify-end gap-3">
                <button type="button" onclick="closeModal('editModal')" class="px-5 py-2.5 rounded-xl font-bold text-sm text-slate-500 hover:bg-slate-100 transition-colors">Cancelar</button>
                <button type="submit" id="btn-edit-user-submit" class="px-5 py-2.5 rounded-xl font-bold text-sm bg-indigo-600 text-white hover:bg-indigo-700 transition-all shadow-sm hover:shadow-md disabled:opacity-60 disabled:cursor-not-allowed flex items-center gap-2">Salvar Alterações</button>
            </div>
        </form>
    </div>
</div>

<script>
    function formatUserNameTitleCase(str) {
        return String(str || '')
            .trim()
            .replace(/\s+/g, ' ')
            .toLowerCase()
            .replace(/(^|\s)\S/g, (match) => match.toUpperCase());
    }

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
        document.getElementById('edit_name').value = formatUserNameTitleCase(user.name);
        document.getElementById('edit_email').value = user.email;
        if(user.unit_id) {
            document.getElementById('edit_unit_id').value = user.unit_id;
        } else {
            document.getElementById('edit_unit_id').value = '';
        }

        // Configurar turma do aluno
        const classContainer = document.getElementById('edit_class_container');
        const classSelect = document.getElementById('edit_class_id');
        if (classSelect) {
            classSelect.value = (user.class_id && user.class_id !== null) ? user.class_id : '';
        }
        
        // Selecionar o role correto
        if(user.role === 'admin') {
            document.getElementById('edit_role_admin').checked = true;
            if (classContainer) classContainer.classList.add('hidden');
        } else if(user.role === 'teacher') {
            document.getElementById('edit_role_teacher').checked = true;
            if (classContainer) classContainer.classList.add('hidden');
        } else {
            document.getElementById('edit_role_student').checked = true;
            if (classContainer) classContainer.classList.remove('hidden');
        }
        
        openModal('editModal');
    }

    // Alternar exibição do seletor de turma dinamicamente de acordo com o perfil selecionado
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('input[name="role"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const form = this.closest('form');
                if (form) {
                    const classContainer = form.querySelector('[id$="_class_container"]');
                    if (classContainer) {
                        if (this.value === 'student') {
                            classContainer.classList.remove('hidden');
                        } else {
                            classContainer.classList.add('hidden');
                        }
                    }
                }
            });
        });

        // Bloqueia duplo clique e exibe overlay de carregamento durante a importação de alunos (CSV) e salvamento
        const importUsersForm = document.getElementById('importUsersForm');
        if (importUsersForm) {
            importUsersForm.addEventListener('submit', function(e) {
                if (this.dataset.submitting === '1') {
                    e.preventDefault();
                    return;
                }
                this.dataset.submitting = '1';
                const btn = document.getElementById('btn-import-users-submit');
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<i class="ph-bold ph-spinner animate-spin"></i> Importando Alunos...';
                }
                if (typeof showImportOverlay === 'function') {
                    showImportOverlay(
                        'Importando Alunos em Massa (CSV)...',
                        'Cadastrando usuários, vinculando turmas e enviando credenciais por e-mail. Isso pode levar alguns segundos.'
                    );
                }
            });
        }

        const createUserForm = document.getElementById('createUserForm');
        if (createUserForm) {
            createUserForm.addEventListener('submit', function(e) {
                if (this.dataset.submitting === '1') {
                    e.preventDefault();
                    return;
                }
                this.dataset.submitting = '1';
                const btn = document.getElementById('btn-create-user-submit');
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<i class="ph-bold ph-spinner animate-spin"></i> Salvando...';
                }
                if (typeof showImportOverlay === 'function') {
                    showImportOverlay(
                        'Criando Usuário...',
                        'Gerando credenciais de acesso e enviando e-mail de boas-vindas.'
                    );
                }
            });
        }

        const editUserForm = document.getElementById('editUserForm');
        if (editUserForm) {
            editUserForm.addEventListener('submit', function(e) {
                if (this.dataset.submitting === '1') {
                    e.preventDefault();
                    return;
                }
                this.dataset.submitting = '1';
                const btn = document.getElementById('btn-edit-user-submit');
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<i class="ph-bold ph-spinner animate-spin"></i> Salvando...';
                }
            });
        }
    });
</script>

<?php include 'includes/footer.php'; ?>
