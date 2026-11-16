# Instalação isolada de staging

Não instalar em produção. O pacote manga-bridge-control-0.1.0-staging.zip NÃO substitui o Importer e não publica conteúdo.

1. Disponibilizar o ZIP exato do plugin em manga-bridge-2/baseline/ na branch de trabalho, depois de conferir que contém apenas código público autorizado. Não enviar banco, wp-config.php, logs privados, secrets ou backups. Comparar SHA-256 com a entrega anterior e registrar se o arquivo instalado difere.
2. Preparar WordPress de staging e backup privado. Manter produção e as imagens públicas sem alterações.
3. Instalar o ZIP de staging sem remover o Importer existente. Não executa migração nem invalida conteúdo na ativação.
4. Configurar WP_ENVIRONMENT_TYPE como staging. Definir MBI2_EXTERNAL_ENABLED como true apenas no wp-config.php privado do staging.
5. Gerar uma chave aleatória de 32 bytes codificada em base64 e guardar em MBI2_HMAC_KEY_B64 no wp-config.php privado e no GitHub Secret de mesmo nome. Não usar valores de testes como chave real.
6. Configurar GitHub Secret MBI2_WORDPRESS_ENDPOINT para o endpoint /wp-json/mbi2/v1 do staging, HTTPS. Configurar a variável MBI2_ALLOWED_MEDIA_HOSTS com os hosts públicos exatos da mídia autorizada.
7. Validar primeiro HMAC e cancelamento com o simulador local da CI. Só depois configurar MBI2_GITHUB_TOKEN privado com a menor permissão suficiente para dispatch no repositório. Nenhum token deve entrar em arquivo versionado.
8. MBI2_WORKFLOW_REGISTERED só pode ser true depois de verificar que manga-bridge-executor.yml aceita workflow_dispatch na ref de staging. Workflows novos podem exigir registro na branch padrão do GitHub: main permanece intocada e essa dependência não está autorizada automaticamente.
9. Em Ferramentas → Manga Bridge — Staging, criar uma solicitação de coleta e acompanhar o estado. O cron do servidor deve executar WP-Cron com o painel fechado. Não há publicação nesta fase.

Limites atuais: uma execução de oito minutos e 300 requisições no máximo; quatro execuções automáticas por pedido; corpo autenticado de no máximo 256 KiB. O coletor atual suporta somente os layouts explicitamente testados e o carregador REST Slimeread. Não é um substituto comprovado para todos os adaptadores da 1.7.52. O comprovante de imagem é um cabeçalho/intervalo de até 64 KiB com dimensões válidas, não o download integral de cada arquivo.

Retorno seguro: desativar somente Manga Bridge Control — Staging, remover sua configuração externa e manter os dados próprios para diagnóstico. Não apagar tabelas nem registros do catálogo. A extensão não altera os arquivos do Importer anterior.

O ZIP final do Importer só será liberado depois da inspeção da base instalada, adaptação de publicação, testes de preservação e rollback em WordPress/PHP 8.4/MariaDB real.
