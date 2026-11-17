# Benchmark reproduzível — 10 obras por cenário

O projeto mede **tempo real de gravação de posts e metadados** de uma carga sintética em WordPress/PHP/MariaDB descartáveis via GitHub Actions. Não é um cronômetro da importação completa nem uma medição da hospedagem de produção.

| Classe | Mangás | Capítulos por obra | Imagens por capítulo |
| --- | ---: | ---: | ---: |
| Poucos | 10 | 10 | 12 |
| Médios | 10 | 60 | 20 |
| Muitos | 10 | 200 | 25 |

O modelo calcula um *limite inferior aproximado* de requisições HTTP usando a lógica atual de verificação de imagem individual, sem retries: `2 + capítulos * (1 + imagens)`, por obra. Os cenários de origem assumem 0,15 / 0,45 / 0,90 s por requisição e 18 s de inicialização por workflow (hipóteses, **não medições**). O fluxo processa uma obra por vez.

**Atenção:** o coletor de staging tem orçamento de 300 requisições por execução, 420 s, limite de 4 execuções por job e checkpoint no WordPress de aproximadamente 220 kB. Com algumas cargas, esses limites inviabilizam a publicação completa. O algoritmo informa `fits_4_attempts_each`, mas a publicação externa ainda NÃO está implementada de qualquer forma.

A verificação com intervalos de bytes permite conferir cabeçalho e dimensões, mas não comprova integridade total da imagem. Não são feitas requisições às origens, ao Worker da Cloudflare ou ao site de produção pelo benchmark. O WP simulado cria publicações fictícias com URLs `example.org` somente no banco descartável e não gera recibos reais de integridade.

Executado em branch isolada `manga-bridge-benchmarks`. A `main` ImageClean e todas as URLs de produção permanecem intactas.

Arquivo de resultados `out/wp-measured.json` registra apenas tempos reais de escrita de banco e contagem de consultas; `out/model.json` contém tempos hipotéticos para coleta, claramente etiquetados. Ambos podem ser obtidos no artifact do Actions.
