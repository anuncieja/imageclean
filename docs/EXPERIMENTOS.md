# Protocolo experimental inicial

Cada experimento deve registrar: identificador, origem conhecida, versão do gerador se informada, data de obtenção, hash original, configuração de processamento, hash de saída, dimensões, peso e evidências de qualidade.

## Conjunto

Começar com 10–20 arquivos autorizados de origens diferentes, incluindo fotografias reais e imagens geradas. Este número é exploratório, não sustenta estimativa universal. Não misturar fotos reais aos resultados de imagens geradas ao calcular taxas. Manter um grupo de controle sem processamento. Separar novas imagens de avaliação das usadas para escolher configurações.

## Critérios

- Uma categoria removida precisa desaparecer da inspeção posterior suportada. Isso não equivale a eliminar toda informação identificadora.
- Para limpeza sem recodificação, comparar bytes de imagem e pixels decodificados independentemente.
- Para conversão, comparar tamanho, orientação, transparência, cores, textos, rostos e identidade de produtos. O avaliador rejeita mudanças inaceitáveis.
- Registro de detector deve identificar ferramenta, cobertura, resultado, data e arquivo exato. “Não detectado” não comprova remoção completa.
- Sem detector disponível, registrar “não verificado”. Sem publicação observada, registrar “não testado”.
- Testes de publicação exigem autorização específica para as contas e postagens; o desenvolvimento do aplicativo não autoriza publicar nas redes do usuário.
- Uma observação sem rótulo não garante ausência futura; acompanhar alterações no estado da mesma postagem quando fizer parte do estudo.

## Estado inicial

Não há resultados SynthID ou de rotulagem nas redes nesta entrega. Os testes sintéticos incluídos verificam engenharia de arquivos, não evasão ou taxa de sucesso em plataformas.
