# Anotacoes Tecnicas

## Credenciais e seguranca

- Credenciais do banco sao lidas de variaveis de ambiente
- Usar .env.local para dados reais
- Nao enviar .env.local para o repositorio
- Manter .env.example apenas com valores de exemplo

## Ambiente

- Banco alvo: MySQL na porta 3306
- Driver necessario: pdo_mysql

## Fluxo principal

1. Usuario acessa index
2. Sistema redireciona para login ou painel conforme sessao
3. Login valida usuario e senha com password_hash/password_verify
4. Painel carrega metricas, filtros e tabela
5. Cadastro/edicao de produto persiste no banco
6. Logout encerra a sessao

## Pendencias futuras

- Exclusao de produto
- Paginacao
- Relatorios
- Testes automatizados
