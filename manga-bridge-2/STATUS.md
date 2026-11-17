# Estado da implementação

Fase atual: transporte isolado para staging, em desenvolvimento. Este pacote NÃO é uma atualização substituta do Manga Bridge Importer 1.7.52.

Implementado:
- Protocolo PHP/Python autenticado com HMAC-SHA256, corpo bruto, método, rota, timestamp e nonce.
- Máquina de estados com ID e geração por pedido, revisão monotônica, repetição idempotente, lease, cancelamento e retomada.
- Registros próprios não autoloadados; compare-and-swap limitado a opções mbi2_job_. Não há escrita em posts, postmeta, termos, slugs ou configurações do importador antigo.
- Coletor independente limitado a uma obra, fontes permitidas, DNS público com conexão fixada no endereço verificado, recusa do proxy público, inventário Slimeread e sondagem limitada de imagens originais.
- Workflow de execução sob demanda com limite de oito minutos, concorrência serial e sem exportação de manifestos privados em artifacts.
- Workflow de testes PHP 8.4/Python e ZIP separado do transporte de staging.

Não concluído:
- Obtenção e exame dos bytes do ZIP exato instalado em produção. A referência disponível na conversa é manga-bridge-importer-v1.7.52-meta-publicada.zip, SHA-256 5f5b26898627fb37f287b5f96552b69a1b60c16bb4f829dfc3547645445e45ea; isto identifica a entrega anterior, não confirma o arquivo hoje instalado.
- Comparação com o downgrade 1.7.41 e investigação dos ~50 registros ausentes: requer inventário e backup privados, somente leitura.
- Adaptação da publicação integral ao código real do Importer. Nenhuma publicação é habilitada nesta extensão.
- Integração dos pedidos por quantidade e da confirmação manual de novos capítulos ao Importer existente.
- Instalação, atualização/rollback do Importer legado e medições de carga antes/depois. A extensão complementar já passou em WordPress/MariaDB descartáveis; isso não valida a v1.7.52.
- Validação do workflow_dispatch nesta branch. O GitHub documenta restrições de registro de workflows na branch padrão. Não alterar main para contornar isso sem autorização.
- Alterações no Cloudflare Worker: nenhum código implantado ou configuração foi obtido nesta fase.

O ambiente local de terminal está indisponível nesta sessão. Resultados só serão marcados como aprovados após execução observável no GitHub Actions; fixtures não são medições de produção.

A extensão de transporte em Python/PHP complementa o validador de manifestos Node já adicionado à branch; os arquivos do validador são preservados. A CI executa as duas suítes. O teste WordPress/MariaDB usa somente registros sintéticos em banco descartável. A conclusão de cada execução deve ser consultada no Actions.

Resultados observados e limitações estão em TECHNICAL-REPORT.md. A execução 43958992148 aprovou a comunicação HTTP em WordPress/PHP 8.4/MariaDB descartáveis; a integração de publicação legada e o despacho real contra staging externo continuam pendentes.
