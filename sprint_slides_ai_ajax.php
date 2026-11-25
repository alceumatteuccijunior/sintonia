<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'teacher'])) {
    echo json_encode(['error' => 'Acesso negado.']);
    exit;
}

require_once 'config.php';

if (!defined('OPENAI_API_KEY') || trim(OPENAI_API_KEY) === '' || trim(OPENAI_API_KEY) === 'sua_chave_aqui') {
    echo json_encode(['error' => 'Chave da API da OpenAI não configurada.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['sprint_name'])) {
    echo json_encode(['error' => 'Dados inválidos.']);
    exit;
}

$sprintName     = trim((string)($data['sprint_name'] ?? 'Avaliação Diagnóstica'));
$className      = trim((string)($data['class_name'] ?? 'Turma'));
$courseName     = trim((string)($data['course_name'] ?? ''));
$totalStudents  = (int)($data['total_students'] ?? 0);
$totalQuestions = max(1, (int)($data['total_questions'] ?? 1));
$avgScore       = (float)($data['avg_score'] ?? 0);
$avgPercent     = isset($data['avg_percent']) ? (float)$data['avg_percent'] : round(($avgScore / $totalQuestions) * 100, 1);

$healthHigh = (int)($data['health_high'] ?? 0);
$healthMed  = (int)($data['health_med'] ?? 0);
$healthLow  = (int)($data['health_low'] ?? 0);

$formatCapList = function (array $list): string {
    if (empty($list)) {
        return "Nenhuma capacidade nesta faixa.\n";
    }
    $out = "";
    foreach ($list as $c) {
        $code = trim((string)($c['capacity_code'] ?? 'Geral'));
        $desc = trim((string)($c['capacity_desc'] ?? ''));
        $rate = round((float)($c['rate'] ?? 0), 1);
        $label = $desc !== '' ? "{$code} ({$desc})" : $code;
        $out .= "- {$label}: {$rate}% de acerto\n";
    }
    return $out;
};

// Se vier a estrutura nova por faixa real (sem sobreposição), usa; caso contrário, classifica a partir do payload legado
$capsConsolidated = $data['caps_consolidated'] ?? null;
$capsAttention    = $data['caps_attention'] ?? null;
$capsCritical     = $data['caps_critical'] ?? null;

if (!is_array($capsConsolidated) || !is_array($capsAttention) || !is_array($capsCritical)) {
    $capsConsolidated = [];
    $capsAttention    = [];
    $capsCritical     = [];
    $seenCodes        = [];
    $mergedLegacy     = array_merge($data['best_capacities'] ?? [], $data['worst_capacities'] ?? []);
    foreach ($mergedLegacy as $c) {
        $key = ($c['capacity_code'] ?? '') . '|' . ($c['capacity_desc'] ?? '');
        if (isset($seenCodes[$key])) continue;
        $seenCodes[$key] = true;
        $r = (float)($c['rate'] ?? 0);
        if ($r >= 70) $capsConsolidated[] = $c;
        elseif ($r >= 50) $capsAttention[] = $c;
        else $capsCritical[] = $c;
    }
}

$courseLine = $courseName !== '' ? "Curso: {$courseName} | " : "";

$prompt  = "Analise os indicadores reais da Avaliação Diagnóstica abaixo segundo a Metodologia SENAI de Educação Profissional (MSEP):\n\n";
$prompt .= "1. PANORAMA DA TURMA:\n";
$prompt .= "- {$courseLine}Turma: {$className} | Avaliação: {$sprintName}\n";
$prompt .= "- Participação: {$totalStudents} aluno(s) avaliados\n";
$prompt .= "- Média Geral da Turma: {$avgPercent}% de aproveitamento ({$avgScore} de {$totalQuestions} questões)\n";
$prompt .= "- Distribuição dos Alunos por Faixa:\n";
$prompt .= "  * Alto Desempenho (>= 70%): {$healthHigh} aluno(s)\n";
$prompt .= "  * Em Atenção / Desenvolvimento (50% a 69%): {$healthMed} aluno(s)\n";
$prompt .= "  * Em Risco Crítico (< 50%): {$healthLow} aluno(s)\n\n";

$prompt .= "2. DESEMPENHO POR CAPACIDADE OFICIAL DA MATRIZ (Sem sobreposição):\n";
$prompt .= "A) Capacidades Consolidadas (>= 70% de acerto):\n" . $formatCapList($capsConsolidated) . "\n";
$prompt .= "B) Capacidades em Atenção / Em Desenvolvimento (50% a 69,9% de acerto):\n" . $formatCapList($capsAttention) . "\n";
$prompt .= "C) Capacidades Críticas / Com Defasagem (< 50% de acerto):\n" . $formatCapList($capsCritical) . "\n";

$criticalQuestions = $data['critical_questions'] ?? [];
if (!empty($criticalQuestions) && is_array($criticalQuestions)) {
    $prompt .= "3. QUESTÕES COM MAIOR ÍNDICE DE ERRO (< 60%) E DISTRATORES MAIS MARCADOS:\n";
    foreach ($criticalQuestions as $cq) {
        $qCap  = $cq['capacity_code'] ?? 'Geral';
        $qRate = $cq['rate'] ?? 0;
        $qCmd  = $cq['command_short'] ?? '';
        $qDist = $cq['top_distractor'] ?? null;
        $qCnt  = (int)($cq['distractor_count'] ?? 0);
        $prompt .= "- [{$qCap} | {$qRate}% de acerto] Enunciado: \"{$qCmd}\"";
        if ($qDist) {
            $prompt .= " -> Erro mais comum ({$qCnt} alunos marcaram): \"{$qDist}\"";
        }
        $prompt .= "\n";
    }
    $prompt .= "\n";
}

$prompt .= "DIRETRIZES OBRIGATÓRIAS DE CALIBRAGEM E TOM:\n";
$prompt .= "- Seja 100% fiel e proporcional aos números acima. NÃO invente problemas ou críticas quando os indicadores forem bons!\n";
$prompt .= "- Se a maioria dos alunos estiver fora da faixa de risco/atenção e a média geral for boa (>= 70%), reconheça claramente que a turma está bem e com base sólida, tratando eventuais pontos intermediários apenas como oportunidades de aprofundamento ou nivelamento pontual.\n";
$prompt .= "- Somente aponte alerta crítico ou necessidade de recomposição intensiva quando de fato existirem capacidades abaixo de 50% (ou em atenção) ou concentração relevante de alunos em risco.\n";
$prompt .= "- Cite os códigos das capacidades (ex.: C1, C2...) ao comentar os pontos fortes e os pontos que merecem atenção.\n\n";

$prompt .= "FORMATO DA RESPOSTA (máximo de 3 parágrafos curtos e densos para caber perfeitamente em 1 slide 16:9, iniciando cada parágrafo com o título em negrito):\n";
$prompt .= "**1. Diagnóstico Geral da Turma:** (Leitura realista do nível de proficiência da turma com base na média de {$avgPercent}% e na distribuição entre alunos em alto desempenho, atenção e risco).\n";
$prompt .= "**2. Análise das Capacidades Avaliadas:** (Destaque das capacidades consolidadas pela turma e, somente se houver capacidades em atenção ou críticas, análise objetiva de onde ocorreram as dúvidas conceituais).\n";
$prompt .= "**3. Direcionamento Pedagógico (MSEP):** (Se a turma foi bem, sugira estratégias de aprofundamento prático/desafios técnicos e apoio pontual; se houve defasagens reais, sugira 2 ações práticas de intervenção em sala/laboratório focadas nas capacidades que ficaram abaixo do esperado).";

$url = 'https://api.openai.com/v1/chat/completions';
$postData = [
    'model' => 'gpt-4o-mini',
    'messages' => [
        [
            'role' => 'system',
            'content' => 'Você é um Consultor Pedagógico Especialista na Metodologia SENAI de Educação Profissional (MSEP). Responda apenas com os 3 parágrafos do parecer executivo, de forma coesa, equilibrada e fiel aos dados, sem introduções ou saudações.'
        ],
        [
            'role' => 'user',
            'content' => $prompt
        ]
    ],
    'temperature' => 0.5,
    'max_tokens' => 600
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . OPENAI_API_KEY
]);

$response = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    echo json_encode(['error' => 'Erro na conexão com OpenAI: ' . $err]);
    exit;
}

$decoded = json_decode($response, true);
if (isset($decoded['error'])) {
    echo json_encode(['error' => 'Erro da API OpenAI: ' . $decoded['error']['message']]);
    exit;
}

$insight = $decoded['choices'][0]['message']['content'] ?? 'Nenhum insight gerado.';
echo json_encode(['insight' => $insight]);
