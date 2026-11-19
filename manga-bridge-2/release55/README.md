# Mangá Bridge Importer 1.7.55 — lotes no GitHub

Em **Mangás → Lotes via GitHub**, informe de 1 a 100 novas obras para publicar. O Actions pagina o catálogo na origem, pula obras já existentes, reúne e valida capítulos e imagens e substitui candidatas recusadas. O WordPress recebe resultados assinados, prepara os capítulos em blocos e libera uma obra completa por vez. O cron do servidor continua o lote com o painel fechado. A contagem só aumenta após o WordPress publicar a obra.

## Ativação

1. Faça backup do banco e guarde o ZIP da versão ativa. Instale a atualização substituindo o importador no WordPress. O motor novo vem desligado.
2. O repositório `anuncieja/imageclean` deve ter os workflows de produção na branch `main`. Use o ambiente **manga-bridge-production** restrito à `main` e defina a chave **MBI2_HMAC_KEY_B64** no ambiente. Se precisar, configure **MBI2_ALLOWED_MEDIA_HOSTS** para os hosts de imagens autorizados (CSV).
3. No WordPress, configure uma chave HMAC idêntica à do ambiente e um token restrito ao repositório, com permissão **Actions: Read and write**. As constantes `MBI2_GITHUB_TOKEN`, `MBI2_HMAC_KEY_B64`, `MBI2_WORKFLOW_REGISTERED`, `MBI2_EXTERNAL_PUBLISH` e `MBI2_EXTERNAL_ENABLED` funcionam como na 1.7.54. Inclua `define('MBI2_BATCH_ENABLED', true);` apenas quando o workflow de lotes estiver registrado.
4. Confirme o cron do cPanel sem depender do painel aberto. A rota HTTPS `/wp-json/mbi2/v1/batches/` deve atingir o WordPress sem cache intermediário. Use o painel para pedir e, se necessário, parar o lote.

Nenhuma chave ou token deve ser enviado por mensagem ou commit. Não há chamada do importador ao Worker público de imagens. A rota pública de leitura permanece como estava.

## Limites e retomada

- Até **100 obras novas por lote**, **120 capítulos por obra** e **2.400 imagens distintas por obra**, respeitando também o teto de dados assinados. O limite de páginas do catálogo segue a configuração, até 100 páginas. O painel mostra o resultado parcial e as causas de recusa.
- Uma rodada de Actions trata até 12 candidatas e 300 pedidos HTTP à origem dentro do seu orçamento. O WordPress pede outra rodada quando necessário. A validação concluída não é refeita quando uma obra passa de uma rodada.
- Falhas transitórias têm retentativas limitadas. Um lote termina ao atingir a meta, esgotar o catálogo configurado ou atingir um limite de duração. Nenhuma obra incompleta conta como publicação.
- **Novos capítulos** continua separado e depende de aprovação manual. **Importar via GitHub** por URL e as telas locais continuam disponíveis.

Para parar a integração, interrompa o lote e desligue `MBI2_BATCH_ENABLED`. Para voltar à versão anterior, substitua o ZIP pelo anterior, sem usar **Excluir**. As publicações concluídas e o catálogo existente ficam guardados.

## Teste reproduzível

`tests/wordpress.sh` roda no GitHub Actions com WordPress, PHP 8.4 e MariaDB descartáveis. Simula 50 publicações, uma obra de 120 capítulos repartida entre rodadas, uma falha temporária, duplicatas, uma candidata recusada, entrega parcial de 15/20 e rollback. A origem é sintética; o teste não acessa o site público.
