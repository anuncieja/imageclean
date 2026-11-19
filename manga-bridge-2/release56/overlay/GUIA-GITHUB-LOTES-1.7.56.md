# Mangá Bridge Importer 1.7.56 — lotes contínuos pelo GitHub

**Mangás → Lotes via GitHub** aceita a quantidade de obras que você pedir e também permite importar uma única obra pela URL. O lote novo não tem os tetos anteriores de 100 obras por pedido, 120 capítulos ou 2.400 imagens por obra. O GitHub continua seguindo a paginação disponível da origem enquanto houver candidatas. Recusas não contam como publicação.

A verificação segue em partes autenticadas. O WordPress grava o progresso de inventário, capítulos e páginas em uma tabela auxiliar; a próxima rodada retoma dali. Quando tudo foi verificado, o WordPress prepara e libera os capítulos em etapas e só publica o mangá e soma à meta após concluir a obra. O cron do servidor dá continuidade sem precisar do painel aberto. O importador não usa o Worker público de imagens.

Cada rodada continua com limite de requisições, tamanho por pacote e tempo de execução. Esses limites controlam uma rodada e não descartam uma obra por quantidade. Um lote pode terminar abaixo da meta se o catálogo acabar, se a origem deixar de responder após retentativas ou se o GitHub ficar indisponível ou sem cota. Os limites da hospedagem e do processamento do WordPress também continuam valendo.

## Instalação e ativação

- Use o PR da 1.7.56 e registre `manga-bridge-batch-stream.yml` na branch padrão. O ambiente `manga-bridge-production` usa o mesmo segredo HMAC e hosts de imagens autorizados da 1.7.55. O endpoint permanece `/wp-json/mbi2/v1/batches/`. Mantenha-o fora do cache.
- Faça backup do banco e guarde o ZIP anterior. Se houver lote da 1.7.55 em andamento, deixe-o terminar antes da troca. A atualização substitui o plugin pelo ZIP, preserva as obras e cria a tabela auxiliar só ao iniciar o primeiro lote novo. Não use **Excluir** ao trocar ZIPs.
- Confira o token de Actions, a chave HMAC, `MBI2_EXTERNAL_ENABLED`, `MBI2_EXTERNAL_PUBLISH`, `MBI2_WORKFLOW_REGISTERED` e `MBI2_BATCH_ENABLED` conforme o guia da 1.7.55. O cron do cPanel deve executar o WordPress regularmente. Não compartilhe as chaves por mensagem ou commit.
- Se precisar reverter, pare o lote novo primeiro. A versão anterior preserva os posts publicados, mas não continua a coleta das rodadas novas.

As telas locais e a confirmação manual de **Novos capítulos** permanecem disponíveis. Para usar o fluxo de dados fracionado com URL, utilize o campo de URL da tela **Lotes via GitHub**. A antiga tela de URL continua disponível para compatibilidade.

## Teste

O CI usa WordPress, PHP 8.4 e MariaDB descartáveis. Exercita 101 publicações solicitadas, uma obra de 176 capítulos (incluindo capítulo de 130 páginas), 2.756 verificações de imagem nessa obra, paginação, interrupções de origem, deduplicação, um lote parcial e preservação após voltar à versão anterior. Usa uma origem simulada e não altera o site de produção.
