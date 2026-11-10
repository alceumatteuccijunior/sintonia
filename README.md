# Sistema iaS - Evolução em cada desafio

## 🎯 O que é o iaS?

O **iaS** é uma plataforma corporativa e educacional de Alta Performance desenvolvida em PHP e MySQL (Vanilla). Ele foi projetado para revolucionar o processo de avaliação, diagnóstico e tomada de decisão em instituições de ensino, com forte alinhamento às diretrizes educacionais e aos padrões de excelência.

Mais do que um aplicador de simulados, o iaS atua como um **Ecossistema Multi-Tenant de Inteligência Pedagógica e Engajamento**. A plataforma cobre com fluidez e modernidade todo o ciclo de avaliação: do armazenamento inteligente de questões à aplicação rigorosa com métricas anti-cola, passando pelo disparo de devolutivas em PDF por e-mail e terminando em um sistema inovador de Gamificação.

Sua interface foca intensamente na usabilidade e estética, utilizando **TailwindCSS**, **Phosphor Icons** e elementos de **Glassmorphism**, garantindo uma experiência "Premium", moderna e 100% responsiva para professores, alunos e gestores.

---

## 🚀 Para que serve o iaS? Principais Atribuições:

1. **Gestão Estruturada do Conhecimento (Banco de Questões, Importação com IA & Mídia de Apoio)**
   Armazena questões e separa o acervo por Curso, Módulo, Capacidade e Dificuldade. A integração de **Tags/Lotes (ex: SAEP2026)** permite catalogar milhares de questões de provas específicas para seleção com 1 clique.
   - **Importação Inteligente de Provas em Word (`.docx`) e PDF (`.pdf`) com IA:** Basta anexar uma prova ou lista de exercícios em `.docx` ou `.pdf`, escolher o Curso, Módulo Padrão, Tag e Gabarito Rápido opcional. A IA estrutura automaticamente todos os enunciados e alternativas, resolve o gabarito caso não esteja explícito, detecta quais questões citam figuras/circuitos (`📷 Pede imagem`) e abre uma tela de conferência interativa antes de salvar.
   - **Imagens de Apoio Técnicas:** Suporte completo a diagramas, circuitos elétricos, esquemas técnicos, tabelas e gráficos (`image_url`). Totalmente seguro para produção: questões puramente textuais permanecem 100% limpas sem caixas vazias ou tags quebradas.
   - **Fluxo Ágil Pós-Importação em Massa (Word, PDF ou CSV):** Ao finalizar a importação, o sistema posiciona no topo todas as questões que citam figuras no enunciado e permite carregar as imagens diretamente do computador em segundos.
   - **Gestão Individual e Contínua:** No Banco de Questões (`questions_view.php`), cada questão exibe badge indicativo e permite adicionar, editar ou remover a imagem a qualquer momento.
   - **Preservação de Dados:** A exclusão de questões é lógica (*Soft Delete*), garantindo que históricos e notas de alunos do passado não sejam perdidos.

2. **Aplicação Segura, Inteligente e Agendada (Sprints & Agenda)**
   A criação de provas, apelidadas de *Sprints*, permite customização extrema:
   - **Agendamento Inteligente & Fuso de Brasília (`America/Sao_Paulo`):** Programe avaliações com antecedência (ex: 10 dias antes). O sistema opera com precisão atômica no fuso de Brasília, bloqueando o acesso dos alunos até a data e hora exatas de liberação (`start_time`).
   - **Ciclo de Vida Dinâmico de Liberação:** No exato minuto de início, a prova se auto-ativa dinamicamente para `active`, liberando o botão *"Iniciar Prova"*.
   - **Prazo Limite Protegido (`end_time`):** Impede novos acessos após o encerramento do prazo, enquanto protege alunos que já iniciaram a prova para que possam concluí-la normalmente dentro do seu tempo limite.
   - **Agenda Interativa (Google Calendar Style):** Nova visão em calendário mensal e lista cronológica para planejamento e governança de aplicações.
   - **Definição de Tempo Limite** (contagem regressiva máxima com auto-submissão).
   - **Definição de Tempo Mínimo** de entrega (trava de segurança para obrigar o estudante a dedicar tempo de leitura, impedindo "chutes rápidos").
   - Motor de seleção inteligente de questões via filtros combinados com alerta de questões já aplicadas à turma.
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

