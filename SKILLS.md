---
name: criar-plugin-stream-dspace
description: Criar e manter plugin WordPress que descobre coleções DSpace por OAI-PMH, importa metadados e mídias, apresenta vídeos e coleções via shortcodes e recebe atualizações pelo GitHub. Usar ao implementar, testar ou distribuir esse projeto.
---

# Projeto sugerido: MIDAS

**MIDAS — Mídias Digitais de Acervos e Sistemas**. Nome provisório do plugin: `midas-dspace`; confirmar disponibilidade de nome antes de publicar. Repositório oficial do código: GitHub, em `OWNER/REPO` até o usuário informar a URL.

## Objetivo e interfaces

Construir plugin instalável de WordPress, independente do tema, para cadastrar um ou mais repositórios DSpace, descobrir coleções, escolher as coleções publicadas, importar registros e indexar metadados, imagens, vídeos e PDFs públicos. Registrar exatamente `[streamVideo]` e `[streamController collection="[1,2,3]"]`; aceitar também `collection="1,2,3"`. Os números representam IDs locais estáveis de coleções, mapeados para `setSpec` OAI. `streamController` exibe/navega apenas as coleções autorizadas; `streamVideo` mostra catálogo de vídeos, busca, paginação, metadados e player HTML5 com fallback para a URL original. Permitir filtro opcional por coleção e integração dos dois shortcodes na mesma página por parâmetro de URL namespaced, sem depender da ordem de renderização. Mostrar também visualização de imagens e links para PDFs.

## Administração e sincronização

- Criar telas Repositórios, Coleções, Sincronização/Logs e Configurações. Configurar URL OAI-PMH, nome, eventual API REST, frequência de atualização, modo de mídia (links remotos por padrão; cópia local opcional). Testar endpoint com `Identify` e `ListMetadataFormats`.
- Descobrir conjuntos com `ListSets`, percorrendo todos os `resumptionToken`. Preservar `setSpec`, `setName` e distinguir conjuntos técnicos, comunidades e coleções conforme dados disponíveis. Permitir seleção múltipla.
- Importar `ListRecords` por `set=<setSpec>` e formato suportado, com todos os tokens, lotes, retry/backoff e checkpoint. Executar em background por Action Scheduler quando disponível, ou WP-Cron/fila própria; disponibilizar WP-CLI para acervos grandes. Fazer upsert idempotente pelo par repositório + identificador OAI. Sincronizar incrementalmente por datestamp/from quando o servidor permitir; reiniciar janela temporal quando token expirar. Tratar registros `deleted`, mudanças de coleção e falhas temporárias sem exclusão acidental.
- Indexar identificadores OAI/Handle, título, autor, data, resumo, assuntos, idioma, licença, URL de origem e metadados originais pertinentes. Detectar múltiplas mídias por MIME/bundle. `oai_dc` geralmente fornece metadados, não os bitstreams: negociar formatos ricos anunciados, como METS/ORE/XOAI, ou consultar API REST pública do DSpace conforme versão. Se não houver URL pública verificável, importar os metadados e marcar mídia indisponível; nunca fabricar URL. Respeitar permissões e direitos.
- Preferir tabelas próprias para repositórios, coleções, itens, relações item-coleção, arquivos e checkpoints em bases volumosas; indexar identificadores e campos de busca. Versionar esquema e migrações. Permitir manter ou apagar dados na desinstalação por opção explícita.

## Estrutura recomendada

```text
midas-dspace/
  midas-dspace.php
  includes/Admin/ includes/Oai/ includes/DSpace/
  includes/Sync/ includes/Storage/ includes/Frontend/
  includes/Updates/
  assets/css/ assets/js/
  tests/ fixtures/
  readme.txt
  .github/workflows/release.yml
```
