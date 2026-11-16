# Sistema iaS - Evolução em cada desafio

## 🎯 O que é o iaS?

O **iaS** é uma plataforma corporativa e educacional de Alta Performance desenvolvida em PHP e MySQL (Vanilla). Ele foi projetado para revolucionar o processo de avaliação, diagnóstico e tomada de decisão em instituições de ensino, com forte alinhamento às diretrizes educacionais e aos padrões de excelência.

Mais do que um aplicador de simulados, o iaS atua como um **Ecossistema Multi-Tenant de Inteligência Pedagógica e Engajamento**. A plataforma cobre com fluidez e modernidade todo o ciclo de avaliação: do armazenamento inteligente de questões à aplicação rigorosa com métricas anti-cola, passando pelo disparo de devolutivas em PDF por e-mail e terminando em um sistema inovador de Gamificação.

Sua interface foca intensamente na usabilidade e estética, utilizando **TailwindCSS**, **Phosphor Icons** e elementos de **Glassmorphism**, garantindo uma experiência "Premium", moderna e 100% responsiva para professores, alunos e gestores.

---

## 🚀 Para que serve o iaS? Principais Atribuições:

1. **Gestão Estruturada do Conhecimento (Matriz SAEP Inviolável, Importação com IA & Mídia de Apoio)**
   Armazena questões e separa o acervo por Curso, Módulo (ou `"Sem UC vinculada"` quando opcional), Capacidade Oficial e Dificuldade. A integração de **Tags/Lotes (ex: SAEP2026)** permite catalogar milhares de questões de provas específicas para seleção com 1 clique.
   - **Leitura Nativa da Matriz Curricular SAEP (`.xls` / `.xlsx`):** Basta anexar a planilha oficial da Matriz SAEP do curso (`course_matrix_ajax.php`). O sistema lê automaticamente a Habilitação Profissional (célula `A1`), a Competência Geral (`A2`) e extrai os textos oficiais e **invioláveis** das Capacidades (`C1, C2, C3, C4...`) diretamente das células `C7, D7, E7, F7...`, vinculando-as de forma blindada ao Curso (`course_capacities`) e às suas Unidades Curriculares (`module_capacities`).
   - **Importação Inteligente de Provas em Word (`.docx`) e PDF (`.pdf`) com IA:** Basta anexar uma prova ou lista de exercícios em `.docx` ou `.pdf`, escolher o Curso (vinculado à sua Matriz SAEP), Módulo opcional, Tag e Gabarito Rápido opcional. A IA separa automaticamente **Contexto (texto-base)** e **Comando (pergunta)**, extrai as alternativas, resolve o gabarito caso não esteja explícito, detecta quais questões citam figuras/circuitos (`📷 Pede imagem`) e **classifica automaticamente cada questão na Capacidade Oficial (`C1..Cn`) da Matriz SAEP que melhor se encaixa**.
   - **Tela de Revisão Travada na Matriz:** Antes de salvar no banco, o docente revisa todas as questões em cards interativos onde o seletor de Capacidade é um `<select>` travado exclusivamente nas capacidades oficiais da Matriz do Curso, exibindo o texto oficial inviolável logo abaixo.
   - **Imagens no Enunciado E nas Alternativas (`A, B, C, D, E`):** Suporte completo a diagramas, circuitos elétricos, blocos de código, esquemas técnicos, tabelas e gráficos tanto no enunciado da questão (`questions.image_url`) quanto individualmente em cada alternativa (`question_options.image_url` — permitindo alternativas só com texto, só com imagem ou texto + imagem).
   - **Edição Completa das Questões Já Importadas (`questions_view.php`):** Modal dedicado de edição completa (ícone de lápis) permitindo vincular/alterar a Capacidade Oficial da Matriz (`C1..Cn`), editar Contexto, Comando, Unidade Curricular, Dificuldade, Tag e textos/imagens das Alternativas, preservando os IDs no banco para nunca afetar históricos de Sprints anteriores.
   - **Preservação de Dados:** A exclusão de questões é lógica (*Soft Delete*), garantindo que históricos e notas de alunos do passado não sejam perdidos.