### 🏛️ Administração e Diretoria (Multi-Tenant)
- **Agenda Global de Aplicações:** Visão executiva em calendário (Google Agenda Style) de todas as provas do estado/regional, com filtros cruzados por Regional, Unidade Escolar, Curso, Turma, Módulo, Docente e Status.
- **Painel Macro de Desempenho:** Filtre os dados e o histórico de provas por Estado, Regional, Escola e Curso.
- **Importação Automatizada SAEP:** Com 1 único arquivo CSV do Governo, o sistema constrói toda a hierarquia, cadastra todos os alunos, professores, turmas e históricos de Sprints automaticamente.
- **Onboarding Automático:** Disparo de credenciais temporárias por E-mail para alunos importados.
- **Gestão de Usuários & Vínculo de Turmas:** Adição, edição e exclusão de usuários; atribuição rápida de turmas via campo suspenso (dropdown) no modal de edição, importação em massa flexível via CSV (com ou sem coluna de turma) e badges visuais na tabela indicando o status da turma do aluno.

### 👨‍🏫 Visão Docente (A Sala de Aula)
- **Agenda de Sprints (Google Calendar):** Calendário mensal interativo com chips coloridos por status (*Agendada*, *Ativa*, *Concluída*), cards de apoio e modal de reagendamento em 1 clique via AJAX.
- **Agendamento Prévio de Provas:** Programe a data e hora exata de liberação (`start_time`) e encerramento opcional (`end_time`).
- **Banco de Questões com Imagens de Apoio:** Inserção via painel ou CSV. Suporte a upload de esquemas, circuitos elétricos, tabelas e gráficos da máquina local, com gerenciamento direto na listagem ou pós-importação em massa.
- **Criação Rápida de Sprints:** Associa turmas, escolhe quantidade de questões por Lote/Tag ou Dificuldade com checagem anti-repetição e pré-visualização de imagem.
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
├── admin_*.php                # Módulos Administrativos (Dashboard, Exportações SAEP, IA)
├── agenda.php / agenda_*.php  # Módulo de Cronograma e Agenda (Google Calendar Style)
├── class_*.php / classes.php  # Módulos do Professor para Gestão de Turmas
├── dashboard.php              # Dashboard Global (Visão Professor)
├── import_*.php               # Rotinas de importação de CSV (Usuários e Questões)
├── includes/                  # Componentes reutilizáveis (Header, Sidebar, Mailer, Modais)
├── migrate_*.php              # Scripts dinâmicos de Update do BD (Ex: migrate_questions_image.php)
├── questions*.php             # Repositório Central de Questões, Cadastro Manual/CSV e Gestão de Imagens
├── question_image_ajax.php    # Endpoint seguro AJAX para upload/remoção de imagens de questões
├── reset_password.php         # Fluxo obrigatório de troca de senha inicial
├── sprint_*.php               # Motor Core das Provas (Create, Solve, Report, Select, PDF, Word)
├── student_*.php              # O Universo do Aluno (Dashboard, Arena, Duelos, Ranking)
├── template_*.csv             # Templates oficiais padronizados para download e preenchimento
├── uploads/questions/         # Armazenamento isolado e seguro das imagens de apoio técnico
└── README.md                  # Documentação Completa do Projeto
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

- **users:** Administrador, Professores e Alunos (`role`, `unit_id`, `profile_pic`, `require_password_change`).
- **courses / modules:** Estrutura curricular.
- **questions / question_options:** Acervo completo (`is_active`, `import_tag`, opções randômicas na query).
- **classes / class_students:** Agrupamento e vínculos.
- **sprints / sprint_questions:** Entidade de Avaliação. Parâmetros de tempo (`time_limit_minutes`, `time_min_minutes`, `start_time`, `end_time`, `status`).
- **student_answers / sprint_attempts:** Centro de dados analíticos, guardando cada clique do estudante para os relatórios.

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
