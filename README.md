# Sistema iaS - Evolução em cada desafio

## 🎯 O que é o iaS?

O **iaS** é uma plataforma corporativa e educacional de Alta Performance desenvolvida em PHP e MySQL (Vanilla). Ele foi projetado para revolucionar o processo de avaliação, diagnóstico e tomada de decisão em instituições de ensino, com forte alinhamento às diretrizes educacionais e aos padrões de excelência.

Mais do que um aplicador de simulados, o iaS atua como um **Ecossistema Multi-Tenant de Inteligência Pedagógica e Engajamento**. A plataforma cobre com fluidez e modernidade todo o ciclo de avaliação: do armazenamento inteligente de questões à aplicação rigorosa com métricas anti-cola, passando pelo disparo de devolutivas em PDF por e-mail e terminando em um sistema inovador de Gamificação.

Sua interface foca intensamente na usabilidade e estética, utilizando **TailwindCSS**, **Phosphor Icons** e elementos de **Glassmorphism**, garantindo uma experiência "Premium", moderna e 100% responsiva para professores, alunos e gestores.

---

## 🚀 Para que serve o iaS? Principais Atribuições:

1. **Gestão Estruturada do Conhecimento (Banco de Questões)**
   Armazena questões e separa o acervo por Curso, Módulo, Capacidade e Dificuldade. A recente integração de **Tags/Lotes (ex: SAEP2026)** permite catalogar milhares de questões de provas específicas para seleção com 1 clique.
   - **Importação Massiva (CSV):** O sistema aceita a criação de centenas de questões instantaneamente.
   - **Preservação de Dados:** A exclusão de questões é lógica (*Soft Delete*), garantindo que históricos e notas de alunos do passado não sejam perdidos.

2. **Aplicação Segura e Inteligente (Sprints)**
   A criação de provas, apelidadas de *Sprints*, permite customização extrema:
   - Definição de **Tempo Limite** (contagem regressiva máxima).
   - Definição de **Tempo Mínimo** de entrega (trava de segurança para obrigar o estudante a dedicar tempo de leitura, impedindo "chutes rápidos").
   - Motor de seleção inteligente de questões via filtros combinados.
   - **Sistema Anti-Cola:** O cronômetro persiste em tempo real e monitora perda de foco (saídas de abas). Após 3 avisos, a prova é encerrada automaticamente.

3. **Diagnóstico Cirúrgico para Docentes e Feedback Ativo**
   Transforma a nota bruta em inteligência. O *Raio-X* da turma mapeia com precisão em quais Módulos e Capacidades a turma tem defasagem. 
   - Ao finalizar uma sprint, o aluno recebe **instantaneamente por E-mail** (função nativa PHP) sua Devolutiva Individual em formato PDF (tamanho A4 estruturado).
   - Professores também podem exportar esses PDFs individualmente pelo Dashboard.

4. **Governança Macro para Gestores (Multi-Tenant)**
   Visão analítica completa através de um Dashboard Administrativo, monitorando evolução de Regionais, Unidades Escolares e Cursos. Permite criação de planos de aula baseados em IA.

5. **Cadastro e Automação Institucional**
   - **Importação Massiva de Usuários (CSV):** Secretarias podem subir planilhas inteiras de alunos. O sistema cria as contas e dispara automaticamente um **e-mail de boas-vindas com as credenciais iniciais**. No primeiro login, o sistema exige obrigatoriamente a troca de senha por segurança.

6. **Arena iaS e Gamificação**
   O coração do engajamento do aluno. Possui:
   - **Sistema de Níveis (XP):** Resoluções de Sprints geram Experiência que eleva o ranqueamento.
   - **Avatares Personalizados:** Upload seguro de imagens (.JPG, .PNG, .GIF animados).
   - **Arena de Duelos (PvP):** Alunos desafiam uns aos outros na turma para "Batalhas de Conhecimento" geradas dinamicamente com questões que ainda não responderam.
   - **Ranking Global e Pódio Holográfico:** Exibição competitiva saudável.

---

## 🌟 Lista Completa de Funcionalidades

### 🏛️ Administração e Diretoria (Multi-Tenant)
- **Painel Macro de Desempenho:** Filtre os dados e o histórico de provas por Estado, Regional, Escola e Curso.
- **Importação Automatizada SAEP:** Com 1 único arquivo CSV do Governo, o sistema constrói toda a hierarquia, cadastra todos os alunos, professores, turmas e históricos de Sprints automaticamente.
- **Onboarding Automático:** Disparo de credenciais temporárias por E-mail para alunos importados.
- **Gestão de Usuários:** Adição, edição e exclusão (Soft Delete para proteção de relatórios históricos) de alunos, professores e diretores.

