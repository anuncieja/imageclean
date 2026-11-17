# Manga Bridge Importer 1.7.53 — atualização incremental

Base examinada: manga-bridge-importer-v1.7.52-meta-publicada.zip (SHA-256 5f5b26898627fb37f287b5f96552b69a1b60c16bb4f829dfc3547645445e45ea). É a entrega anterior identificada; a cópia atualmente instalada no servidor não foi comparada byte a byte.

Mudanças implementadas:
- Novos pedidos coletam e validam uma obra por vez; a primeira obra pode seguir para publicação antes de coletar as demais. A quantidade continua contando somente obras publicadas. Candidatas rejeitadas usam reposição e limites já existentes; não há aumento de paralelismo.
- Lotes anteriores sem stream53 preservam seu fluxo e cursor. Não são convertidos silenciosamente.
- Atualização deixa de executar a cadeia histórica de migrações sobre o catálogo. Flags antigas de deduplicação/revalidação são arquivadas no registro mbi_upgrade53 e retiradas da execução automática, inclusive para evitar sua execução após rollback.
- Contador de capas do painel usa somente um resultado em cache; sem cache, o botão permanece disponível sem varrer todos os mangás.
- A validação lê pausa e geração em uma consulta fresca, mantendo cancelamento entre as imagens. O recibo usa o mesmo snapshot recém-carregado, evitando recarga duplicada de metadados.
- Bloqueio de requisições ao Worker público ampliado para manutenção, detecção e preparação enquanto possuem a trava de trabalho. Diagnóstico explicitamente iniciado e requisições dos leitores continuam separados. Rota, chave e URLs públicas não são modificadas.

Escopo e limitações:
- Não é a conclusão da migração para execução externa. O transporte experimental continua separado, desativado para publicação; o Importer atual permanece operacional.
- Não elimina latência, indisponibilidade ou bloqueios da origem. Mantém verificação das imagens antes de publicar. Não há garantia de publicar qualquer obra em prazo fixo.
- Nenhuma restauração dos ~50 mangás foi executada; a causa do desaparecimento ainda exige comparação privada em acesso somente leitura.
- Não houve medição de carga no servidor de produção. Na fixture de 24 imagens, leituras do grupo de metadados passaram de 16 para 14. Isso não representa a redução total de consultas ou CPU da hospedagem.
- A lista histórica de erros de 13/10 é dado específico do site. Ela foi mantida no ZIP privado do usuário e excluída do código/artefato público desta branch. O pacote público da CI verifica o código e o ciclo de instalação, sem executar essa correção histórica.
- Os testes controlados cobrem inventário dinâmico, validação, pausa, retomada, duplicação, reposição, compatibilidade de lotes antigos e fluxo por obra. A CI nativa testa PHP 8.4, JavaScript, WordPress e MariaDB. Consultar a conclusão da execução, sem presumir aprovação deste arquivo.

Instalação segura:
1. Em staging, guardar backup privado do banco, wp-content e ZIP exato da 1.7.52. Não enviar backups ao GitHub público.
2. Pausar a fila e esperar a rodada atual terminar. Registrar quantidades/IDs/status, slugs, capas, relações e configurações antes.
3. Substituir o plugin pelo ZIP privado manga-bridge-importer-v1.7.53.zip. Não excluir o plugin pelo painel: uninstall.php é um fluxo diferente da atualização.
4. Conferir os mesmos registros, configurar/verificar cron real e testar uma URL e uma confirmação manual de novos capítulos. Só depois ampliar a quantidade. A publicação de novos capítulos continua manual.
5. Se houver falha, pausar e substituir os arquivos pelo ZIP exato da 1.7.52 guardado no passo 1. Não usar 1.7.41 como rollback desta atualização. Não restaurar o banco inteiro automaticamente.
6. Comparar os mesmos registros antes de retomar. Esta atualização não altera a estrutura de tabelas na passagem da 1.7.52 e não exige reconstrução de catálogo.

Nenhuma alteração foi instalada na produção. main e os arquivos do ImageClean são preservados.
