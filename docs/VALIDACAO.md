# Validação da versão 0.1.0

Data: 05/11/2026. Ambiente local: Node.js 24.22.1, Python 3 e Pillow 13.0.0. As amostras são sintéticas e não contêm imagens pessoais.

## Resultados executados

- `npm test`: 18 testes aprovados, zero falhas.
- Quatro amostras reais de codec geradas por Pillow: JPEG, JPEG progressivo, PNG com transparência e WebP sem perdas. Após limpeza, outro decodificador (Pillow) confirmou pixels RGBA idênticos, dimensões iguais e ausência de EXIF em todas as quatro.
- Estrutura: assinatura, dimensões, EXIF limitado, presença de ponteiro GPS, XMP e textos PNG; preservação seletiva de categorias; correção de tamanho RIFF e flags WebP; limpeza idempotente.
- Casos negativos: CRC PNG inválido, truncamentos, assinatura não suportada, resolução excessiva, dados depois do fim, orientação EXIF 6, animações, categorias de limpeza inválidas e 400 entradas aleatórias pequenas.
- Procedência: PNG `caBX` sintético e segmento JPEG APP11 bloqueiam processamento. A amostra `caBX` NÃO é um manifesto C2PA assinado e não verifica criptografia.
- Sintaxe de `app.js` e `engine.js` verificada com Node.
- Build estático concluído.

## Verificação manual no navegador

Executada no navegador Chromium disponibilizado no ambiente:

1. Página carregada com estilos, controles e textos em português.
2. Imagem sintética de demonstração carregada; 640 × 420, EXIF interpretado e ponteiro GPS identificado.
3. Limpeza EXIF concluída; saída reinspecionada e decodificada, download habilitado.
4. Conversão JPEG → WebP concluída; saída 640 × 420 decodificada.
5. Download do JSON executado e conteúdo lido no executor: versão 0.1.0, operação de conversão, formato WebP e SHA-256 com 64 caracteres.
6. Arquivo JPEG com orientação 6 carregado; conversão gerou WebP 32 × 48 a partir de dados 48 × 32. Remoção simples de EXIF foi bloqueada.
7. PNG com contêiner `caBX` sintético carregado; processamento bloqueado com mensagem explícita. Nenhuma validade criptográfica foi afirmada.
8. Captura visual da interface desktop inspecionada. Layout mobile implementado por CSS, mas não validado em aparelho ou em navegador mobile.

## Não executado / pendente

- Workflow CI no GitHub: configurado, ainda não executado remotamente.
- Publicação no Cloudflare Pages: não realizada.
- Verificação por SynthID: nenhum detector integrado, nenhum resultado produzido.
- Validação C2PA: não implementada; somente reconhecimento conservador de contêineres.
- Publicações e rotulagem em redes sociais: não testadas.
- Processamento em lote, ZIP de imagens, contas, créditos, pagamentos e backend SaaS: fora da versão experimental 0.1.
- Auditoria independente, teste de carga, ampla cobertura de codecs/perfis e suíte automatizada de UI em diferentes navegadores: pendentes.

Os resultados descrevem esta versão e estas amostras; não comprovam remoção universal de dados ou de marcas invisíveis.

## Revalidação em 06/11/2026

- Recuperado o pacote original da versão 0.1.0 para envio a `anuncieja/imageclean`.
- Reexecutados os 18 testes: todos aprovados.
- Build estático concluído e quatro comparações independentes por Pillow aprovadas (JPEG, JPEG progressivo, PNG e WebP).
- Não foram repetidos os testes manuais de navegador descritos acima, realizados em 05/11.
- Os resultados remotos do workflow devem ser consultados na aba Actions do repositório.
