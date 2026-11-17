# Benchmark de 10 mangás — resultado verificado

**Execução GitHub Actions:** https://github.com/anuncieja/imageclean/actions/runs/44025821413
**Branch:** manga-bridge-benchmark · **PHP 8.4.27 · WordPress 7.1.4 · MariaDB 11.4.13**.

## A) Medição real — publicação sintética no WordPress de testes

O script criou e publicou 10 obras sequenciais com capítulos e metadados compatíveis com o formato da v1.7.53, incluindo a verificação da gate de integridade **com recibos sintéticos somente neste ambiente descartável**. Não houve coleta, HEAD/GET de imagem, Worker, cron real, WordPress de produção ou execução integral do pipeline import_manga().

| Perfil | 10 obras × capítulos | URLs de páginas | Tempo observado de gravação/publicação | Média por obra | p95 por obra | SQL observadas | Pico PHP |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Poucos (10 cap./obra, 12 pág./cap.) | 100 | 1.200 | **1,135 s** | 0,113 s | 0,128 s | 5.273 | 55,31 MiB |
| Médios (50 cap./obra, 20 pág./cap.) | 500 | 10.000 | **5,105 s** | 0,510 s | 0,546 s | 24.874 | 57,31 MiB |
| Muitos (200 cap./obra, 24 pág./cap.) | 2.000 | 48.000 | **20,563 s** | 2,056 s | 2,227 s | 98.374 | 63,31 MiB |

**Atenção:** tempos medidos na máquina e banco temporários do GitHub, não previsões de tempo de importação no cPanel. Cada perfil teve uma rodada de 10 obras; medianas e p95 referem-se à variação entre essas 10 obras, não a múltiplas repetições independentes. Perfis foram executados em ordem de tamanho crescente no mesmo banco CI.

## B) Coleta/validação externa — projeção, NÃO medição

Contagem hipotética por obra = 1 GET de inventário + 1 GET da capa + 1 GET de HTML por capítulo + 1 GET parcial por imagem. Sem redundância, erros, atraso de cron, limites extras da origem, paginação ou reparos.

O executor possui limite de 300 consultas por job e 4 tentativas/jobs por obra (máximo teórico de 1.200 consultas). O script soma o tempo de publicação observado no WP com latências externas **supostas** de 0,2/0,6/1,2 s por consulta e 30 s hipotéticos por inicialização do GitHub Actions. Valores de latência/startup NÃO foram cronometrados.

| Perfil | Requisições para as 10 obras | Execuções mínimas por obra | RTT 0,2 s | RTT 0,6 s | RTT 1,2 s |
| --- | ---: | ---: | ---: | ---: | ---: |
| Poucos | 1.320 | 1 | **9,42 min** | **18,22 min** | **31,42 min** |
| Médios | 10.520 | 4 | **55,15 min** | **125,29 min** | **230,49 min** |
| Muitos | 50.020 | 17 | **Não conclui** | **Não conclui** | **Não conclui** |

Para mangás grandes, precisamos de checkpoint durável de longa duração e orquestração em muitos lotes, **sem liberar publicação antes de validar toda a obra**. A limitação atual de quatro tentativas não é compatível com o perfil de 200 capítulos × 24 imagens. Também será necessário verificar o limite de 220 KiB do checkpoint JSON antes de implementar esse porte.

## C) Escopo e próximos critérios

- Resultado de publicação em CI é real e reproduzível, mas não mede o tempo de origem, Cloudflare, SEO/RankMath nem a hospedagem cPanel.
- A estimativa de ponta a ponta é uma calculadora parametrizada, **não um benchmark real**. Condições ruins de rede, 429 e tentativas adicionais podem aumentar bastante.
- A maior parte da carga de uma importação completa nesse modelo está nas requisições e validação de imagens, não na inserção de posts no banco da CI.
- Como o site já sofreu desaparecimento de obras e sobrecarga, **não executar benchmark massivo no ambiente de produção** antes de staging privado, backups e configuração do executor.
- Antes de adotar GitHub para obras grandes, corrigir orçamento de jobs e persistência; não aumentar indiscriminadamente a concorrência e não pular a verificação das imagens.

**Artifato com JSON e CSV (retenção até 24/11/2026):** https://github.com/anuncieja/imageclean/actions/runs/44025821413
