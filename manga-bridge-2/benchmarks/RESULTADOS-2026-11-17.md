# Benchmark de 10 mangás — 17 de novembro de 2026

**Execução observável:** https://github.com/anuncieja/imageclean/actions/runs/44025480918

**Âmbito:** WordPress descartável + PHP 8.4 + MariaDB 11.4 na infraestrutura do GitHub Actions; plugin Importer v1.7.53 instalado. Nenhum dado de produção foi acessado.

## O que foi efetivamente medido

São cronometradas apenas inserções de posts WordPress e gravações de postmeta com URLs sintéticas (sem HTTP na origem, validação real de imagem, publicação segura por recibo, fila ou Worker). O script cria `post_status=publish` somente na instalação descartável; isso **não reproduz** a etapa real de verificação de integridade do Importer.

| Perfil | Obras | Capítulos/obra | URLs imagem/cap. | Total capítulos | Total URLs | Gravação WP medida | Consultas SQL |
|---|---:|---:|---:|---:|---:|---:|---:|
| Poucos | 10 | 10 | 12 | 100 | 1.200 | **0,471 s** | 2.403 |
| Médios | 10 | 60 | 20 | 600 | 12.000 | **2,778 s** | 13.400 |
| Muitos | 10 | 200 | 25 | 2.000 | 50.000 | **9,595 s** | 44.200 |

**Importante:** os tempos de escrita são de um banco descartável rápido, não do cPanel MariaDB sobrecarregado. Executar todos os registros em uma requisição não é a estratégia recomendada em produção: usar pequenos lotes, monitorar consultas por capítulo e preservação transacional.

## Tempos de rede: somente modelos de capacidade

O executor faz verificações individuais dos arquivos de origem; isto domina o tempo total. O modelo usa pelo menos `2 + capítulos×(1 + imagens por capítulo)` requisições por obra, sequenciais, **sem** retentativas, falhas, paginação extra ou validação integral dos bytes. Inclui 18 segundos **supostos** de inicialização por workflow, e soma a escrita WP medida acima. Os valores NÃO são tempos observados de importação.

| Perfil | Requisições para 10 obras (mín.) | Rede rápida suposta 0,15 s/req | Base suposta 0,45 s/req | Rede lenta suposta 0,90 s/req |
|---|---:|---:|---:|---:|
| Poucos | 1.320 | **6min18s** | **12min54s** | **22min48s** |
| Médios | 12.620 | **46min36s** | **1h49min42s** | **3h24min21s** |
| Muitos | 52.020 | **3h04min13s** | **7h24min19s** | **13h54min28s** |

**Crítico:** tempos de médios/muitos são apenas cenários hipotéticos após resolver os bloqueios abaixo; **não são atingíveis pelo motor atual**.

## Gargalos comprovados pela análise dos limites do código

- O executor do estágio externo suporta até **300 consultas HTTP por execução**.
- A máquina de estados `Job::event()` admite até **4 execuções por job**.
- Assim, o máximo por obra é **1.200 requisições** antes de bloquear; o real pode ser menor por orçamento de 420s, checkpoints e overhead.
- Com 60×20, uma obra precisa de **pelo menos 1.262 consultas**. Faltam no mínimo 62: não termina sob esse limite.
- Com 200×25, uma obra precisa de **pelo menos 5.202 consultas**: não termina sob esse limite.
- O limite de checkpoint registrado em evento é **220.000 bytes**. Modelo de JSON de estado completo baseado na estrutura atual:
  - Poucos: ~39,6 KB
  - Médios: ~387,6 KB
  - Muitos: ~1,61 MB

**Isso significa que não basta aumentar o número de execuções.** É necessária uma persistência resumível/paginada do estado, com manifestos e recibos compactados ou guardados em armazenamento durável, mantendo no WordPress somente os ponteiros e hashes.

## Interpretação operacional

1. **O principal custo não é escrever no WordPress do runner**, é verificar milhares de imagens remotamente e coordenar as etapas.
2. **Volume grande não está pronto**; a limitação é funcional, não apenas de velocidade.
3. As **44.200 consultas SQL** no cenário grande são uma base de custo sintético, não benchmark da query real do plugin. Na hospedagem, gravar em lotes pequenos e evitar gravações repetidas é essencial.
4. O proxy Cloudflare público não foi consultado. Esta medição não determina o consumo dos visitantes.
5. Para estimar duração real em produção, ainda é obrigatório registrar `job_start`, `inventory_complete`, `images_verified`, `publication_confirmed`, o número de tentativas, as latências p50/p95 HTTP, o número de consultas MariaDB e o tempo total para as 10 obras.

## Ações antes de ativar a integração no site

**P0** Tornar o checkpoint durável e compacto, sem exceder 220 kB nem regravar 50 mil URLs em options.

**P0** Permitir múltiplas execuções seguras por obra longa com limite de orçamento total configurado, sem loops infinitos; preservar pausa, lease e deduplicação.

**P1** Implementar relatórios de duração por fase em ações e no painel WordPress, para medir tempo real de ponta a ponta.

**P1** Reduzir queries e deslocar validação para o runner, com escrita final em pequenos lotes no WordPress.

**P1** Medir política de requisições por origem, sem elevar concorrência indiscriminadamente.

Arquivos de teste: `benchmarks/wp-publish.php`, `model.py`, `run-wp.sh`; artefato da execução: `wp-measured.json` e `model.json`. Resultados sintéticos e modelos matemáticos ficam separados.

**Este relatório não autoriza publicar conteúdo em produção nem conclui uma importação real.**
