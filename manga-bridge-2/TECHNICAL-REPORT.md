# Entrega parcial verificável — Manga Bridge

Esta entrega é uma extensão de staging e um transporte externo experimental. Não é a atualização final do Manga Bridge Importer 1.7.52. Não instala nada no Ler Mangá Online e não habilita publicação.

## Código e isolamento

Branch manga-bridge-2-transport; PR #1 para manga-bridge-2. A branch main e todos os arquivos preexistentes do ImageClean foram preservados. O motor de manifestos Node adicionado à branch base durante o trabalho também foi preservado e passou nos testes.

O plugin complementar grava somente registros próprios não autoloadados em options. Não executa migrações, alterações de status, limpeza de capas, exclusões de capítulos ou SQL sobre o catálogo. Repetições do mesmo evento usam corpo e revisão idênticos; gravações concorrentes usam compare-and-swap. Uma execução vencida perde a autorização para gravar; cancelamento troca a geração do pedido.

A coleta fica no runner externo. Há limites de tempo, quantidade de requisições, tamanho de resposta e tentativas. DNS e endereço de conexão são verificados; redirecionamentos são recusados. Imagens são sondadas diretamente nas origens permitidas e o host público img.lermangaonline.com.br é recusado antes da resolução DNS. As verificações já armazenadas no checkpoint não são repetidas quando o inventário permanece igual. Isto não reduz as requisições que os leitores continuam fazendo ao Worker existente.

## Evidência observada

Execução Actions 43958992148, commit d7d3f6fa2572d151238761f7c30beb68101db30b:
- Sintaxe dos arquivos PHP aprovada em PHP 8.4.27.
- 28 verificações PHP de protocolo/estados e 12 testes Python aprovados.
- 9 testes do validador Node preexistente aprovados.
- Comunicação HTTP assinada Python → PHP → WordPress → MariaDB aprovada em loopback.
- WordPress 7.1.4 e MariaDB 11.4.13 no ambiente descartável.
- Comparação dos registros sintéticos antes/depois das operações, desativação e reativação sem mudanças no catálogo.
- ZIP de staging construído e disponibilizado como artifact.

O primeiro teste WP-CLI encontrou uma incompatibilidade do carregamento de fixtures com strict_types; o carregador de testes foi corrigido. A contagem impressa das asserções da integração também foi corrigida para usar o escopo global do WP-CLI. Não eram correções do site de produção.

As alterações seguintes acrescentam teste de instalação do ZIP gerado, sucesso da paginação dinâmica e preservação do checkpoint durante falhas temporárias. O resultado correspondente deve ser consultado na execução do commit mais recente do PR; não inferir sua aprovação apenas deste relatório.

## O que não foi comprovado

Não houve exame dos bytes do ZIP exato instalado da v1.7.52. O repositório inicialmente continha somente o planejamento Manga Bridge. A entrega anterior registrada na conversa identifica um ZIP, mas não comprova o conteúdo da instalação atual.

Não foram identificadas funções específicas do Importer responsável pela carga MariaDB, pois seu código ainda não foi examinado nesta fase. Não houve benchmark antes/depois na hospedagem, importação real autorizada de uma obra, teste dos adaptadores antigos, publicação por quantidade ou confirmação de novos capítulos na v1.7.52. Não afirmar redução percentual, prazo por obra ou resolução da quota do Worker.

O código e as métricas privadas do Worker não foram disponibilizados. A rota pública não foi alterada. O desaparecimento de aproximadamente 50 obras continua sem causa comprovada; nenhuma restauração ou alteração em massa foi executada.

## Dependências e limites

1. Disponibilizar em manga-bridge-2/baseline/ o ZIP exato instalado, contendo somente código autorizado para este repositório público. Conferir segredos antes do envio. Não publicar banco, backups, wp-config ou logs privados.
2. Examinar essa base, mapear chaves e relações reais, reproduzir os defeitos com fixtures e integrar o transporte progressivamente ao Importer sem desativar seu modo atual.
3. Configurar apenas staging privado com Secrets e cron do servidor. A execução automática não depende da tela aberta, mas necessita cron funcional. O teste de ciclo de estados não comprova o cron da hospedagem.
4. Validar workflow_dispatch e a entrega GitHub → staging com as credenciais reais fora do código. O workflow sob demanda ainda não foi disparado contra um staging externo; main permanece intocada.
5. A concorrência do executor é serial. GitHub Actions não é fila durável nem servidor permanente; pedidos não reivindicados são encerrados pelo watchdog com motivo explícito, sem executar tentativas infinitas. Antes de múltiplos pedidos simultâneos, integrar uma política de despacho durável ao controlador.
6. O checkpoint desta fase tem limite de tamanho e quatro execuções automáticas por geração. Obras que excedam esses limites não são consideradas publicadas. Sondagem de cabeçalhos não comprova decodificação integral de todas as imagens.
7. Inventariar os registros ausentes em acesso somente leitura e comparar com backup privado, sem qualquer restauração automática.

O pacote final substituto do Importer permanece pendente desses requisitos. A extensão de staging existe para validar a integração sem expor o catálogo a uma atualização improvisada.
