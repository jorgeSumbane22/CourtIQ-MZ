# CourtIQMZ — Gestão de clubes de basquetebol

Aplicação web em PHP e MySQL onde vários clubes de basquetebol gerem o seu plantel, jogos, treinos e site próprio na mesma instalação. Cada clube **só vê os seus próprios dados**. Um superadministrador gere a plataforma inteira.

Sem framework e sem Composer: corre diretamente num XAMPP.

- [Instalar](#instalar)
- [Credenciais](#credenciais)
- [Perfis e permissões](#perfis-e-permissões)
- [Entrar](#entrar)
- [Criar conta](#criar-conta)
- [Escalões](#escalões)
- [Idiomas](#idiomas)
- [Aspeto](#aspeto)
- [Palavras-passe esquecidas](#palavras-passe-esquecidas)
- [Estrutura do projeto](#estrutura-do-projeto)
- [Segurança](#segurança)
- [Antes de pôr em produção](#antes-de-pôr-em-produção)
- [Limitações conhecidas](#limitações-conhecidas)

## Instalar

Requisitos: PHP 8.1 ou superior, MySQL ou MariaDB, Apache com `.htaccess` ativo (o XAMPP já traz tudo).

1. Copie a pasta para `C:\xampp\htdocs\CourtIQ`.
2. Arranque o Apache e o MySQL no painel do XAMPP.
3. Crie a base de dados e os dados de demonstração:
   ```
   C:\xampp\mysql\bin\mysql.exe -u root < database\schema.sql
   ```
   **Atenção:** `schema.sql` apaga e recria todas as tabelas. Numa instalação com dados reais, faça primeiro um backup.
   O ficheiro começa com `USE courtiq;`, por isso escreve **sempre** na base `courtiq`, mesmo que indique outro nome na linha de comandos. Para o experimentar noutra base, retire primeiro as linhas `CREATE DATABASE` e `USE`.
4. Se a ligação à base de dados não for `root` sem palavra-passe, ajuste `config/database.php`.
5. Se a pasta não se chamar `CourtIQ`, ajuste `base_url` em `config/config.php`.
6. Abra `http://localhost/CourtIQ`.

## Credenciais

Estas são as contas criadas por `database/schema.sql`. **Todas têm a palavra-passe `admin123`. Mude-as antes de usar o sistema com dados reais.**

Endereço de entrada: `http://localhost/CourtIQ/login.php`

Entra-se só com o utilizador e a palavra-passe: o sistema reconhece o tipo de cada conta (ver [Entrar](#entrar)).

### Superadministrador

| Utilizador | Palavra-passe | Nome | Email | No login escolher |
|---|---|---|---|---|
| `superadmin` | `admin123` | Super Administrador | superadmin@courtiq.com | Superadmin |

### Administradores (um por clube)

| Utilizador | Palavra-passe | Nome | Email | No login escolher |
|---|---|---|---|---|
| `admin` | `admin123` | Helena Machava | admin@titans.co.mz | Admin · Maputo Titans |
| `admin.warriors` | `admin123` | Miguel Santos | admin@warriors.co.mz | Admin · Maputo Warriors |
| `admin.eagles` | `admin123` | Teresa Mondlane | admin@eagles.co.mz | Admin · Beira Eagles |
| `admin.lions` | `admin123` | Armando Muianga | admin@lions.co.mz | Admin · Nampula Lions |
| `admin.sharks` | `admin123` | Lúcia Nhantumbo | admin@sharks.co.mz | Admin · Quelimane Sharks |

### Treinadores

Nenhum tem escalões atribuídos, por isso entram por qualquer escalão do seu clube.

| Utilizador | Palavra-passe | Nome | Email | No login escolher |
|---|---|---|---|---|
| `carlos.oliveira` | `admin123` | Carlos Oliveira | carlos.oliveira@titans.co.mz | Treinador · Maputo Titans · qualquer escalão |
| `ana.santos` | `admin123` | Ana Santos | ana.santos@warriors.co.mz | Treinador · Maputo Warriors · qualquer escalão |
| `miguel.costa` | `admin123` | Miguel Costa | miguel.costa@eagles.co.mz | Treinador · Beira Eagles · qualquer escalão |
| `ana.ferreira` | `admin123` | Ana Ferreira | ana.ferreira@lions.co.mz | Treinador · Nampula Lions · qualquer escalão |
| `joao.silva` | `admin123` | João Silva | joao.silva@sharks.co.mz | Treinador · Quelimane Sharks · qualquer escalão |

### Jogadores

| Utilizador | Palavra-passe | Nome | N.º | Email | No login escolher |
|---|---|---|---|---|---|
| `joao.jogador` | `admin123` | João Macuácua | 7 | joao.jogador@titans.co.mz | Jogador · Maputo Titans · Seniores |
| `miguel.jogador` | `admin123` | Miguel Chissano | 10 | miguel.jogador@titans.co.mz | Jogador · Maputo Titans · Seniores |
| `pedro.jogador` | `admin123` | Pedro Langa | 5 | pedro.jogador@warriors.co.mz | Jogador · Maputo Warriors · Seniores |
| `tiago.jogador` | `admin123` | Tiago Cossa | 9 | tiago.jogador@warriors.co.mz | Jogador · Maputo Warriors · Seniores |
| `andre.jogador` | `admin123` | André Sitoe | 8 | andre.jogador@eagles.co.mz | Jogador · Beira Eagles · Seniores |
| `fabio.jogador` | `admin123` | Fábio Mabunda | 13 | fabio.jogador@eagles.co.mz | Jogador · Beira Eagles · Seniores |
| `luis.jogador` | `admin123` | Luís Matsinhe | 6 | luis.jogador@lions.co.mz | Jogador · Nampula Lions · Seniores |
| `bruno.jogador` | `admin123` | Bruno Zandamela | 16 | bruno.jogador@lions.co.mz | Jogador · Nampula Lions · Seniores |
| `rui.jogador` | `admin123` | Rui Tembe | 0 | rui.jogador@sharks.co.mz | Jogador · Quelimane Sharks · Seniores |
| `nelson.jogador` | `admin123` | Nelson Guambe | 19 | nelson.jogador@sharks.co.mz | Jogador · Quelimane Sharks · Seniores |

Cada clube tem 20 jogadores (8 Seniores, 6 Juniores e 6 Iniciados); só estes dois por clube têm conta de acesso.

### Adeptos

Não há contas de adepto nos dados de demonstração. Criam-se em `http://localhost/CourtIQ/registar.php`.

### Clubes de demonstração

| N.º | Clube | Cidade | Pavilhão | Escalões |
|---|---|---|---|---|
| 1 | Maputo Titans | Maputo | Pavilhão do Maxaquene | Iniciados, Juniores, Seniores |
| 2 | Maputo Warriors | Maputo | Pavilhão da FDJ | Iniciados, Juniores, Seniores |
| 3 | Beira Eagles | Beira | Pavilhão da Beira | Iniciados, Juniores, Seniores |
| 4 | Nampula Lions | Nampula | Pavilhão de Nampula | Iniciados, Juniores, Seniores |
| 5 | Quelimane Sharks | Quelimane | Pavilhão de Quelimane | Iniciados, Juniores, Seniores |

Cada clube tem dados de demonstração nos três escalões: 8 jogadores Seniores, 6 Juniores e 6 Iniciados, com jogos (terminados e por disputar), estatísticas, treinos e presenças em cada um. As contas de jogador de demonstração são todas de Seniores.

### Base de dados

Valores de origem em `config/database.php`:

| Servidor | Porta | Base de dados | Utilizador | Palavra-passe |
|---|---|---|---|---|
| `localhost` | `3306` | `courtiq` | `root` | (vazia) |

### Email (SMTP)

Não está configurado. `includes/Mailer.php` traz dados de exemplo (`smtp.gmail.com`, porta 587) que têm de ser trocados pelos de uma conta real para o sistema enviar emails.

### Estas credenciais não estão acessíveis pelo browser

O servidor recusa pedidos a ficheiros `.md`, `.txt`, `.sql` e `.log`, por isso este README não se abre em `http://localhost/CourtIQ/README.md`. Mesmo assim, **não publique este ficheiro com palavras-passe verdadeiras**: depois de as mudar, apague-as daqui.

## Perfis e permissões

| Perfil | Onde trabalha | Limite |
|---|---|---|
| Visitante | site público, sem conta | — |
| Adepto | site público + `conta.php` | sem limite |
| Jogador | `jogador/` | sem limite |
| Treinador | `treinador/` | 2 por clube |
| Administrador | `admin/` | 1 por clube |
| Superadministrador | `superadmin/` | 1 na plataforma |

| O que se pode fazer | Visitante | Adepto | Jogador | Treinador | Admin |
|---|:-:|:-:|:-:|:-:|:-:|
| Ver início, jogos e resultados, classificação, notícias, plantel | sim | sim | sim | sim | sim |
| Ver estatísticas detalhadas e galeria; comprar bilhetes | — | sim | sim | sim | sim |
| Receber as novidades do clube; pedir para entrar no plantel | — | sim | — | — | — |
| Painel do jogador; chat do clube | — | — | sim | sim | sim |
| Criar, editar e remover jogadores; criar contas de acesso para jogadores | — | — | — | sim | sim |
| Jogos (agendar, resultado, estatísticas, apagar), treinos, presenças, convocatórias, táticas, avaliações, comunicados | — | — | — | sim | sim |
| Aceitar ou recusar pedidos de inscrição de jogadores; enviar notificações | — | — | — | sim | sim |
| Repor palavras-passe de membros do clube; definir os escalões de cada treinador | — | — | — | — | sim |
| Finanças, dados do clube, notícias/galeria/patrocinadores, bilhetes vendidos | — | — | — | — | sim |

O superadministrador pode tudo, em todos os clubes: cria e gere clubes e contas, aprova pedidos de clubes, vê a auditoria (ações e inícios de sessão) e as métricas, responde a pedidos de suporte, faz cópias de segurança de toda a base de dados e liga o modo de manutenção.

Um treinador com escalões atribuídos só trabalha dentro deles (ver [Escalões](#escalões)).

A matriz está num só sítio, `includes/permissions.php`: as páginas perguntam `can('...')` e os formulários dos painéis são verificados antes de serem processados, por isso esconder um botão nunca é a única proteção.

## Entrar

Há um só formulário de entrada para todos: **utilizador (ou email) e palavra-passe**. Não se escolhe perfil, clube nem escalão: o sistema lê o tipo da conta na base de dados (`users.role`) e leva cada pessoa para a sua área.

| Tipo da conta (`users.role`) | Vai para |
|---|---|
| `superadmin` | `superadmin/dashboard.php` |
| `admin` | `admin/dashboard.php`, no seu clube |
| `treinador` | `treinador/dashboard.php`, no seu clube e nos escalões que treina |
| `jogador` | `jogador/dashboard.php`, no seu clube e no seu escalão |
| `adepto` | `conta.php` |

O clube vem de `users.team_id`, os escalões do treinador de `users.escaloes` e o escalão do jogador da sua ficha (`players.escalao`). O botão **Entrar como visitante** abre o site público sem conta. Contas inativas ou suspensas não entram, e a mensagem de erro é sempre a mesma ("Utilizador ou palavra-passe incorretos"), para não revelar que contas existem.
Depois de 5 falhas de palavra-passe na mesma conta, ou 20 vindas do mesmo endereço IP, o login fica bloqueado 15 minutos.

## Criar conta

A página `registar.php` (ligação no login e no topo do site) tem três opções.

- **Adepto** — escolhe um clube e passa a acompanhá-lo: em `conta.php` recebe um aviso sempre que o clube publica uma notícia, marca um jogo ou regista um resultado, e passa a ver as estatísticas, a galeria e a poder comprar bilhetes.
- **Jogador** — quem faz parte do plantel indica o clube, o escalão e a posição. A conta nasce como adepto e o pedido aparece ao treinador e ao administrador desse clube em *Pedidos de inscrição*. Se aceitarem (escolhendo o número da camisola), a conta passa a jogador e ganha ficha no plantel; se recusarem, continua adepto e vê o motivo.
- **Clube** — o responsável indica os dados do clube, os escalões que o clube tem e os seus próprios dados, que ficam como **administrador do clube**.

As contas de treinador são criadas pelo superadministrador.

### Entrada de clubes novos

- **Entrada imediata** (situação de origem) — o clube fica ativo logo e quem o inscreveu entra de seguida no painel de administração. O superadministrador recebe um aviso.
- **Com aprovação** — o pedido fica à espera em **Superadmin → Pedidos de clubes**; só quando é aprovado é que o clube e a conta passam a existir.

O superadministrador muda de um modo para o outro nessa mesma página. O número de clubes está limitado por "Máximo de equipas" nas Configurações (20 de origem); com o limite atingido, as inscrições ficam à espera mesmo em modo de entrada imediata.

Nenhum clube pode ter "CourtIQ" no nome: é o nome da plataforma.

## Escalões

Cada clube trabalha por escalões: **Iniciados**, **Juniores** e **Seniores**. O clube escolhe os que tem (na inscrição e depois em *Equipa*).

- Cada jogador, jogo e treino pertence a um escalão.
- Nas listas dos painéis há botões para ver um escalão de cada vez; a escolha mantém-se ao mudar de página.
- As presenças de um treino ou jogo só listam os jogadores desse escalão, e a convocatória de um jogo só aceita jogadores do escalão do jogo.
- No site público, o plantel, os jogos e as estatísticas filtram-se por escalão e a classificação tem uma tabela por escalão.
- No painel do jogador (dashboard, jogos, treinos, calendário, plantel e comparação de estatísticas) só aparece o que é do escalão dele.
- O superadmin define os escalões e envia o emblema de cada clube em *Equipas*, e os escalões de cada treinador em *Utilizadores*.
- A página inicial do site também tem os botões de escalão: jogos, resultados, melhores jogadores e classificação seguem o escalão escolhido.
- Não se pode retirar a um clube um escalão que ainda tenha jogadores.

**Treinadores.** O administrador define os escalões de cada treinador em *Equipa → Contas com acesso* (sem nada definido, treina todos). Todo o painel do treinador segue esses escalões: plantel, jogos, treinos, presenças, convocatórias, avaliações, pedidos de inscrição, estatísticas, relatórios e dashboard. Fora deles não vê, não edita e não remove nada.

**Administrador.** Vê as estatísticas, os relatórios (e as suas exportações) e o dashboard de todos os escalões juntos ou de um de cada vez. As finanças são sempre do clube inteiro.

## Idiomas

O sistema funciona em **português** e em **inglês**. O seletor está na barra de cima de todas as páginas e nas páginas de entrada (bandeira de Moçambique para Português, bandeira do Reino Unido para English). A escolha fica guardada num cookie durante um ano e vale para o sistema todo.

As páginas estão escritas em português. Com o inglês escolhido, a página inteira é traduzida no momento de ser enviada (`includes/lang.php`): cada texto, e cada placeholder, título ou confirmação, é trocado pelo que estiver no dicionário `lang/en/`. Por isso não é preciso mexer numa página para ela mudar de idioma.

- **O que se traduz** — menus, títulos, botões, formulários, tabelas, mensagens de sucesso e de erro, confirmações, o chat e as páginas de entrada.
- **O que não se traduz** — o que foi escrito pelas pessoas: nomes de clubes, jogadores e patrocinadores, notícias, comunicados, mensagens. Também ficam em português os ficheiros exportados (CSV, cópias de segurança) e os emails.
- **Partes variáveis** — números e nomes conhecidos saem do texto antes de procurar no dicionário: "8 atletas no plantel de Beira Eagles." procura-se como `{n} atletas no plantel de {0}.`

Para traduzir um texto novo acrescenta-se uma linha `'texto em português' => 'English text'` a um ficheiro de `lang/en/`. O script `lang/recolher.php` (linha de comandos) percorre todas as páginas com cada perfil e lista os textos que ainda não têm tradução. Os textos que o JavaScript escreve estão em `lang/en.js.php`.

## Aspeto

O desenho é desportivo, inspirado na NBA, com as **cores da bandeira de Moçambique**: fundo preto, **verde** nas ações e destaques, **vermelho** e **amarelo** como assinatura (a faixa da bandeira no topo, o item ativo do menu, o "IQ" do logótipo, as etiquetas de secção) e títulos em maiúsculas condensadas. Há tema escuro e tema claro, escolhidos no botão do canto inferior direito.

- As cores estão todas em variáveis no topo de `assets/css/layout.css` (painéis e site) e de `assets/css/auth.css` (páginas de entrada): `--accent`, `--accent2`, `--brand-red`, `--brand-yellow` e `--mz-stripe` (a faixa da bandeira).
- **Entrada pela liga**: quem abre o site entra como visitante em `visitante/inicio.php` (Liga › Clubes da liga), vê os clubes, os últimos resultados, os próximos jogos e a classificação, e escolhe o clube que quer ver. Só depois as páginas do clube (Início, Jogadores, Jogos, Estatísticas, Notícias e o menu Clube) mostram dados; sem escolha, levam de volta à liga. O clube muda-se no seletor de cima, onde "Toda a liga" volta à escolha. Quem tem sessão entra no seu clube.
- **Site público sem menu lateral**: a navegação está na barra de cima (`includes/layout/topnav.php`). Por cima, o clube em vista, o idioma e a conta; por baixo, Início, Jogadores, Jogos, Estatísticas e Notícias, e os menus suspensos **Liga** (Classificação, Equipas) e **Clube** (Equipa Técnica, História, Galeria, Bilhetes, Patrocinadores, Contacto). No telemóvel o menu abre no botão ☰. Os painéis continuam com o menu lateral.
- **Animações de basquetebol**: a bola do logótipo dribla ao abrir cada página, a faixa da bandeira entra da esquerda, os números dos placares sobem para o lugar, na página inicial a bola faz um lançamento em arco até ao cartão do próximo jogo, o ponto do próximo jogo pisca como o relógio dos 24 segundos, as listas vazias mostram o ícone a quicar, os avisos entram como um passe e no login as linhas do campo desenham-se. Também: os itens do menu entram um a um e o sublinhado vermelho corre por baixo do item, as duas equipas do próximo jogo entram de lados opostos com o "VS" no meio, os segundos do relógio viram como num placar, as linhas das tabelas entram uma a uma, as barras de estatísticas enchem-se, as etiquetas V/D aparecem com impulso, as janelas abrem a deslizar e as secções da página inicial aparecem ao fazer scroll (nos browsers que o suportam).
- Para não pesar, as animações usam só `transform` e `opacity`, duram menos de um segundo e meio e correm uma vez (só o ponto do próximo jogo se repete). Não correm quando o sistema do utilizador pede menos movimento. Não há desfoques nem efeitos ao passar o rato além da mudança de cor.
- Os tipos de letra são Barlow e Barlow Condensed, carregados só nos pesos usados.

## Palavras-passe esquecidas

Em `login.php`, **Esqueceu-se?** abre `recuperar.php`.

- **Com email configurado** — a pessoa recebe uma ligação válida durante 1 hora, que só serve uma vez.
- **Sem email configurado** (situação de origem) — o pedido chega como notificação ao administrador do clube, que em *Equipa → Contas com acesso* gera uma ligação de 24 horas para entregar à pessoa. Para administradores, o pedido chega ao superadministrador, que repõe a palavra-passe em *Utilizadores*.

Para ativar o email, preencha os dados SMTP no topo de `includes/Mailer.php`. O envio por SMTP autenticado precisa da biblioteca PHPMailer; sem ela, o sistema usa a função `mail()` do PHP, que no XAMPP normalmente não está configurada.

Num computador de desenvolvimento (`'env' => 'development'` em `config/config.php`, sem email e a aceder por `localhost`), `recuperar.php` mostra a ligação no ecrã. **Em produção mude `env` para `production`.**

O superadministrador muda os seus dados e a sua palavra-passe em *O meu perfil*.

## Estrutura do projeto

```
CourtIQ/
├── index.php, login.php, registar.php, registar-clube.php
├── conta.php (área do adepto), recuperar.php, redefinir.php
├── visitante/            site público (uma página por ficheiro)
├── admin/ treinador/ jogador/ superadmin/     painéis
├── includes/
│   ├── bootstrap.php     sessão, funções, base de dados, autenticação, permissões e idioma
│   ├── Auth.php          login, perfis, bloqueio por tentativas
│   ├── permissions.php   perfis, matriz de permissões, limites de contas e escalões
│   ├── lang.php          idioma e tradução das páginas
│   ├── team_helper.php   clube atual, team_owns(), modo de manutenção
│   ├── clubs.php         criação de clubes novos (inscrição e aprovação)
│   ├── panel_helpers.php arranque e componentes dos painéis
│   ├── public_helpers.php  formatação e componentes do site público
│   ├── upload_helper.php   envio de imagens
│   ├── password_reset.php  ligações de recuperação
│   ├── auth_page.php     moldura do login e do cadastro
│   ├── panel_pages/      páginas partilhadas por vários painéis
│   ├── layout/           moldura das páginas: head.php, sidebar.php, navbar.php e footer.php
│   └── repositories/     acesso a dados por entidade
├── assets/css/           layout.css, public.css (lp-), panel.css (pn-), auth.css (au-)
├── imagens/              imagens dos dados de demonstração
├── assets/js/            layout.js, public.js, panel.js, chat.js
├── lang/                 dicionário português → inglês e script de recolha
├── database/schema.sql   estrutura e dados de demonstração
├── uploads/              imagens enviadas (uma pasta por clube)
├── backups/              cópias de segurança geradas pelo superadministrador
└── config/               base de dados e definições gerais
```

**Moldura das páginas.** A sidebar, a navbar e o footer existem uma única vez, em `includes/layout/`:

| Ficheiro | O que tem |
|---|---|
| `head.php` | `<head>`, tema claro/escuro e os dados comuns (utilizador, avisos por ler) |
| `topnav.php` | a barra de navegação do site público, com Liga e Clube em menus suspensos (o site não usa `sidebar.php` nem `navbar.php`) |
| `sidebar.php` | o menu lateral |
| `navbar.php` | a barra de cima, com o seletor de idioma |
| `footer.php` | o rodapé e o fecho da página |

Cada painel tem um `{painel}_top.php` que só define o seu menu e inclui as três primeiras peças, e um `{painel}_bottom.php` que inclui o `footer.php`. Para mudar um item do menu edita-se o `_top.php` desse painel; para mudar o aspeto da sidebar, da navbar ou do footer edita-se um único ficheiro e muda em todo o sistema.

**Páginas partilhadas.** Várias páginas dos painéis são a mesma página com permissões diferentes. `admin/jogos.php` e `treinador/jogos.php`, por exemplo, só escolhem o painel e carregam `includes/panel_pages/jogos.php`.

**Vários clubes.** `users.team_id` diz a que clube pertence cada conta (o superadministrador não tem clube). Todas as tabelas com dados de clube têm `team_id`, e antes de ler, alterar ou apagar um registo cujo número vem do pedido a página confirma que é do clube com `team_owns('tabela', $id)`.

**Imagens enviadas.** Fotografias de jogadores e de perfil, emblemas, capas de notícias, galeria e logótipos são guardados em `uploads/equipa_{id}/`. Só entram imagens JPG, PNG, WEBP ou GIF até 4 MB; o tipo é lido do conteúdo do ficheiro, o nome é gerado pelo sistema e a pasta `uploads/` não executa PHP.

## Segurança

- Palavras-passe guardadas com bcrypt; sessão regenerada no login.
- Bloqueio de login durante 15 minutos após 5 falhas na mesma conta ou 20 do mesmo endereço IP. Fica registado na base de dados, por isso apagar os cookies não o levanta. Entrar com sucesso ou repor a palavra-passe limpa o bloqueio da conta.
- Todos os formulários e pedidos que alteram dados levam um código anti-CSRF.
- Todo o SQL usa consultas preparadas; todo o texto mostrado é escapado.
- `config/`, `includes/`, `database/`, `backups/` e `lang/` não são acessíveis pelo browser, nem ficheiros `.sql`, `.txt`, `.md` ou `.log`.
- As ações importantes ficam registadas na auditoria, com utilizador, clube e endereço IP.

## Antes de pôr em produção

1. Mude todas as palavras-passe de demonstração e apague-as deste ficheiro.
2. Em `config/config.php`, ponha `'env' => 'production'`.
3. Crie um utilizador MySQL próprio, com palavra-passe, e atualize `config/database.php`.
4. Sirva o site por HTTPS.
5. Configure o email em `includes/Mailer.php`.
6. Decida se os clubes entram de imediato ou com aprovação (Superadmin → Pedidos de clubes).
7. Agende cópias de segurança da base de dados **e** da pasta `uploads/`: o backup da base de dados não leva as imagens.

## Limitações conhecidas

- O cadastro não confirma o email (o envio de email não está configurado), por isso alguém pode criar conta com um email que não é seu. Há um limite de 5 contas novas por endereço IP em cada hora, e de 3 clubes por dia.
- Com a entrada imediata ligada, qualquer pessoa pode criar um clube e ficar administradora dele.
- A compra de bilhetes regista o pedido e gera um código, mas não cobra: não há pagamento online.
- A receita de bilheteira entra nas Finanças quando o administrador carrega em "Lançar nas Finanças" no jogo; não é automática a cada venda.
- Quem souber o nome de utilizador de outra pessoa pode bloquear-lhe o login durante 15 minutos, falhando a palavra-passe 5 vezes. A pessoa pode recuperar a palavra-passe para entrar de imediato.
- O chat atualiza de 4 em 4 segundos; não usa WebSockets.
- Os emails, os ficheiros CSV e as cópias de segurança saem sempre em português.
- O site público mostra os próximos jogos e os resultados na mesma página (Jogos); já não há páginas separadas de Calendário e de Resultados.
- O administrador do clube não faz cópias de segurança nem vê o histórico de ações: isso está só no superadministrador.
