# ImageClean Lab · 0.1.0

Laboratório experimental de inspeção e processamento local de imagens, em português. O frontend é estático, sem dependências de produção, login, telemetria ou API externa. Não é o SaaS comercial completo.

## Começar

Requer Node.js 22 ou superior. Não exige `npm install`.

```bash
npm test
npm start
```

Abra o endereço mostrado no terminal. Clique em **Experimentar com imagem de teste** ou selecione um JPEG, PNG ou WebP estático. A amostra de demonstração é sintética, com metadados de teste. Não é evidência de detecção de IA.

## Implementado

- Inspeção por assinatura binária: JPEG (incluindo progressivo), PNG e WebP.
- Leitura de dimensões e contêineres EXIF, XMP, IPTC/Photoshop, comentários JPEG e textos PNG quando reconhecidos.
- Leitura limitada de TIFF/EXIF: orientação, fabricante, modelo, software, autor, copyright, datas e presença de ponteiro GPS. Não extrai coordenadas e não enumera todo EXIF.
- Remoção por categoria de contêiner, seguida de nova inspeção. Dados codificados da imagem são preservados nesse modo. Remover EXIF retira o bloco inteiro, não apenas GPS.
- Perfis ICC e informações de cor não são selecionados para remoção na limpeza.
- Orientação EXIF diferente de 1 impede retirada do bloco: o usuário pode usar a conversão, que aplica a orientação pelo decodificador do navegador.
- Conversão no navegador para WebP, JPEG (fundo branco) e PNG; ajuste de qualidade e lado maior, sem ampliar imagens pequenas. Os formatos disponíveis dependem do navegador.
- Comparação original/saída, download da imagem, relatório JSON e SHA-256 de entrada/saída.
- Histórico transitório dos últimos 20 processamentos; não contém os arquivos e desaparece ao recarregar. Uma imagem por vez.
- Processamento estrutural em Web Worker, com limite de 45 segundos, 30 MiB, 24 MP e número limitado de registros. Nunca envia imagens ao servidor.
- CLI local para inspeção/limpeza, sem sobrescrever entrada ou arquivo de saída existente.
- Testes de contêiner, segurança de entrada, preservação de dados e decodificação independente.

## Limites explícitos

**SynthID:** não há detector integrado. Nunca exibe “removido” ou “imagem sem IA”.

**C2PA:** identifica contêineres reconhecidos ou possíveis (`caBX`, `C2PA`, APP11). APP11 também pode ter outras finalidades; é tratado conservadoramente. Não valida JUMBF, assinatura, confiança, manifesto remoto ou vínculo criptográfico. Bloqueia qualquer processamento se encontrar esses contêineres, pois não pode certificar a preservação da assinatura. “Nenhum contêiner reconhecido” não significa ausência de toda procedência.

**Rotulagem nas redes:** não há integração de publicação nem estimativa de risco. O relatório contém campos vazios para registrar observações externas. Não foram feitos testes reais em Instagram, Facebook ou com detector SynthID.

**Conversão:** recodifica pixels e pode mudar cores, perfis, qualidade e metadados; não promete fidelidade idêntica. JPEG elimina transparência sobre fundo branco. Animações, TIFF, AVIF e formatos diferentes dos três iniciais não são processados.

**Cobertura:** o parser é deliberadamente limitado. Não é perícia forense, antivírus ou validação completa de todos os aspectos de cada codec. A interface também exige decodificação bem-sucedida pelo navegador. A CLI faz somente validação estrutural. Categorias desconhecidas permanecem no arquivo. Metadados podem existir em estruturas não reconhecidas ou no próprio conteúdo visual.

**Arquivos pessoais:** os relatórios podem incluir nomes de arquivos e valores originais de metadados; mantenha-os privados. A aplicação não altera o arquivo original.

## Testes

```bash
npm test
npm run build
node scripts/prepare-decoder-check.mjs
python3 scripts/verify-pixels.py
```

Somente o último comando precisa de Pillow. As amostras sintéticas já estão incluídas. Para regenerá-las: `python3 scripts/make-fixtures.py` com Pillow instalado. A regeneração não é necessária para usar a aplicação.

```bash
node scripts/cli.mjs inspect test/fixtures/metadata.jpg
node scripts/cli.mjs clean test/fixtures/metadata.jpg results/limpa.jpg EXIF
```

Crie a pasta de saída antes do último comando. A saída estruturada da CLI pode ser redirecionada a um relatório JSON pelo shell.

## GitHub e Cloudflare Pages

Repositório: https://github.com/anuncieja/imageclean . O repositório foi criado como público pelo proprietário. Não inclua imagens pessoais em commits: `samples/` e `results/` são ignoradas.

O workflow `.github/workflows/ci.yml` executa testes, build e comparação independente de pixels a cada push/PR. Consulte os resultados na aba **Actions** e o histórico de validação em `docs/VALIDACAO.md`.

No Cloudflare Pages: conectar o repositório, escolher **Framework: None**, comando `npm run build`, pasta de saída `dist`. Não requer Functions, banco ou variáveis secretas nesta versão. A aplicação também funciona em um subdiretório, pois seus caminhos são relativos. O arquivo `_headers` aplica as restrições de recursos no Pages.

## Próximas etapas de pesquisa

1. Obter amostras autorizadas com origem conhecida e separar conjunto de desenvolvimento do conjunto de avaliação.
2. Validar acesso a uma ferramenta oficial de verificação de SynthID para imagens; não inventar uma API.
3. Adicionar validação C2PA com ferramenta compatível, antes de oferecer qualquer processamento desses arquivos.
4. Registrar observações de publicação, plataforma, conta, data e evidência. Não automatizar publicações sem autorização específica.
5. Avaliar qualidade e eventuais mudanças de detecção; não divulgar percentual sem metodologia e amostra.

## Referências de formato

- https://developers.google.com/speed/webp/docs/riff_container
- https://www.w3.org/TR/png/
- https://spec.c2pa.org/specifications/specifications/2.1/specs/C2PA_Specification.html

Código próprio entregue para uso pelo proprietário. Sem bibliotecas de produção de terceiros ou ativos pessoais incorporados. Node.js, navegadores, GitHub Actions e ferramentas opcionais de teste possuem licenças/termos próprios. Esta entrega não é auditoria independente de segurança nem revisão jurídica.