2. **Aplicação Segura, Inteligente e Agendada (Sprints & Agenda)**
   A criação de provas, apelidadas de *Sprints*, permite customização extrema:
   - **Agendamento Inteligente & Fuso de Brasília (`America/Sao_Paulo`):** Programe avaliações com antecedência (ex: 10 dias antes). O sistema opera com precisão atômica no fuso de Brasília, exibindo a avaliação como *"Agendada"* no painel do aluno e bloqueando seu início até a data e hora exatas de liberação (`start_time`).
   - **Ciclo de Vida Dinâmico de Liberação:** No exato minuto de início, a prova se auto-ativa dinamicamente para `active`, liberando o botão *"Iniciar Prova"*.
   - **Prazo Limite Protegido (`end_time`):** Impede novos acessos após o encerramento do prazo, enquanto protege alunos que já iniciaram a prova para que possam concluí-la normalmente dentro do seu tempo limite.
   - **Agenda Interativa (Google Calendar Style):** Visão em calendário mensal e lista cronológica para planejamento e governança de aplicações.
   - **Seleção em Massa de Questões Filtradas:** Na montagem da Sprint (`sprint_select_questions.php`), filtre por Lote/Tag, Módulo, Capacidade ou Dificuldade e utilize o botão **"Selecionar Todas as Filtradas (X)"** para adicionar todas as questões desejadas com 1 clique.
   - **Definição de Tempo Limite** (contagem regressiva máxima com auto-submissão) e **Tempo Mínimo** de entrega (trava de segurança para obrigar o estudante a dedicar tempo de leitura, impedindo "chutes rápidos").
   - **Sistema Anti-Cola:** O cronômetro persiste em tempo real e monitora perda de foco (saídas de abas). Após 3 avisos, a prova é encerrada automaticamente.

3. **Diagnóstico Cirúrgico para Docentes e Feedback Ativo**
   Transforma a nota bruta em inteligência. O *Raio-X* da turma mapeia com precisão em quais Módulos e Capacidades a turma tem defasagem. 
   - Ao finalizar uma sprint, o aluno recebe **instantaneamente por E-mail** (função nativa PHP) sua Devolutiva Individual em formato PDF (tamanho A4 estruturado).
   - Professores também podem exportar esses PDFs individualmente pelo Dashboard.

4. **Governança Macro para Gestores (Multi-Tenant)**
   Visão analítica completa através de um Dashboard Administrativo, monitorando evolução de Regionais, Unidades Escolares e Cursos. Permite criação de planos de aula baseados em IA.

5. **Cadastro e Automação Institucional (Vínculo Flexível de Turmas)**
   - **Vínculo Duplo de Turmas (CSV ou Edição Direta):** Atribuição de turma simplificada e sem gargalos:
     - **Importação Massiva via CSV (2 ou 3 colunas):** O modelo aceita tanto `Nome,Email` quanto `Nome,Email,Turma`. Ao subir a planilha, o sistema resolve a turma automaticamente pelo nome ou ID.
     - **Dropdown de Turma no Modal de Importação:** Possibilidade de selecionar uma turma padrão no modal que será aplicada a todos os alunos da planilha que não possuírem turma preenchida.
     - **Campo Suspenso Direto na Edição do Aluno:** No painel de Gestão de Usuários (`admin_users.php`), o administrador pode abrir o modal de edição e vincular ou transferir o aluno de turma através de um campo suspenso (`select`).
     - **Badges Visuais e Alertas de Status:** A listagem exibe chips indicando a turma de cada aluno ou o alerta visual de *"Sem Turma"*, facilitando a governança.
   - **Onboarding e E-mail Automático:** O sistema gera senhas provisórias seguras e dispara automaticamente um **e-mail de boas-vindas com as credenciais iniciais**. No primeiro login, o sistema exige obrigatoriamente a troca de senha por segurança.

6. **Arena iaS e Gamificação**
   O coração do engajamento do aluno. Possui:
   - **Sistema de Níveis (XP):** Resoluções de Sprints geram Experiência que eleva o ranqueamento.
   - **Avatares Personalizados:** Upload seguro de imagens (.JPG, .PNG, .GIF animados).
   - **Arena de Duelos (PvP):** Alunos desafiam uns aos outros na turma para "Batalhas de Conhecimento" geradas dinamicamente com questões que ainda não responderam.
   - **Ranking Global e Pódio Holográfico:** Exibição competitiva saudável.

