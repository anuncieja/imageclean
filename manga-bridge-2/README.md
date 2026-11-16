# Manga Bridge 2.0 — Arquitetura de migração (planejamento)

> Estado: **isolado, não instalado e não conectado ao WordPress**.
> Este diretório é o ponto de partida para substituir gradualmente o motor de importação, sem alterar o site ativo nem o catálogo existente.
> Não adicionar tokens, senhas, cookies, dados pessoais ou URLs privadas neste repositório público.

## Objetivos obrigatórios

- Manter todos os mangás e capítulos existentes, inclusive ID, slug, imagens referenciadas e relações no WordPress.
- Nunca apagar, despublicar, sobrescrever ou invalidar posts existentes apenas porque a origem falhou.
- Importar **uma obra completa de cada vez**; publicar somente depois de validar capítulos e páginas.
- Atualizações de capítulos são detectadas e **submetidas à confirmação manual** antes de publicar, até autorização explícita para mudar essa regra.
- Operar mesmo com o painel fechado, com estados persistentes e retomada idempotente.
- Ter botão Parar, importação manual por URL, detecção de atualizações e publicação em pequenos lotes.
- Minimizar carga de PHP/MariaDB e requisições ao Cloudflare Worker.
- Não repetir indefinidamente obras bloqueadas; registrar a causa específica e seguir para outro candidato quando permitido.

## Componentes propostos

1. **WP Control Plugin (leve)** — painel, autenticação, status, fila e publicação transacional em pequenos lotes. Nada de loops de scraping em páginas administrativas.
2. **GitHub Actions (execução inicial sob demanda)** — chamado por um serviço autenticado quando uma solicitação explícita for criada. Os jobs têm identificadores, limites de duração e logs resumidos. Não é servidor permanente.
3. **Import Engine (Node.js ou Python)** — extração, paginação explícita ou endpoints comprovados, coleta de imagens, validação, deduplicação e manifesto por obra.
4. **Ingress seguro para WordPress** — recebe manifesto assinado, verifica permissões, deduplica por identificador estável e aplica alterações de forma atômica na publicação. Dados externos não são comandos.
5. **Observabilidade** — logs estruturados sem segredos, contadores de requisição por origem, tentativas, tempo por etapa, erros recuperáveis e irrecuperáveis.
6. **Cloudflare Image Worker** — permanece separado da lógica de importação; validar número real de chamadas antes de alterar rota, cache ou regras de bots.

## Fluxo

```
Painel WP: pedido manual
  -> registro de job com ID idempotente
  -> chamada autenticada ao executor
  -> coleta paginada / validação de capítulos e capas
  -> manifesto completo com hashes e diagnóstico
  -> verificação no WP (dry-run)
  -> gravação de lote pequeno + confirmação
  -> atualização de estado do job
```

Estados mínimos: `queued`, `running`, `waiting_origin`, `ready`, `publishing`, `published`, `blocked`, `cancelled`.

## Garantias de segurança

- **Sem configuração de produção automática**. A migração será testada em staging primeiro.
- **Sem credenciais no GitHub**. Usar GitHub Secrets e permissões mínimas quando existir integração segura.
- Eventos externos não podem apagar posts ou substituir capítulos existentes.
- Retentativas de rede devem ter backoff e limite por origem, com retomada do progresso já validado.
- Imagem ausente = obra não publicável até correção. Não substituir por imagem fictícia.
- Falha na origem deve interromper apenas a obra afetada, sem travar outras obras independentes.
- Limitar a concorrência, requisições por minuto, duração e tamanho de jobs. Usar uma obra por vez inicialmente.
- Não assumir que cache do Worker reduz contagem de invocações: medir separadamente tráfego de leitores, bots e verificações.

## Antes de implementar e integrar

1. Obter **ZIP exato da v1.7.52** e backup/exportação do banco do WordPress.
2. Inventariar posts publicados, rascunhos e cerca de 50 mangás desaparecidos após downgrade; fazer comparação não destrutiva com backup.
3. Levantar métricas do Worker por rota, horário e origem; identificar quem está consumindo a cota.
4. Validar uma obra completa em staging, com imagens funcionais e relatório de tempo / carga.
5. Publicar somente após aprovação dos testes de integridade, consumo e reversão.

## Repositório e branches

Este projeto começa temporariamente na **branch `manga-bridge-2` do repositório `anuncieja/imageclean`**. A branch `main` e os arquivos atuais do ImageClean permanecem intocados. O proprietário pretende renomear o repositório depois.

**Atenção:** o repositório ainda é **público**. Não subir código proprietário, logs, backups ou segredos até confirmar a visibilidade desejada.
