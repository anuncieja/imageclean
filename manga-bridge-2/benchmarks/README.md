# Benchmark de 10 mangás por porte — 17/11/2026

Perfis definidos para comparação reproduzível:

| Perfil | Obras | Capítulos por obra | URLs de imagens por capítulo |
| --- | ---: | ---: | ---: |
| Poucos | 10 | 10 | 12 |
| Médios | 10 | 50 | 20 |
| Muitos | 10 | 200 | 24 |

Este laboratório cria um WordPress com o plugin 1.7.53 e MariaDB 11.4 descartáveis no GitHub Actions. Mede o tempo de criação dos rascunhos, gravação das metadatas e URLs, verificação da gate de integridade do plugin com **recibos estritamente sintéticos**, publicação dos capítulos e publicação final de cada obra. Tudo serial. Coleta tempo total, latência média/mediana/p95 de cada obra, consultas SQL, memória PHP e contagens de posts. Nenhuma obra real é importada.

**Não é benchmark de ponta a ponta.** O fluxo real de validação de mídia, descoberta, publicação assíncrona, filas, paginação, falhas, cooldown, RankMath e imagens do Worker está intencionalmente excluído. A criação de recibos sintéticos nunca pode ser copiada para produção. Somente o script de testes em ambiente descartável pode usá-los.

O arquivo estimated_scenarios.csv calcula um cenário hipotético com:
- Uma chamada à página do mangá, uma à capa original;
- Uma chamada HTML por capítulo e uma verificação de cabeçalho por página de imagem;
- Limite de 300 requisições por GitHub Actions e 4 execuções por obra;
- RTTs ilustrativos de 0,2 / 0,6 / 1,2 segundo por requisição, e 30 segundos ilustrativos de overhead por execução;
- Nenhuma tentativa extra, link duplicado, bloqueio ou paginação adicional;
- Se uma obra ultrapassar 1200 requisições, marca como inviável nos limites atuais, em vez de inventar um tempo de conclusão.

**Os RTTs e o overhead não são medições reais.** O tempo de publicação em WP e consultas SQL são medidas reais do ambiente CI *quando a execução concluir com sucesso*. Nenhuma medição representa a hospedagem cPanel, a origem real ou o Worker Cloudflare.

Workflow: .github/workflows/manga-bridge-benchmark.yml, branch manga-bridge-benchmark. Os arquivos JSON e CSV são guardados como artifact por 7 dias.

Para medir o tempo real de dez obras no site, será necessário staging privado com o importador e motor externo integrados, registrar latência e tempo de espera por fila, e obter autorização para acessos moderados à fonte.