---

## 🌟 Lista Completa de Funcionalidades

### 🏛️ Administração e Diretoria (Multi-Tenant & Privilégios Master)
- **Acesso Master p/ Todos os Administradores:** Todos os usuários com perfil `admin` possuem visibilidade e governança global irrestrita sobre todas as Regionais, Unidades, Cursos, Turmas e Sprints.
- **Mapa Interativo do Sistema (`admin_system_map.php`):** Guia executivo e visual embutido no painel administrativo explicando o fluxo completo de 7 etapas (com contadores em tempo real do banco), relacionamentos entre entidades, matriz de permissões dos 3 perfis e 5 *Playbooks* práticos de alta produtividade.
- **Agenda Global de Aplicações:** Visão executiva em calendário (Google Agenda Style) de todas as provas do estado/regional, com filtros cruzados por Regional, Unidade Escolar, Curso, Turma, Módulo, Docente e Status.
- **Painel Macro de Desempenho:** Filtre os dados e o histórico de provas por Estado, Regional, Escola e Curso.
- **Importação Automatizada SAEP:** Com 1 único arquivo CSV do Governo, o sistema constrói toda a hierarquia, cadastra todos os alunos, professores, turmas e históricos de Sprints automaticamente.
- **Onboarding Automático:** Disparo de credenciais temporárias por E-mail para alunos importados.
- **Gestão de Usuários & Vínculo de Turmas:** Adição, edição e exclusão de usuários; atribuição rápida de turmas via campo suspenso (dropdown) no modal de edição, importação em massa flexível via CSV (com ou sem coluna de turma) e badges visuais na tabela indicando o status da turma do aluno.

### 👨‍🏫 Visão Docente (A Sala de Aula)
- **Agenda de Sprints (Google Calendar):** Calendário mensal interativo com chips coloridos por status (*Agendada*, *Ativa*, *Concluída*), cards de apoio e modal de reagendamento em 1 clique via AJAX.
- **Agendamento Prévio de Provas:** Programe a data e hora exata de liberação (`start_time`) e encerramento opcional (`end_time`).
- **Matriz Curricular SAEP (`.xls`) & Banco de Questões com IA:** Vínculo obrigatório e inviolável com as capacidades oficiais (`C1..Cn`) da Matriz SAEP do curso; importação em massa via Word (`.docx`), PDF (`.pdf`) com IA ou Planilha CSV; suporte a imagens de apoio tanto no enunciado quanto nas alternativas (`A, B, C, D, E`); e edição completa da estrutura de questões já importadas.
- **Criação Rápida de Sprints com Seleção em Massa:** Associa turmas, filtra questões por Lote/Tag, Módulo, Capacidade ou Dificuldade e permite selecionar todas as questões filtradas com 1 clique, contando com checagem anti-repetição e pré-visualização completa.
- **Controles Anti-Falta de Foco:** Limite Máximo de tempo de prova e **Tempo Mínimo Obrigatório** (para inibir chuta-chuta).
- **Raio-X Analítico em Tempo Real:** Descubra exatamente *qual capacidade* o aluno errou (sem focar na "nota vazia").
- **Tutor IA (Apoio ao Docente):** Um chat integrado com a inteligência do banco que gera automaticamente "Planos de Aula Corretivos" baseados nos piores desempenhos da sala.
- **Exportação Coletiva & Word:** Gere as Devolutivas em Slides PDF e exporte a avaliação para Microsoft Word (.doc) com as imagens de apoio 100% incorporadas (Base64).
- **Gestão de Lixeira (Soft Delete):** Remova alunos de turmas antigas e envie-os para uma aba "Lixeira" antes de exclusão permanente.

