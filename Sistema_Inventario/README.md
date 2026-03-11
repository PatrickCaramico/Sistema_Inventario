# Sistema Inventario

Aplicacao web em PHP + MySQL para gestao de estoque, com login por perfil, dashboard analitico, assistente Luigi e trilha de auditoria.

## Funcionalidades implementadas

- Autenticacao com sessao
- Controle de perfis:
  - Administrador: gerencia produtos
  - Funcionario: acesso somente leitura
- Protecao de rotas
- Timeout de sessao por inatividade com logout automatico e limpeza de cookie
- Exibicao do perfil logado e contador de tempo de sessao no topo

## Desafio Base

> 1. Módulos do Sistema
>
> - Tela de Login:
Campos: E-mail e Senha.
Design: Centralizado, seguindo a paleta de cores (pode ser a Professional Tech ou Soft Nature).
> - Dashboard Principal (Tabela):
Cabeçalho com resumo: "Total de Itens", "Itens com Estoque Baixo", "Valor Total em Estoque".
Tabela de Produtos: Nome, Categoria, Preço, Quantidade.
> - Ação Visual: Destacar em vermelho suave as linhas com menos de 5 unidades.
> - Formulário de Cadastro/Edição:
Pode ser uma página nova ou um Modal (fica muito moderno no portfólio).
Campos: Nome, Categoria (Dropdown/Select), Preço e Quantidade Inicial.
> 
> 2.  Funcionalidades de "Cliente"
Segurança: Se eu não estiver logado e tentar acessar painel.php, o sistema deve me chutar de volta para o login.php.
Filtro Inteligente: Um campo de busca ou botões de categoria (ex: [Tudo] [Mouses] [Teclados]).
Histórico de Cadastro: Uma coluna na tabela mostrando a data em que o produto foi adicionado (o PHP pega isso direto do banco).
Logout: Um botão visível para eu encerrar minha sessão com segurança.


### Dashboard

- Cards de resumo:
  - Total de itens
  - Itens com estoque baixo
  - Valor total em estoque
  - Total de produtos
- Insights:
  - Categoria em destaque
  - Produtos com estoque critico
  - Distribuicao por categoria
  - Atividade recente
  - Auditoria de acoes
- Grafico de barras por categoria (Chart.js)

### Produtos

- Cadastro de produto
- Edicao de produto em modal
- Exclusao de produto com confirmacao
- Ajuste rapido de estoque (-1 / +1)
- Destaque visual para estoque menor que 5
- Coluna de data de cadastro
- Busca por texto
- Filtro por categoria
- Ordenacao por colunas
- Paginacao de resultados

### Gestao de usuarios

- Menu exclusivo para administradores
- Cadastro de novos usuarios com:
  - nome
  - e-mail
  - senha
  - nivel de acesso
- Tabela com todos os usuarios cadastrados

### Assistente Luigi

- Botao flutuante com mini chat
- Painel do chat ampliado para melhor leitura
- Perguntas sugeridas clicaveis dentro da conversa
- Campo livre para o usuario digitar perguntas manualmente
- Ao fechar no X, o historico da conversa e limpo e o assistente volta ao estado inicial
- Respostas personalizadas mencionando o nome do usuario logado
- Respostas contextuais com base nos dados do painel

#### Exemplos de perguntas aceitas

- Ola
- Bom dia
- Boa tarde
- Boa noite
- Tudo bem?
- Quem e voce?
- O que voce faz?
- Como pode ajudar?
- Qual o total de itens?
- Quantos produtos estao cadastrados?
- Quais categorias existem?
- Qual categoria tem mais itens?
- Qual o valor total do estoque?
- Como evitar estoque critico?
- Quem te criou?
- Quem desenvolveu o sistema?

## Tecnologias

- PHP 8+
- MySQL 8+
- HTML5 + CSS3 + JavaScript
- PDO
- Chart.js (CDN)

## Estrutura principal

- index.php: redirecionamento inicial
- login.php: autenticacao
- painel.php: dashboard, filtros, tabela, grafico e assistente
- logout.php: encerramento de sessao
- includes/config.php: conexao, bootstrap do banco, sessao e seguranca
- assets/css/style.css: layout e componentes
- assets/images/: imagens do projeto
- anotacoes/: registros e notas tecnicas

## Requisitos

- XAMPP (Apache + MySQL)
- Extensoes PHP habilitadas:
  - pdo
  - pdo_mysql
  - mysqli

## Configuracao

1. Copie o arquivo de ambiente:

```bash
copy .env.example .env.local
```

2. Configure seu ambiente local em .env.local:

```env
APP_ENV=local
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=Sistema_Inventario
DB_USERNAME=root
DB_PASSWORD=sua_senha
SESSION_TIMEOUT_SECONDS=1800
```

3. Crie o banco (se necessario):

```sql
CREATE DATABASE Sistema_Inventario CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

4. Inicie Apache e MySQL no XAMPP.

5. Acesse:

- http://localhost/Sistema_Inventario/

## Usuarios iniciais

- Admin:
  - E-mail: admin@inventario.com
  - Senha: 123456
- Funcionario:
  - E-mail: funcionario@inventario.com
  - Senha: 123456

## Seguranca para GitHub

- Nunca publicar .env.local
- Publicar apenas .env.example com placeholders
- .gitignore ja configurado para arquivos sensiveis

## Troubleshooting

Se ocorrer erro could not find driver:

- Habilite pdo_mysql no php.ini
- Reinicie Apache e terminal

