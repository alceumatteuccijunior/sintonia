# Sistema Sintonia - Plataforma de Inteligência e Avaliação Educacional

## 🎯 O que é o Sintonia?

O **Sintonia** é uma plataforma corporativa e educacional de Alta Performance desenvolvida em PHP e MySQL (Vanilla), construída para transformar o processo de avaliação, diagnóstico e tomada de decisão em grandes redes de ensino (com forte alinhamento às diretrizes do SENAI e ao modelo SAEP). 

Ele não é apenas um "aplicador de provas", mas sim um **Ecossistema Multi-Tenant de Inteligência Pedagógica e Engajamento**. O Sintonia engloba de forma fluida todo o ciclo de vida da avaliação: desde o armazenamento de questões complexas e composição de simulados (Sprints), até a aplicação rigorosa (anti-cola), a extração de dados analíticos para professores e diretores em múltiplas hierarquias, e um inovador sistema de Gamificação e Duelos (P2P) para engajar os alunos no estudo contínuo.

A interface foca intensamente na usabilidade e estética (Glassmorphism, TailwindCSS, Phosphor Icons), entregando uma experiência "Premium", moderna e 100% responsiva.

## 🚀 Para que serve o Sintonia?

A missão do Sintonia é **erradicar o ponto cego pedagógico** e **potencializar o engajamento**. Suas principais atribuições são:

1. **Gestão do Conhecimento (Banco de Questões):** Armazenar de forma estruturada as questões do modelo SAEP, fragmentando-as rigidamente por Capacidade, Comando, Contexto e Dificuldade.
2. **Aplicação Segura e em Escala (Sprints):** Permitir que docentes montem avaliações em poucos cliques. O motor de resolução nativo controla cronômetros e monitora perda de foco (abas do navegador) para coibir trapaças.
3. **Diagnóstico Cirúrgico para Docentes:** Transformar a nota bruta em inteligência acionável. O Raio-X da turma mapeia precisamente em quais "Capacidades" e "Módulos" os estudantes estão falhando.
4. **Governança Macro para Gestores (Multi-Tenant):** Oferecer à direção uma visão analítica massiva através do Dashboard Global, monitorando a evolução histórica de uma Regional inteira ou Unidade específica.
5. **Automação Institucional e Inteligência Artificial:** Importar massivamente arquivos base federais (SAEP via CSV) recriando o ambiente escolar instantaneamente, e gerar Planos de Aula Corretivos com a IA.
6. **Arena Sintonia e Gamificação:** Um hub de engajamento do aluno. Sistema de XP, Níveis, Fotos de Perfil Animadas (GIFs), Ranking (Leaderboard) e a inovadora **Arena de Duelos P2P**, onde os alunos desafiam uns aos outros em simulados para testar conhecimentos.

---

## 🛠 Arquitetura Técnica

- **Backend:** PHP 8+ (Estruturado, sem frameworks complexos, focado em alta velocidade e simplicidade).
- **Banco de Dados:** MySQL/MariaDB (Acesso via PDO).
- **Frontend:** HTML5, Vanilla JavaScript, CSS customizado.
  - **Framework CSS:** TailwindCSS (via script CDN).
  - **Ícones:** Phosphor Icons (CDN).
  - **Gráficos:** Chart.js
  - **Componentes Interativos:** TomSelect (Buscas dinâmicas).
- **Gerenciamento de Estado:** Sessões nativas do PHP (`$_SESSION`).

### Padrão de Arquitetura de Pastas

```
/
├── admin_*.php                # Módulos de Inteligência e Gestão da Diretoria (Dashboard, Unidades, Usuários)
├── class_*.php / classes.php  # Módulos do Professor para Gestão de Turmas
├── dashboard.php              # Dashboard principal (Visão Professor)
├── import_saep*.php           # Motor de importação do CSV Governamental SAEP
├── includes/                  # Componentes UI reutilizáveis (Header, Sidebar, Footer Global e Modal de Ajuda)
├── migrate_*.php              # Scripts dinâmicos de atualização e alteração de banco de dados
├── questions*.php             # Repositório Central de Questões e Cadastro
├── sprint_*.php               # Todo o ciclo da Prova (Criação, Execução Anti-Cola, Relatórios, Slides, Raio-X)
├── student_*.php              # O Universo do Aluno (Dashboard, Arena, Duelos, Ranking, Upload de Avatares)
├── sql/                       # Scripts de criação e população do banco de dados (setup.php)
└── README.md                  # Documentação do Projeto
```

---

## 🚀 Como Executar Localmente

1. Suba um servidor web com suporte a PHP (Apache/Nginx via XAMPP, Laragon, Docker, etc).
2. Configure o banco de dados e ajuste o arquivo `config.php` com as credenciais.
3. Para criar a estrutura completa do banco de dados (Tabelas e Chaves), acesse: `http://localhost/.../sql/setup.php`
4. Crie o **Administrador Master (Sede Central)** rodando: `http://localhost/.../migrate_admin.php`
5. Acesse o `index.php` e faça login com `admin@senai.br` (senha: `marciaalinemarcio`).
6. **Mágica da Inicialização:** No menu lateral do Administrador, clique em "Importar SAEP", selecione o arquivo CSV oficial e clique em processar. O sistema irá automaticamente:
   - Cadastrar todas as Regionais, Unidades Escolares, Cursos e Turmas.
   - Criar contas de Professores e Alunos vinculados às suas escolas.
   - Popular o Banco de Questões e todas as avaliações já respondidas (Sprints).