### 👨‍🏫 Visão Docente (A Sala de Aula)
- **Banco de Questões Mapeado:** Inserção via painel ou CSV. Aceita randomização inteligente (sempre embaralha `A, B, C, D`).
- **Criação Rápida de Sprints:** Associa turmas, escolhe quantidade de questões por Lote/Tag ou Dificuldade.
- **Controles Anti-Falta de Foco:** Limite Máximo de tempo de prova e **Tempo Mínimo Obrigatório** (para inibir chuta-chuta).
- **Raio-X Analítico em Tempo Real:** Descubra exatamente *qual capacidade* o aluno errou (sem focar na "nota vazia").
- **Tutor IA (Apoio ao Docente):** Um chat integrado com a inteligência do banco que gera automaticamente "Planos de Aula Corretivos" baseados nos piores desempenhos da sala.
- **Exportação Coletiva:** Gere as Devolutivas em Slides PDF para usar em Projetor/Sala de Aula.
- **Gestão de Lixeira (Soft Delete):** Remova alunos de turmas antigas e envie-os para uma aba "Lixeira" antes de exclusão permanente.

### 🎓 Visão do Aluno (Gamificação e Experiência)
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
- **Frontend:** HTML5, Vanilla JavaScript.
- **Estilização e UI:** TailwindCSS (CDN), Phosphor Icons.
- **Visualização de Dados:** Chart.js.
- **Componentes Interativos:** TomSelect.
- **Infraestrutura/Deploy:** Automação Contínua (CI/CD) em cPanel via git `.cpanel.yml`.

### Padrão de Arquitetura de Pastas

```
/
├── admin_*.php                # Módulos Administrativos (Dashboard, Exportações SAEP, IA)
├── class_*.php / classes.php  # Módulos do Professor para Gestão de Turmas
├── dashboard.php              # Dashboard Global (Visão Professor)
├── import_*.php               # Rotinas de importação de CSV (Usuários e Questões)
├── includes/                  # Componentes reutilizáveis (Header, Sidebar, Mailer, ModiaS)
├── migrate_*.php              # Scripts dinâmicos de Update do BD (Ex: migrate_sprint_min_time.php)
├── questions*.php             # Repositório Central de Questões e Cadastro Manual/CSV
├── reset_password.php         # Fluxo obrigatório de troca de senha inicial
├── sprint_*.php               # Motor Core das Provas (Create, Solve, Report, Select, PDF)
├── student_*.php              # O Universo do Aluno (Dashboard, Arena, Duelos, Ranking)
├── template_*.csv             # Templates oficiiaS padronizados para download e preenchimento
└── README.md                  # Documentação Completa do Projeto
```

---

## 🚀 Como Executar Localmente

1. Suba um servidor web com suporte a PHP (Apache/Nginx via XAMPP, Laragon, Docker).
2. Ajuste o arquivo `config.php` com as credenciiaS do banco MySQL.
3. Para configurar o ambiente e habilitar 100% dos recursos miaS recentes, execute os scripts de banco de dados na ordem:
   - Configurações antigas do BD (Se necessário)
   - `migrate_admin.php` (Gera o Admin Master)
   - `migrate_profile_pic.php` (Avatares)
   - `migrate_capacities.php` 
   - `migrate_questions_tag.php` (Filtros Avançados)
   - `migrate_questions_active.php` (Soft Delete)
   - `migrate_sprint_min_time.php` (Trava de Leitura)
4. Acesse o sistema. Para o fluxo de envio de e-mails funcionar localmente, ative uma configuração SMTP ou ambiente de emulação na sua infraestrutura PHP.

---

## 📦 Modelo de Dados Principal

- **users:** Administrador, Professores e Alunos (`role`, `unit_id`, `profile_pic`, `require_password_change`).
- **courses / modules:** Estrutura curricular.
- **questions / question_options:** Acervo completo (`is_active`, `import_tag`, opções randômicas na query).
- **classes / class_students:** Agrupamento e vínculos.
- **sprints / sprint_questions:** Entidade de Avaliação. Parâmetros de tempo (`time_limit_minutes`, `time_min_minutes`).
- **student_answers / sprint_attempts:** Centro de dados analíticos, guardando cada clique do estudante para os relatórios.

---

## 🎯 Por que o iaS é Inovador?

O **iaS** resolve o eterno problema do ensino digital - a falta de foco do aluno - através do choque de Gamificação. Além de entregar um sistema esteticamente belíssimo que encanta usuários, ele é rígido com métricas e antifraude, blindando os relatórios de "chutes" irreiaS através do motor de tempo e travas de saída de abas. O uso de E-mails automatizados para *Onboarding* e envio instantâneo do Feedback humanizam o software, conectando instituição, aluno e resultados em tempo real.

**iaS - Evolução em cada desafio.**
