# Sistema Sintonia - Banco de Questões e Avaliações SAEP

O **Sintonia** é uma plataforma acadêmica desenvolvida em PHP e MySQL (Vanilla), projetada para permitir que professores apliquem provas e simulados (chamados de "Sprints") utilizando um banco de questões estruturadas nos moldes do SAEP (compostas por Capacidade, Contexto, Comando e Alternativas).

A interface foca intensamente na usabilidade e estética (Glassmorphism, TailwindCSS via CDN, Phosphor Icons), entregando uma experiência "Premium" e responsiva tanto para professores quanto para alunos.

---

## 🛠 Arquitetura Técnica

- **Backend:** PHP 8+ (Estruturado, sem frameworks complexos, focado em alta velocidade e simplicidade).
- **Banco de Dados:** MySQL/MariaDB (Acesso via PDO).
- **Frontend:** HTML5, Vanilla JavaScript, CSS customizado.
  - **Framework CSS:** TailwindCSS (via script CDN em ambiente de desenvolvimento).
  - **Ícones:** Phosphor Icons (CDN).
- **Gerenciamento de Estado:** Sessões nativas do PHP (`$_SESSION`).

### Padrão de Arquitetura de Pastas

```
/
├── includes/          # Componentes reaproveitáveis de UI
│   ├── header.php     # Header (dependências CSS/JS globais)
│   ├── footer.php     # Scripts, fechamento de tags HTML e UI logic global (parallax, etc)
│   └── sidebar.php    # Menu lateral responsivo
├── sql/               # Scripts de banco de dados
│   ├── setup.php      # Cria/Recria a estrutura de tabelas
│   └── import_json.php# Script de migração para importar o JSON de questões
├── config.php         # Configuração de conexão do banco (Credenciais PDO)
├── index.php          # Ponto de entrada / Tela de Login
├── auth.php           # Lógica de autenticação e criação de usuários teste
├── dashboard.php      # Dashboard principal (Visão Professor)
├── classes.php        # Gerenciamento de Turmas (Listagem)
├── class_create.php   # Formulário para criar nova turma
├── questions.php      # Visão de Cursos vinculados ao Banco de Questões
├── questions_view.php # Visualização detalhada das questões de um Curso
├── sprint_create.php  # Assistente de Criação de Sprints (Passo 1)
├── sprint_select_questions.php # Seleção de questões e finalização (Passo 2)
└── README.md          # Documentação do Projeto
```

---

## 🚀 Como Executar Localmente

1. Suba um servidor web com suporte a PHP (Apache/Nginx via XAMPP, Laragon, Docker, etc).
2. Configure o banco de dados e ajuste o arquivo `config.php` com as credenciais.
3. Para criar as tabelas pela primeira vez, execute o script: `http://localhost/.../sql/setup.php`
4. Para popular o banco com as questões base, execute: `http://localhost/.../sql/import_json.php`
5. Acesse o `index.php`. Você pode usar o botão "Autenticação Mágica" para logar rapidamente como Professor de teste.

---

## 📦 Modelo de Dados (MySQL)

As principais entidades e relacionamentos são:

- **users:** Professores e Alunos (campo `role`).
- **courses:** Cursos disponíveis (Ex: Técnico em Informática).
- **modules:** Unidades curriculares vinculadas a Cursos.
- **questions:** As questões em si (Contexto, Comando, Capacidade, Dificuldade). Vinculadas a Módulos.
- **question_options:** As alternativas (A, B, C, D) para cada questão e indicação da correta (`is_correct`).
- **classes:** Turmas criadas pelos professores, vinculadas a um Curso.
- **class_students:** Vínculo de Alunos com Turmas.
- **sprints:** Avaliações criadas por um professor para uma turma específica, com tempo limite (`time_limit_minutes`).
- **sprint_questions:** Questões selecionadas para compor uma Sprint.
- **student_answers:** Respostas submetidas pelos alunos para as questões de uma Sprint.

---

## 🎯 Escopo e Progresso do Desenvolvimento

### ✅ Concluído (Fase 1: Fundação & Visão Professor)
- [x] Estrutura do Banco de Dados e Script de Inicialização.
- [x] Importador de JSON inteligente para alimentar o Banco de Questões inicial.
- [x] Template e Sistema de Design Base (Glassmorphism, Tailwind).
- [x] Autenticação (Login) de usuários com controle de sessão PHP.
- [x] Dashboard do Professor com estatísticas dinâmicas (Count real do banco).
- [x] Banco de Questões: Navegação Cursos -> Módulos -> Cartões de Questões detalhados.
- [x] Turmas: CRUD base (Cadastro de Turmas vinculadas a um Curso).
- [x] Sprints (Passo 1): Seleção da Turma, Nome e Tempo Limite.
- [x] Sprints (Passo 2): Seleção visual de questões filtradas pelo curso da Turma, com aviso inteligente e opção para ocultar questões "Já respondidas pela turma" em avaliações anteriores.

### ✅ Concluído (Fase 2: Motor de Avaliação e Visão Aluno)
- [x] Cadastro manual de Questões avulsas no Banco de Questões (UI do Professor) através da tela `question_create.php`.
- [x] Lógica de primeiro acesso do Aluno (`student_setup.php` para seleção de qual Turma ele pertence).
- [x] Dashboard do Aluno (`student_dashboard.php` exibindo Sprints pendentes e status).
- [x] Motor de Resolução de Sprints (UI de Prova) via `sprint_solve.php`:
  - Exibição de uma questão por vez.
  - Temporizador visual no topo que auto-submete ao zerar.
  - Controle e validação do tempo independente para cada aluno (tabela `sprint_attempts`).
  - Armazenamento em `student_answers` a cada passo da prova (auto-save).

### ⏳ A Fazer (Fase 3: Inteligência e Relatórios)
- [ ] Ranking de Alunos (Gamificação).
- [ ] Dashboard de Desempenho do Professor (Ver pontos fortes e fracos da Turma baseados nas Respostas).
- [ ] Geração de Relatórios em PDF consolidados.
- [ ] Integração de IA para Sugestão de Planos de Aula baseado nas deficiências mapeadas nas avaliações.

---

*Este README deve ser atualizado a cada grande ciclo de implementação.*
