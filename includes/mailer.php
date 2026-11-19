<?php
// includes/mailer.php

function send_system_email($to, $subject, $message) {
    // Cabeçalhos para enviar HTML com a função mail() nativa
    $headers  = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    
    // Remetente (Opcional: Ajuste para o domínio do seu servidor)
    $headers .= "From: iaS Plataforma <no-reply@seu-servidor.com>" . "\r\n";
    
    // Template HTML básico
    $html_body = "
    <html>
    <body style='font-family: Arial, sans-serif; background-color: #f8fafc; padding: 20px; color: #334155;'>
        <div style='max-width: 600px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 12px; border-top: 6px solid #4f46e5; box-shadow: 0 4px 6px rgba(0,0,0,0.05);'>
            <h2 style='color: #1e293b; margin-top: 0;'>Sistema iaS</h2>
            <div style='font-size: 16px; line-height: 1.6;'>
                $message
            </div>
            <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 30px 0;'>
            <p style='color: #94a3b8; font-size: 12px; text-align: center; margin-bottom: 0;'>
                Este é um e-mail automático do iaS.<br>Por favor, não responda.
            </p>
        </div>
    </body>
    </html>
    ";
    
    // Usa a função mail nativa do PHP
    return @mail($to, $subject, $html_body, $headers);
}

/**
 * Gera o link direto (1 clique) para adicionar uma Sprint ao Google Agenda do professor
 */
function build_sprint_google_calendar_url(array $sprint, string $baseUrl = ''): string {
    $startTs = !empty($sprint['start_time'])
        ? strtotime($sprint['start_time'])
        : (!empty($sprint['created_at']) ? strtotime($sprint['created_at']) : time());

    $durationMinutes = max(30, (int)($sprint['time_limit_minutes'] ?? 60));
    $endTs = $startTs + ($durationMinutes * 60);
    if (!empty($sprint['end_time'])) {
        $parsedEnd = strtotime($sprint['end_time']);
        // Se o prazo final for no mesmo turno (até 6h de diferença), usa-o como fim do evento na agenda
        if ($parsedEnd > $startTs && ($parsedEnd - $startTs) <= 6 * 3600) {
            $endTs = $parsedEnd;
        }
    }

    $datesParam = date('Ymd\THis', $startTs) . '/' . date('Ymd\THis', $endTs);
    $title = "[iaS SENAI] Sprint: " . ($sprint['name'] ?? 'Avaliação') . " — Turma " . ($sprint['class_name'] ?? '');

    $detailsLines = [
        "Avaliação / Diagnóstica no sistema iaS SENAI",
        "• Sprint: " . ($sprint['name'] ?? ''),
        "• Turma: " . ($sprint['class_name'] ?? '') . (!empty($sprint['course_name']) ? " (" . $sprint['course_name'] . ")" : ""),
        "• Questões: " . ($sprint['total_questions'] ?? '-') . " | Tempo limite: " . ($sprint['time_limit_minutes'] ?? '-') . " min",
        "• Lembrete: Orientar a turma sobre a Trava Anticola (máx. 3 saídas de aba/janela).",
    ];
    if ($baseUrl !== '') {
        $detailsLines[] = "• Acesso à plataforma: " . $baseUrl;
    }

    $location = !empty($sprint['unit_name']) ? $sprint['unit_name'] : 'Plataforma iaS SENAI';

    $query = http_build_query([
        'action'   => 'TEMPLATE',
        'text'     => $title,
        'dates'    => $datesParam,
        'ctz'      => 'America/Sao_Paulo',
        'details'  => implode("\n", $detailsLines),
        'location' => $location
    ], '', '&', PHP_QUERY_RFC3986);

    return "https://calendar.google.com/calendar/render?" . $query;
}

/**
 * Envia um e-mail completo ao professor vinculado à Sprint com:
 * 1. Dados do agendamento e botão de 1 clique para adicionar ao Google Agenda
 * 2. Guia amigável de como orientar os alunos no acesso à plataforma
 * 3. Alerta detalhado sobre o funcionamento da Trava Anticola (monitoramento de foco de tela)
 */
