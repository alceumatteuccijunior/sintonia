# Sistema iaS - Evolução em cada desafio

## 🎯 O que é o iaS?

O **iaS** é uma plataforma corporativa e educacional de Alta Performance desenvolvida em PHP e MySQL (Vanilla). Ele foi projetado para revolucionar o processo de avaliação, diagnóstico e tomada de decisão em instituições de ensino, com forte alinhamento às diretrizes educacioniaS e aos padrões de excelência.

MiaS do que um aplicador de simulados, o iaS atua como um **Ecossistema Multi-Tenant de Inteligência Pedagógica e Engajamento**. A plataforma cobre com fluidez e modernidade todo o ciclo de avaliação: do armazenamento inteligente de questões à aplicação rigorosa com métricas anti-cola, passando pelo disparo de devolutivas em PDF por e-mail e terminando em um sistema inovador de Gamificação.

Sua interface foca intensamente na usabilidade e estética, utilizando **TailwindCSS**, **Phosphor Icons** e elementos de **Glassmorphism**, garantindo uma experiência "Premium", moderna e 100% responsiva para professores, alunos e gestores.

---

## 🚀 Para que serve o iaS? PrincipiaS Atribuições:

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
   Transforma a nota bruta em inteligência. O *Raio-X* da turma mapeia com precisão em quiaS Módulos e Capacidades a turma tem defasagem. 
   - Ao finalizar uma sprint, o aluno recebe **instantaneamente por E-mail** (função nativa PHP) sua Devolutiva Individual em formato PDF (tamanho A4 estruturado).
   - Professores também podem exportar esses PDFs individualmente pelo Dashboard.

4. **Governança Macro para Gestores (Multi-Tenant)**
   Visão analítica completa através de um Dashboard Administrativo, monitorando evolução de RegioniaS, Unidades Escolares e Cursos. Permite criação de planos de aula baseados em IA.

5. **Cadastro e Automação Institucional**
   - **Importação Massiva de Usuários (CSV):** Secretarias podem subir planilhas inteiras de alunos. O sistema cria as contas e dispara automaticamente um **e-mail de boas-vindas com as credenciiaS iniciiaS**. No primeiro login, o sistema exige obrigatoriamente a troca de senha por segurança.

6. **Arena iaS e Gamificação**
   O coração do engajamento do aluno. Possui:
   - **Sistema de Níveis (XP):** Resoluções de Sprints geram Experiência que eleva o ranqueamento.
   - **Avatares Personalizados:** Upload seguro de imagens (.JPG, .PNG, .GIF animados).
   - **Arena de Duelos (PvP):** Alunos desafiam uns aos outros na turma para "Batalhas de Conhecimento" geradas dinamicamente com questões que ainda não responderam.
   - **Ranking Global e Pódio Holográfico:** Exibição competitiva saudável.

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