### 🎓 Visão do Aluno (Gamificação e Experiência)
- **Visualização Técnica Cristalina com Zoom:** Questões com circuitos, diagramas e esquemas contam com exibição destacada e Lightbox interativo (zoom em alta resolução). Questões sem imagens mantêm layout limpo sem nenhum impacto.
- **Proteção Anti-Vazamento (Agendadas):** O aluno visualiza as provas futuras no seu dashboard com data/hora de liberação (`Liberada em DD/MM/AAAA às HH:MM`), mas com acesso e botão de início bloqueados até o momento marcado.
- **Liberação Automática em Tempo Real:** No exato minuto de início, a avaliação é liberada dinamicamente, transformando o botão em *"Iniciar Prova"*.
- **Proteção de Tentativas em Andamento:** Se o horário limite (`end_time`) expirar enquanto o aluno estiver respondendo à prova, sua sessão permanece ativa até a conclusão do seu tempo limite individual (`time_limit_minutes`), garantindo a entrega sem interrupções.
- **Motor de Prova Anti-Cola:** O sistema trava a avaliação e submete sozinha se o aluno tentar sair da aba do navegador 3 vezes (Tracking de Foco).
- **Devolutiva Humanizada:** O aluno recebe no e-mail o seu boletim completo em PDF.
- **Sistema de Leveling (XP):** Cada resposta correta gera pontos de experiência, promovendo o aluno a Patentes superiores.
- **Arena P2P (Duelos):** Alunos desafiam amigos da mesma turma para uma batalha de Sprints geradas automaticamente pela IA.
- **Arena de Treinamento Solo:** Treinamento infinito sugerido pelo sistema cobrindo deficiências.
- **Personalização de Perfil:** Upload de Imagens e suporte a GIFs animados no avatar.
- **Dashboard Pessoal:** Gráficos históricos que exibem evolução da nota e tempo gasto em provas ao longo dos meses.

### 🛡️ Segurança e UX Global
- **Self-Onboarding (Help Modals):** Embutido em todas as telas há botões de `[?]` que explicam a funcionalidade na hora.
- **Sessões Isoladas e Criptografia:** Senhas *Hashed* usando bcrypt nativo e travas de nível de acesso.
- **Interface Premium:** Uso sofisticado do Tailwind CSS entregando UI Glassmorphism com altíssima responsividade.

---

## 🛠 Arquitetura Técnica

- **Backend:** PHP 8+ (Estruturado, PDO, focado em altíssima performance, disparos nativos via `mail()`).
- **Banco de Dados:** MySQL/MariaDB.
- **Fuso Horário & Sincronismo:** Padronização integral em `America/Sao_Paulo` (Horário Oficial de Brasília / UTC-3) no PHP e na sessão MySQL (`SET time_zone = '-03:00'`), blindando agendamentos e cronômetros contra discrepâncias de servidores em UTC.
- **Frontend:** HTML5, Vanilla JavaScript.
- **Estilização e UI:** TailwindCSS (CDN), Phosphor Icons.
- **Visualização de Dados:** Chart.js.
- **Componentes Interativos:** TomSelect.
- **Infraestrutura/Deploy:** Automação Contínua (CI/CD) em cPanel via git `.cpanel.yml`.

### Padrão de Arquitetura de Pastas

```
/
├── admin_*.php                   # Módulos Administrativos (Dashboard, Mapa do Sistema, SAEP, Usuários)
├── agenda.php / agenda_*.php     # Módulo de Cronograma e Agenda (Google Calendar Style)
├── class_*.php / classes.php     # Módulos do Professor para Gestão de Turmas
├── course_matrix_ajax.php        # Leitor nativo da Matriz SAEP (.xls) e editor completo de questões
├── dashboard.php                 # Dashboard Global (Visão Professor)
├── import_*.php                  # Rotinas de importação de CSV (Usuários e Histórico SAEP)
├── includes/                     # Componentes reutilizáveis (Header, Sidebar, Mailer, Modais)
├── migrate_*.php                 # Scripts dinâmicos de evolução do esquema de banco de dados
├── questions*.php                # Repositório Central de Questões, Importação IA (Word/PDF/CSV) e Revisão
├── question_image_ajax.php       # Endpoint AJAX para imagens de apoio no enunciado e nas alternativas
├── reset_password.php            # Fluxo obrigatório de troca de senha inicial
├── sprint_*.php                  # Motor Core das Provas (Create, Solve, Report, Select, PDF, Word)
├── student_*.php                 # O Universo do Aluno (Dashboard, Arena, Duelos, Ranking)
├── template_*.csv                # Templates oficiais padronizados para download e preenchimento
├── uploads/questions/            # Armazenamento isolado e seguro das imagens de questões e alternativas
├── PróximosPassos.md             # Blueprint de Engenharia para IA Avançada (RAG & Copiloto MSEP)
├── Resumo_Mostra_Cientifica_FIESC.md # Resumo científico estruturado para submissão na Mostra FIESC
└── README.md                     # Documentação Completa do Projeto
```