function send_teacher_sprint_schedule_email(PDO $pdo, int $sprintId, bool $isReschedule = false): bool {
    $stmt = $pdo->prepare("
        SELECT s.*,
               c.name AS class_name,
               co.name AS course_name,
               u.name AS unit_name,
               t.name AS teacher_name,
               t.email AS teacher_email,
               (SELECT COUNT(*) FROM sprint_questions sq WHERE sq.sprint_id = s.id) AS total_questions
        FROM sprints s
        JOIN classes c ON s.class_id = c.id
        LEFT JOIN courses co ON c.course_id = co.id
        LEFT JOIN units u ON c.unit_id = u.id
        LEFT JOIN users t ON s.teacher_id = t.id
        WHERE s.id = ?
        LIMIT 1
    ");
    $stmt->execute([$sprintId]);
    $sprint = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sprint || empty($sprint['teacher_email'])) {
        return false;
    }

    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $uri = isset($_SERVER['REQUEST_URI']) ? rtrim(dirname($_SERVER['REQUEST_URI']), '/\\') : '';
    $baseUrl = "{$protocol}://{$host}{$uri}/";
    $reportUrl = "{$baseUrl}sprint_report.php?id=" . (int)$sprint['id'];
    $agendaUrl = "{$baseUrl}agenda.php";

    $gcalUrl = build_sprint_google_calendar_url($sprint, $baseUrl);

    $teacherName = htmlspecialchars($sprint['teacher_name'] ?: 'Professor(a)');
    $sprintName  = htmlspecialchars($sprint['name']);
    $className   = htmlspecialchars($sprint['class_name'] ?? '');
    $courseName  = htmlspecialchars($sprint['course_name'] ?? '');
    $unitName    = htmlspecialchars($sprint['unit_name'] ?: 'Unidade SENAI');
    $totalQs     = (int)($sprint['total_questions'] ?? 0);
    $timeLimit   = (int)($sprint['time_limit_minutes'] ?? 0);
    $timeMin     = (int)($sprint['time_min_minutes'] ?? 0);

    $isScheduledFuture = (!empty($sprint['start_time']) && strtotime($sprint['start_time']) > time()) || ($sprint['status'] === 'scheduled');
    $startFormatted = !empty($sprint['start_time'])
        ? date('d/m/Y \à\s H:i', strtotime($sprint['start_time']))
        : date('d/m/Y \à\s H:i') . ' (Liberação Imediata)';
    $endFormatted = !empty($sprint['end_time'])
        ? date('d/m/Y \à\s H:i', strtotime($sprint['end_time']))
        : 'Sem prazo limite fixo';

    $actionHeadline = $isReschedule
        ? "O agendamento da sua avaliação diagnóstica (Sprint) foi atualizado!"
        : ($isScheduledFuture
            ? "Uma nova avaliação diagnóstica (Sprint) foi agendada para a sua turma!"
            : "Uma nova avaliação diagnóstica (Sprint) foi criada e liberada para a sua turma!");

    $minTimeText = $timeMin > 0 ? " • Tempo mínimo de leitura: <strong>{$timeMin} min</strong>" : "";

    $message = "
        <p style='margin-top:0;'>Olá, Professor(a) <strong>{$teacherName}</strong>!</p>
        <p>{$actionHeadline}</p>

        <!-- Card de Dados da Sprint -->
        <div style='background-color:#f8fafc; border:1px solid #e2e8f0; border-left:5px solid #1A428A; border-radius:10px; padding:16px; margin:18px 0;'>
            <h3 style='margin:0 0 10px 0; color:#1A428A; font-size:17px;'>📋 {$sprintName}</h3>
            <p style='margin:4px 0; font-size:14px;'><strong>Turma:</strong> {$className}" . ($courseName !== '' ? " ({$courseName})" : "") . "</p>
            <p style='margin:4px 0; font-size:14px;'><strong>Unidade:</strong> {$unitName}</p>
            <p style='margin:4px 0; font-size:14px;'><strong>Data e Horário de Início:</strong> <span style='color:#4f46e5; font-weight:bold;'>{$startFormatted}</span></p>
            " . (!empty($sprint['end_time']) ? "<p style='margin:4px 0; font-size:14px;'><strong>Prazo Limite de Entrega:</strong> {$endFormatted}</p>" : "") . "
            <p style='margin:4px 0; font-size:14px;'><strong>Estrutura:</strong> {$totalQs} questões • Tempo máximo: <strong>{$timeLimit} min</strong>{$minTimeText}</p>
        </div>

        <!-- Botão Adicionar ao Google Agenda -->
        <div style='background-color:#eef2ff; border:1px solid #c7d2fe; border-radius:10px; padding:16px; margin:20px 0; text-align:center;'>
            <p style='margin:0 0 12px 0; font-size:14px; color:#312e81; font-weight:bold;'>
                📅 Deseja salvar esta aplicação na sua agenda pessoal para receber lembrete?
            </p>
            <a href='{$gcalUrl}' target='_blank' style='display:inline-block; padding:11px 22px; background-color:#4f46e5; color:#ffffff; text-decoration:none; border-radius:8px; font-weight:bold; font-size:14px; margin:4px;'>
                + Adicionar ao meu Google Agenda
            </a>
            <a href='{$agendaUrl}' target='_blank' style='display:inline-block; padding:11px 18px; background-color:#ffffff; color:#1A428A; border:1px solid #cbd5e1; text-decoration:none; border-radius:8px; font-weight:bold; font-size:14px; margin:4px;'>
                Ver Agenda da Plataforma
            </a>
        </div>

        <!-- Guia de Orientação para o Professor -->
        <div style='background-color:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:18px; margin:22px 0;'>
            <h3 style='margin:0 0 10px 0; color:#166534; font-size:16px;'>
                💡 Professor(a), saiba como orientar seus alunos a usarem a plataforma no dia da prova:
            </h3>
            <ol style='margin:0; padding-left:20px; font-size:13.5px; color:#1e293b; line-height:1.65;'>
                <li style='margin-bottom:8px;'>
                    <strong>Link de Acesso:</strong> Peça para os estudantes acessarem <a href='{$baseUrl}' style='color:#1A428A; font-weight:bold;'>{$baseUrl}</a> pelo navegador.
                </li>
                <li style='margin-bottom:8px;'>
                    <strong>Login do Estudante:</strong>
                    <ul style='margin:4px 0 0 0; padding-left:18px;'>
                        <li>Alunos já ativos entram com seu <strong>e-mail</strong> e <strong>senha pessoal</strong>.</li>
                        <li><strong>1º Acesso:</strong> Alunos recém-cadastrados entram com a senha provisória enviada ao e-mail deles (ou número de matrícula, se importados do SAEP). No primeiro login, o sistema solicitará a criação de uma senha definitiva e confirmação da turma.</li>
                        <li>Caso algum aluno tenha esquecido a senha, basta clicar em <em>&ldquo;Esqueci minha senha&rdquo;</em> na tela de login para receber o link de redefinição.</li>
                    </ul>
                </li>
                <li style='margin-bottom:8px;'>
                    <strong>Início da Prova:</strong> A Sprint aparecerá liberada no painel do aluno automaticamente a partir do horário agendado (<strong>{$startFormatted}</strong>).
                </li>
                <li style='margin-bottom:4px;'>
                    <strong>Salvamento Automático e Imagens:</strong> A cada alternativa marcada, o sistema salva a resposta automaticamente (indicador <em>&ldquo;✔ Salva&rdquo;</em> no topo). Questões e alternativas com diagramas ou códigos possuem o botão <strong>Ampliar Imagem (Zoom)</strong> dentro da própria tela.
                </li>
            </ol>
        </div>

        <!-- Alerta Crítico sobre a Trava Anticola -->
        <div style='background-color:#fef2f2; border:1px solid #fecaca; border-left:5px solid #dc2626; border-radius:10px; padding:18px; margin:22px 0;'>
            <h3 style='margin:0 0 8px 0; color:#991b1b; font-size:15.5px;'>
                🚨 ATENÇÃO — Oriente a turma sobre a Trava Anticola (Monitoramento de Foco):
            </h3>
            <p style='margin:0 0 10px 0; font-size:13.5px; color:#7f1d1d; line-height:1.55;'>
                Para garantir a integridade da avaliação diagnóstica, a plataforma possui um <strong>bloqueio automático contra troca de abas e janelas</strong>. Avise os alunos antes de clicarem em <em>Iniciar</em>:
            </p>
            <ul style='margin:0; padding-left:20px; font-size:13.5px; color:#7f1d1d; line-height:1.6;'>
                <li style='margin-bottom:6px;'>
                    <strong>Não sair da tela da prova:</strong> Enquanto estiverem respondendo, os alunos <strong>NÃO podem mudar de aba, minimizar o navegador, usar Alt+Tab, abrir calculadora/outros programas ou clicar em notificações</strong> fora da janela da prova.
                </li>
                <li style='margin-bottom:6px;'>
                    <strong>Limite máximo de 3 avisos:</strong> Na 1ª e na 2ª saída de foco, o sistema bloqueia a tela com um alerta vermelho (<em>Aviso 1 de 3</em> e <em>Aviso 2 de 3</em>).
                </li>
                <li style='margin-bottom:6px;'>
                    <strong>Entrega automática na 3ª saída:</strong> Se o aluno sair da aba da prova pela <strong>3ª vez</strong>, o sistema <strong>finaliza e entrega a prova automaticamente na hora</strong> com as questões respondidas até aquele momento.
                </li>
                <li>
                    <strong>Recomendação em laboratório:</strong> Peça para fecharem todas as outras abas e programas antes de abrir a avaliação.
                </li>
            </ul>
        </div>

        <p style='font-size:13.5px; color:#475569; margin-top:18px;'>
            Assim que os alunos concluírem, você poderá acompanhar os resultados, gráficos por capacidade da Matriz SAEP, plano de aula corretivo via IA e o <strong>Modo Projetor</strong> em:
            <br><a href='{$reportUrl}' style='color:#1A428A; font-weight:bold;'>{$reportUrl}</a>
        </p>
    ";

    $subjectPrefix = $isReschedule ? "[Atualização / Reagendamento] " : "[Agendamento] ";
    $subject = $subjectPrefix . "Sprint: {$sprint['name']} ({$sprint['class_name']}) — Agenda e Orientações";

    return (bool)send_system_email($sprint['teacher_email'], $subject, $message);
}

