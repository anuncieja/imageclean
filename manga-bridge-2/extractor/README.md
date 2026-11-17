# Manga Bridge 2.0 — Fase 2: inventário externo de origem

**Status: extrator real em modo somente leitura. Não integrado ao WordPress e NÃO publica.**

## O que implementamos
- Parser real de HTML de obras e páginas de capítulos, sem bibliotecas externas (Python 3.12).
- Links de capítulos limitados às seções da obra (não aceita anúncios como capítulos).
- Extrai contagem declarada, título, capa, capítulos e URLs de imagens identificáveis.
- Segue somente paginação que a própria origem expõe: /page/N ou ?paged=N do MESMO mangá; não inventa rotas REST.
- Se a origem mostrar 30 capítulos de 100: retorna blocked_partial_list, sem liberar a obra.
- Se houver botão dinâmico sem URL de paginação: blocked_dynamic_loader.
- Salva checkpoint atômico de progresso; retoma sem consultar páginas já verificadas.
- Limites: quatro requisições no padrão (máximo vinte), uma por vez, 1,5 segundos de intervalo, timeout 7 segundos, até 1,5 MB de HTML por resposta.
- Pode amostrar até dois capítulos para contar URLs de páginas SEM baixar imagens.
- Não acessa o Worker img.lermangaonline.com.br nem o WordPress de produção.

## Testes offline

Rodar na pasta manga-bridge-2:

    python3 -m unittest discover -s extractor/test -v
    python3 -m compileall -q extractor

O workflow manga-bridge-2-source-ci executa os testes em cada alteração do extrator.

## Teste real manual

Somente para fontes para as quais você esteja autorizado a consultar. Um único mangá e no máximo três requisições de HTML:

    python3 -m extractor.cli \
      --live --allow-host mangalivre.blog \
      --url https://mangalivre.blog/manga/ao-no-hako/ \
      --max-requests 3 --max-pages 3 --sample-chapters 0 \
      --report /tmp/mb2-report.json --checkpoint /tmp/mb2-state.json

O arquivo de relatório contém URLs extraídas: não o publique neste repositório público. O valor inventory_complete significa apenas que a lista de links corresponde à contagem informada. Nunca dá permissão de publicação (allowPublish é sempre falso).

## Limites que continuam importantes

1. Um botão JavaScript sem endpoint explícito ainda requer um adaptador específico de origem, ou navegador headless controlado. Não adivinhamos APIs.
2. Um total informado pela origem pode estar incorreto. Divergências não autorizam exclusões.
3. URL de imagem identificada não garante que a imagem realmente abra. Não fazemos milhares de HEADs nem downloads.
4. GitHub Actions tem sistema de arquivos temporário; checkpoint local é para experimentos. Produção necessita armazenamento durável e comunicação autenticada.
5. A resolução DNS é conferida para endereços públicos antes da conexão, mas ainda não há pinagem de DNS/TLS. Origem de produção deverá ser pré-aprovada.
6. Precisa da v1.7.52 exata para definir o contrato de dados do plugin. Até lá, nenhuma gravação no WordPress.
7. Logs do GitHub devem ficar livres de credenciais e URLs privadas. O repositório ainda é público.

Próxima camada: adaptador dinâmico para páginas de capítulos sob limites verificáveis, verificação de imagens sob orçamento e integração com o plugin em staging.