---

## 🚀 Como Executar Localmente

1. Suba um servidor web com suporte a PHP (Apache/Nginx via XAMPP, Laragon, Docker).
2. Ajuste o arquivo `config.php` com as credenciais do banco MySQL.
3. Para configurar o ambiente e habilitar 100% dos recursos mais recentes, execute os scripts de banco de dados na ordem:
   - Configurações antigas do BD (Se necessário)
   - `migrate_admin.php` (Gera o Admin Master)
   - `migrate_profile_pic.php` (Avatares)
   - `migrate_capacities.php` 
   - `migrate_questions_tag.php` (Filtros Avançados)
   - `migrate_questions_active.php` (Soft Delete)
   - `migrate_sprint_min_time.php` (Trava de Leitura)
   - `migrate_sprint_schedule.php` (Agenda & Sprints Agendadas)
   - `migrate_questions_image.php` (Suporte a Imagens de Apoio nas Questões)
4. Acesse o sistema. Para o fluxo de envio de e-mails funcionar localmente, ative uma configuração SMTP ou ambiente de emulação na sua infraestrutura PHP.

---

## 📦 Modelo de Dados Principal

- **users:** Administradores Master, Professores e Alunos (`role`, `unit_id`, `profile_pic`, `must_change_password`).
- **courses / modules:** Estrutura curricular do curso e suas Unidades Curriculares (incluindo suporte a `"Sem UC vinculada"`).
- **course_capacities / module_capacities:** Matriz Oficial de Capacidades SAEP do curso (`C1..Cn` com textos oficiais invioláveis extraídos das células `C7, D7, E7...` da planilha `.xls`).
- **questions / question_options:** Acervo completo de questões (`context`, `command`, `capacity`, `difficulty`, `image_url`, `import_tag`, `is_active`) e alternativas com suporte a texto e/ou imagem individual (`question_options.image_url`).
- **classes / class_students:** Agrupamento de turmas e matrículas de alunos.
- **sprints / sprint_questions:** Entidade de Avaliação com suporte a agendamento (`time_limit_minutes`, `time_min_minutes`, `start_time`, `end_time`, `status IN ('draft','active','completed','scheduled')`).
- **student_answers / sprint_attempts:** Centro de dados analíticos, guardando cada resposta e tempo de realização do estudante para os relatórios e gráficos.
- **system_settings:** Persistência segura de configurações globais no banco (como `openai_api_key`), imune a sobrescritas de deploy via Git/cPanel.

---

## 🎯 Por que o iaS é Inovador?

O **iaS** resolve o eterno problema do ensino digital - a falta de foco do aluno - através do choque de Gamificação. Além de entregar um sistema esteticamente belíssimo que encanta usuários, ele é rígido com métricas e antifraude, blindando os relatórios de "chutes" irreais através do motor de tempo e travas de saída de abas. O uso de E-mails automatizados para *Onboarding* e envio instantâneo do Feedback humanizam o software, conectando instituição, aluno e resultados em tempo real.

---

## 👨‍💻 Autoria e Créditos de Desenvolvimento

Este sistema foi idealizado, projetado e desenvolvido por:

- **Alceu Matteucci Junior** — *Desenvolvedor Líder & Arquitetura de Software*
- **Equipe Pedagógica do SENAI Luzerna** — *Concepção Metodológica, Diretrizes Educacionais e Validação Pedagógica*

© Todos os direitos reservados.

**iaS - Evolução em cada desafio.**
