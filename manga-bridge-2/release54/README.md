# Mangá Bridge Importer 1.7.54 — GitHub no Ler Mangá Online

O WordPress recebe um pedido em **Mangás → Importar via GitHub**. O cron do servidor pede uma execução do Actions. O GitHub obtém a ficha e a lista completa da obra na origem autorizada, lê os capítulos e verifica as imagens na origem. O WordPress recebe um manifesto assinado, verifica a integridade e duplicidade e publica no catálogo. O painel distingue capítulos verificados de obra publicada, e para de consultar quando o pedido encerra.

Esta versão entrega o modo por URL para **obra inédita**, uma por vez, com limite de 20 capítulos e 250 imagens distintas. Se a origem não apresentar uma contagem confiável dos capítulos e das páginas, a obra não é publicada. Importar por quantidade e detectar novos capítulos continuam nas telas existentes. A publicação de novos capítulos continua exigindo confirmação no painel. O fluxo novo não examina o catálogo inteiro, não consulta o Worker público de imagens e não altera a rota de leitura.

## Instalação

1. Salve o ZIP da versão em uso e faça um backup do banco. Pare de iniciar importações e aguarde a rodada atual terminar.
2. Em **Plugins → Adicionar plugin → Enviar plugin**, instale o ZIP 1.7.54 e aceite a substituição do plugin existente. Se o complemento separado **Manga Bridge Control — Staging** estiver ativo, desative-o. A integração vem desligada e não faz migração do catálogo.
3. Para ativar o novo motor, o workflow `manga-bridge-production.yml` precisa estar em `main` no repositório `anuncieja/imageclean`. Crie o ambiente **manga-bridge-production** e restrinja-o à branch `main`. No ambiente, crie o secret **MBI2_HMAC_KEY_B64**, uma chave aleatória de 32 bytes codificada em base64. Se a origem tiver um CDN próprio, cadastre somente os domínios autorizados na variável **MBI2_ALLOWED_MEDIA_HOSTS** (CSV). Não inclua o proxy público `img.lermangaonline.com.br`.
4. Crie um token do GitHub com acesso somente ao repositório `anuncieja/imageclean` e permissão **Actions: Read and write**. No `wp-config.php`, antes da linha que carrega `wp-settings.php`, configure os valores abaixo. A chave é a mesma do secret do ambiente. Guarde o token e a chave exclusivamente em configurações privadas.
5. Confirme que o cron existente no cPanel faz o WordPress executar os eventos pendentes com o painel fechado. A rota HTTPS `/wp-json/mbi2/v1/jobs/` deve chegar ao WordPress e não ser servida por cache. A confirmação de publicação deve vir do WordPress, na própria tela do pedido.
6. Comece com a URL autorizada de uma obra inédita pequena. Confira o link publicado, a capa e a leitura. Se o GitHub não iniciar, confira o código mostrado no pedido e a lista de execuções do Actions. Uma falha transitória da origem pode ser reprocessada automaticamente em até quatro tentativas; uma resposta ambígua da API do GitHub não é reenviada automaticamente.

```php
define('MBI2_GITHUB_TOKEN', '<TOKEN PRIVADO>');
define('MBI2_HMAC_KEY_B64', '<MESMA CHAVE PRIVADA DO GITHUB>');
// Se necessário: define('MBI2_ALLOWED_MEDIA_HOSTS', 'cdn-autorizado.example');
define('MBI2_WORKFLOW_REGISTERED', true);
define('MBI2_EXTERNAL_PUBLISH', true);
define('MBI2_EXTERNAL_ENABLED', true);
```

## Voltar à versão anterior

Interrompa o pedido ativo na tela e aguarde caso a gravação já esteja em andamento. Altere `MBI2_EXTERNAL_ENABLED` para `false`. Para reverter o código, instale o ZIP anterior escolhendo substituir a versão atual no painel; não use **Excluir** para trocar versões. Não apague tabelas ou restaure um backup sobre um catálogo que recebeu publicações depois da cópia. A desativação do motor externo não apaga obras, incluindo as publicadas pela integração.

## Código e testes

`overlay/` é aplicado sobre a versão 1.7.53, mantida no commit testado `3c31f2c65d032990bea27756d67e6f31bf9d67a5`. `src/` contém o executor. `tests/wordpress.sh` executa a integração em WordPress e MariaDB descartáveis; o host usado no teste real é localhost. A origem é simulada e os testes não gravam no Ler Mangá Online.