/**
 * Envia e-mail ao professor anterior avisando que a responsabilidade pela Sprint/Diagnóstica
 * foi transferida para outro docente.
 */
function send_teacher_sprint_reassigned_out_email(
    string $oldTeacherName,
    string $oldTeacherEmail,
    string $newTeacherName,
    array $sprint,
    string $changedByName = 'Coordenação / Gestão'
): bool {
    if (trim($oldTeacherEmail) === '') {
        return false;
    }

    $oldName    = htmlspecialchars($oldTeacherName ?: 'Professor(a)');
    $newName    = htmlspecialchars($newTeacherName ?: 'outro(a) docente');
    $authorName = htmlspecialchars($changedByName ?: 'Coordenação');
    $sprintName = htmlspecialchars($sprint['name'] ?? 'Avaliação');
    $className  = htmlspecialchars($sprint['class_name'] ?? '');
    $courseName = htmlspecialchars($sprint['course_name'] ?? '');
    $startFormatted = !empty($sprint['start_time'])
        ? date('d/m/Y \à\s H:i', strtotime($sprint['start_time']))
        : 'Liberação imediata';

    $message = "
        <p style='margin-top:0;'>Olá, Professor(a) <strong>{$oldName}</strong>,</p>
        <p>Informamos que a responsabilidade pela aplicação da avaliação diagnóstica (Sprint) abaixo foi transferida por <strong>{$authorName}</strong> para o(a) Professor(a) <strong>{$newName}</strong>:</p>

        <div style='background-color:#fffbeb; border:1px solid #fde68a; border-left:5px solid #f59e0b; border-radius:10px; padding:16px; margin:18px 0;'>
            <h3 style='margin:0 0 8px 0; color:#92400e; font-size:16px;'>🔄 {$sprintName}</h3>
            <p style='margin:4px 0; font-size:14px;'><strong>Turma:</strong> {$className}" . ($courseName !== '' ? " ({$courseName})" : "") . "</p>
            <p style='margin:4px 0; font-size:14px;'><strong>Data/Horário Programado:</strong> {$startFormatted}</p>
            <p style='margin:4px 0; font-size:14px;'><strong>Novo(a) Professor(a) Responsável:</strong> <span style='color:#166534; font-weight:bold;'>{$newName}</span></p>
        </div>

        <p style='font-size:13.5px; color:#475569;'>
            📅 <strong>Lembrete de Agenda:</strong> Caso você já tivesse salvo este evento no seu Google Agenda pessoal, você já pode removê-lo ou desconsiderá-lo. O(a) novo(a) docente responsável já recebeu as instruções e o link de agendamento.
        </p>
    ";

    $subject = "[Transferência de Docente] Sprint: " . ($sprint['name'] ?? '') . " (" . ($sprint['class_name'] ?? '') . ")";
    return (bool)send_system_email($oldTeacherEmail, $subject, $message);
}

