# Manga Bridge 2.0 — Fase 2: validação verificável (2026-11-17)

## Entrega efetivamente implementada no GitHub
- Parser Python 3.12 de HTML de origem com extração de título, capa, links de capítulo e URLs de imagem presentes em páginas HTML.
- Inventário com paginação explícita do mesmo mangá, respeito ao limite de requisições e sem inventar endpoints de REST.
- Checkpoint atômico para retomar páginas HTML já lidas sem refazer o processo.
- Diagnóstico de contagem declarada versus links, URLs duplicadas, números fracionados como 23.2/23.5 e páginas não identificadas.
- Testes de URL HTTPS/host, bloqueio de arquivos de imagem inacessíveis (na amostra), lista parcial e saída de publicação sempre desligada.

## Evidência no GitHub Actions
- Execução: https://github.com/anuncieja/imageclean/actions/runs/44019942754
- Testes offline: **23/23 aprovados**.
- Consulta real controlada: um GET da página da obra Ao no Hako no domínio de origem, seguido de um GET do primeiro capítulo (sem baixar binários).
- Inventário: **254 capítulos declarados, 254 links coletados, nenhuma numeração repetida**.
- Amostragem de um capítulo: **22 URLs de imagens identificadas estruturalmente**.
- O plugin WordPress e o Worker de imagens da loja não foram acessados na prova.
- Código não foi implantado em produção.

## Limitação que não deve ser mascarada
- Os 254 capítulos foram listados, **não foram todos baixados, nem tiveram suas imagens verificadas**.
- A origem pode mudar layout ou fornecer contagens incorretas; testes não garantem todas as obras.
- Links de imagens encontrados não comprovam imagem disponível/legível.
- Caso haja um carregador JavaScript sem endpoint exposto, a obra é bloqueada; nenhum navegador headless foi ativado.
- A execução é temporária dentro do GitHub Actions. Ainda faltam persistência durável entre runners, contrato autenticado com a v1.7.52 do plugin e teste em staging.
- Não há publicação nem alteração de IDs, slugs, capítulos, banco ou Cloudflare.

## Continuidade
1. Obter o ZIP exato da v1.7.52 que o Work está atualizando e definir o contrato estável de comunicação.
2. Implementar leitor resumível de imagens em lotes (por exemplo 2 capítulos por execução), com orçamento pequeno por origem e sem disparar o Worker.
3. Desenvolver adaptador controlado para carregamentos dinâmicos **quando necessário**, com testes que comprovem a rota real.
4. Implementar fila persistente e publicação autenticada, com dry-run e rollback em staging.
5. Só migrar produção após prova de equivalência do catálogo e auditoria dos ~50 mangás desaparecidos.

**A fase 2 entregou o núcleo funcional do inventário HTML; ainda não é uma solução completa de importação.**
