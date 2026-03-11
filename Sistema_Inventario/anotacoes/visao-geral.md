# Visao Geral do Projeto

## Objetivo

Construir um gestor de inventario com foco em autenticacao, controle de estoque e leitura rapida de indicadores.

## Escopo implementado

- Tela de login
- Painel com cards de resumo
- Lista de produtos com filtros
- Modal para cadastro e edicao
- Destacar itens com estoque baixo
- Exibir data de cadastro do produto
- Logout seguro

## Regras de negocio

- Somente usuario autenticado acessa o painel
- Estoque baixo: quantidade menor que 5
- Campos obrigatorios no cadastro: nome e categoria
- Preco e quantidade nao podem ser negativos

## Banco de dados

Tabelas:

- users
- products

O sistema cria as tabelas automaticamente se nao existirem.