7. Opcional: Execute também as rotinas complementares para habilitar novos recursos: `migrate_profile_pic.php` (para habilitar avatares de alunos) e `migrate_capacities.php`.

---

## 📦 Modelo de Dados Principal (MySQL)

- **users:** Administrador, Professores e Alunos (controlado por `role` e `unit_id`). Possui suporte a `profile_pic`.
- **courses / modules:** Estrutura curricular base.
- **questions / question_options:** O núcleo do banco de questões do SAEP e suas alternativas.
- **classes / class_students:** Gestão e agrupamento de alunos.
- **sprints / sprint_questions:** Entidade de Avaliação. Pode ser uma prova normal (criada pelo professor) ou um *Duelo de Arena* (criada automaticamente pelo aluno).
- **student_answers:** Tabela central analítica. Registra cada clique e acerto de todas as provas do sistema.

---

## 🎯 Escopo e Progresso do Desenvolvimento

O desenvolvimento foi concluído em um fluxo contínuo e orgânico. Abaixo o histórico de todas as Fases Finalizadas (100%):

### ✅ Fase 1: Fundação & Visão Professor
- Estrutura do DB e Script de Inicialização.
- Template e Sistema de Design Premium (Glassmorphism).
- Banco de Questões e Organização de Cursos/Módulos.
- Cadastro e Gestão de Turmas.
- Assistente em Passos para Criação de Sprints.

### ✅ Fase 2: Motor de Avaliação Rigorosa
- Engine de Resolução de Sprints (`sprint_solve.php`).
- Temporizador persistente e submissão automática.
- Armazenamento assíncrono à prova de falhas (AJAX).

### ✅ Fase 3 e 4: Diagnóstico, IA e Sala de Aula
- Ultra Relatório de Desempenho do Professor (quebra por Módulo, Capacidade e Questão).
- Integração de IA para Sugestão de Planos de Aula Baseados nos Gaps.
- Controle de Gabarito e Modo Projetor (`sprint_review_teacher.php`).
- Boletim Cirúrgico do Aluno após aprovação do Gabarito.

### ✅ Fase 5: Autenticação Real e Anti-Cola Rigoroso
- Níveis de Acesso Dinâmicos e Auto-Cadastro.
- Gestão de Turmas Avançada (mover alunos e lixeira lógica).
- Detecção de Perda de Foco: 3 avisos visuais ao sair da aba antes de finalizar a prova coercitivamente.

### ✅ Fase 6: Apresentações Executivas em HTML
- Histórico do Aluno com Gráficos Temporais (Chart.js).
- Motor de Geração de Slides Institucionais SENAI prontos para PDF, exportando diretamente do navegador o histórico e as sugestões de IA.

### ✅ Fase 7 e 8: Multi-Tenant SAEP & Inteligência Governamental
- Importador Massivo de Arquivos Federais (CSV SAEP). Recriação de toda a estrutura do Estado em segundos.
- Visão Global Macrossistêmica (`admin_dashboard.php`): Filtros dinâmicos por Regionais, Unidades Escolares e Cursos.
- Fluxo de status da prova (Pendente -> Em Andamento -> Finalizada) ativado de forma autônoma.
- Identificação visual imediata de risco pedagógico (<50% de acerto).

### ✅ Fase 9: A Revolução do Engajamento (Gamificação P2P)
- **Sistema de Níveis (Level Up):** Motor inteligente de cálculo de Experiência (XP). Cada acerto do aluno em todo o sistema gera 50 XP, subindo seu nível (Novato -> Proficiente -> Avançado -> Mestre).
- **Arena Sintonia (Treinamento):** O aluno solicita treinamento e o sistema "pesca" automaticamente 5 questões que o aluno nunca acertou antes para reforço.
- **Duelos PvP:** Alunos podem desafiar colegas de turma para Batalhas de Conhecimento. O sistema gera uma Sprint neutra idêntica para ambos e os dois competem pelo melhor tempo e nota.
- **Ranking Holográfico:** Pódio estilizado destacando Ouro, Prata e Bronze dentro da turma.

### ✅ Fase 10: Personalização e Onboarding Autodidata
- **Sistema de Fotos de Perfil:** Upload otimizado de Avatares JPG/PNG e suporte a GIFs animados, integrados nos rankings, duelos e dashboard principal.
- **Modal Global de Ajuda (Self-Onboarding):** Ícones estratégicos implementados ao lado dos títulos de todas as telas administrativas e docentes. Ao clicar, o sistema explica as funcionalidades, abolindo manuais de instrução externos.

---

**✨ Sintonia Finalizado e Pronto para Operação de Elite!**