/**
 * Envia e-mail ao professor responsável avisando que a Sprint/Diagnóstica foi cancelada/excluída.
 */
function send_teacher_sprint_cancelled_email(array $sprint, string $cancelledByName = 'Coordenação / Gestão'): bool {
    if (empty($sprint['teacher_email'])) {
        return false;
    }

    $teacherName = htmlspecialchars($sprint['teacher_name'] ?: 'Professor(a)');
    $authorName  = htmlspecialchars($cancelledByName ?: 'Coordenação');
    $sprintName  = htmlspecialchars($sprint['name'] ?? 'Avaliação');
    $className   = htmlspecialchars($sprint['class_name'] ?? '');
    $courseName  = htmlspecialchars($sprint['course_name'] ?? '');
    $unitName    = htmlspecialchars($sprint['unit_name'] ?: 'Unidade SENAI');
    $startFormatted = !empty($sprint['start_time'])
        ? date('d/m/Y \à\s H:i', strtotime($sprint['start_time']))
        : (!empty($sprint['created_at']) ? date('d/m/Y \à\s H:i', strtotime($sprint['created_at'])) : 'Data não definida');

    $message = "
        <p style='margin-top:0;'>Olá, Professor(a) <strong>{$teacherName}</strong>,</p>
        <p>Informamos que a avaliação diagnóstica (Sprint) abaixo foi <strong>cancelada e excluída</strong> do cronograma por <strong>{$authorName}</strong> em " . date('d/m/Y \à\s H:i') . ":</p>

        <div style='background-color:#fef2f2; border:1px solid #fecaca; border-left:5px solid #dc2626; border-radius:10px; padding:16px; margin:18px 0;'>
            <h3 style='margin:0 0 8px 0; color:#991b1b; font-size:16px;'>🗑️ Avaliação Cancelada: {$sprintName}</h3>
            <p style='margin:4px 0; font-size:14px; color:#7f1d1d;'><strong>Turma:</strong> {$className}" . ($courseName !== '' ? " ({$courseName})" : "") . "</p>
            <p style='margin:4px 0; font-size:14px; color:#7f1d1d;'><strong>Unidade:</strong> {$unitName}</p>
            <p style='margin:4px 0; font-size:14px; color:#7f1d1d;'><strong>Horário que estava agendado:</strong> {$startFormatted}</p>
        </div>

        <p style='font-size:13.5px; color:#475569;'>
            📅 <strong>Lembrete de Agenda:</strong> Caso você tenha adicionado esta aplicação ao seu <strong>Google Agenda</strong> pessoal, lembre-se de remover o evento da sua agenda.
        </p>
    ";

    $subject = "[Cancelada] Sprint: " . ($sprint['name'] ?? '') . " (" . ($sprint['class_name'] ?? '') . ") — Remova da sua Agenda";
    return (bool)send_system_email($sprint['teacher_email'], $subject, $message);
}
?>
