# MIDAS — Mídias Digitais de Acervos e Sistemas

O **MIDAS** é um plugin WordPress para apresentar acervos de repositórios DSpace em um catálogo de mídias independente do tema. Ele descobre conjuntos por OAI-PMH, importa metadados e permite publicar coleções selecionadas com vídeos, imagens e links para PDFs.

**Versão do código:** `0.1.0`, em desenvolvimento. O nome `midas-dspace` é provisório. O repositório GitHub oficial ainda não foi definido.

## Recursos

- Cadastro de múltiplos repositórios, com endpoint OAI-PMH e API REST pública opcional.
- Descoberta de conjuntos com paginação e IDs locais estáveis.
- Seleção das coleções que podem aparecer no catálogo.
- Importação de título, autores, data, resumo, assuntos, idioma, licença, identificador OAI, URL de origem e metadados originais.
- Player HTML5 com link para o vídeo original, visualização de imagens e links para PDFs.
- Busca por título, autor, resumo e assuntos, com 12 registros por página.
- Sincronização em lotes, checkpoints, tentativas com espera progressiva e logs.
- Execução pelo Action Scheduler, quando disponível, ou pelo WP-Cron; comandos WP-CLI para processamento manual.
- Links remotos por padrão e cópia local opcional.
- Consulta de atualizações em releases de um repositório GitHub configurado pelo administrador.

O catálogo também apresenta registros sem mídia disponível, preservando seus metadados. Arquivos protegidos por autenticação não são importados pelo plugin.

## Requisitos

| Componente | Requisito |
| --- | --- |
| WordPress | 6.4 ou superior, conforme declarado pelo plugin |
| PHP | 8.1 ou superior, com SimpleXML/libxml |
| Banco de dados | MySQL ou MariaDB compatível com WordPress, índices FULLTEXT e `GET_LOCK` |
| Administração | Conta com a permissão `manage_options` |
| Repositório | Endpoint OAI-PMH público e acessível pelo servidor WordPress |
| Rede | Saída HTTP/HTTPS para o repositório e suas mídias; para GitHub quando usadas as atualizações |
| Cópia local | Diretório de uploads gravável e espaço disponível |

Action Scheduler e WP-CLI são opcionais. A instalação do plugin não exige Composer, Node.js ou compilação de assets.

## Instalação

### Pela pasta do plugin

1. Obtenha os arquivos deste projeto.
2. Copie a pasta **`midas-dspace`** para `wp-content/plugins/` da instalação WordPress.
3. Confirme que o arquivo principal está em `wp-content/plugins/midas-dspace/midas-dspace.php`.
4. No painel WordPress, abra **Plugins → Plugins instalados**.
5. Ative **MIDAS — Mídias Digitais de Acervos e Sistemas**.
6. Acesse o menu **MIDAS** para iniciar a configuração.

As tabelas do plugin são criadas na ativação. Não é necessário executar um arquivo SQL manualmente.

### Por arquivo ZIP

Compacte somente a pasta `midas-dspace`, mantendo essa pasta como diretório principal do pacote:

```text
midas-dspace.zip
└── midas-dspace/
    ├── midas-dspace.php
    ├── uninstall.php
    ├── includes/
    └── assets/
```

Na raiz deste projeto, é possível gerar o pacote no PowerShell:

```powershell
Compress-Archive -Path .\midas-dspace -DestinationPath .\midas-dspace.zip
```

Em Linux ou macOS, com o utilitário `zip` disponível:

```bash
zip -r midas-dspace.zip midas-dspace
```

No WordPress, abra **Plugins → Adicionar novo plugin → Enviar plugin**, selecione o ZIP, instale e ative.

Não compacte a raiz inteira do projeto: `.git`, `.test-runtime` e os demais arquivos externos à pasta do plugin não fazem parte da instalação.

## Configuração inicial

### 1. Cadastrar um repositório

Abra **MIDAS → Repositórios** e preencha:

| Campo | Como preencher |
| --- | --- |
| Nome | Nome usado para identificar o acervo no painel |
| URL OAI-PMH | Endpoint de coleta, sem acrescentar `verb=ListRecords` ou outro verbo |
| API REST pública | Opcional; informe a base da API disponibilizada pelo repositório |
| Intervalo em segundos | Padrão de `86400` (24 horas); mínimo aceito de `300` |
| Modo de mídia | Links remotos ou cópia local dos arquivos públicos |

Exemplos ilustrativos — substitua pelos endereços reais da instituição:

```text
OAI-PMH:        https://repositorio.exemplo.org/oai/request
REST DSpace 7+: https://repositorio.exemplo.org/server/api
REST DSpace 5/6: https://repositorio.exemplo.org/rest
```

Clique em **Salvar e testar**. O plugin consulta `Identify` e `ListMetadataFormats` e enfileira a descoberta dos conjuntos. O botão **Testar endpoint e descobrir conjuntos** permite repetir essa operação.

O endpoint OAI de um cadastro existente não pode ser trocado pela interface. Cadastre outro repositório para preservar a identidade dos registros já importados.

### 2. Publicar coleções

1. Acompanhe a descoberta em **MIDAS → Sincronização / Logs**.
2. Abra **MIDAS → Coleções**.
3. Confira o nome, o `setSpec` e o tipo de cada conjunto.
4. Marque as coleções desejadas.
5. Clique em **Salvar seleção e sincronizar**.
6. Anote os IDs locais exibidos na tabela para usar nos shortcodes.

A classificação distingue coleções, comunidades e conjuntos técnicos quando reconhece seus identificadores. Conjuntos classificados como `unknown` precisam ser avaliados pelo administrador antes da publicação.

Desmarcar uma coleção retira sua autorização de exibição e impede novos lotes de importação dessa coleção. Os metadados existentes permanecem armazenados.

### 3. Criar a página do catálogo

No editor WordPress, adicione blocos **Shortcode** com:

```text
[streamController collection="[1,2,3]"]

[streamVideo]
```

Substitua `1,2,3` pelos IDs locais obtidos em **MIDAS → Coleções**. Esses números não são IDs do DSpace nem valores de `setSpec`.

## Uso dos shortcodes

### Catálogo

```text
[streamVideo]
```

Exibe registros das coleções publicadas, respeitando o filtro do controlador presente no conteúdo da página. Inclui busca, paginação, metadados e as mídias disponíveis.

Para restringir diretamente o catálogo:

```text
[streamVideo collection="1,2,3"]
```

### Navegação entre coleções

As duas formas são aceitas no conteúdo de páginas e posts:

```text
[streamController collection="[1,2,3]"]
[streamController collection="1,2,3"]
```

Sem o atributo `collection`, o controlador lista as coleções publicadas:

```text
[streamController]
```

O controlador e o catálogo podem aparecer em qualquer ordem no conteúdo da página. Os filtros usam estes parâmetros de URL:

| Parâmetro | Finalidade |
| --- | --- |
| `midas_collection` | ID local da coleção selecionada |
| `midas_search` | Texto da busca |
| `midas_page` | Número da página de resultados |

Alterar a URL não autoriza a exibição de coleções despublicadas ou fora do escopo do catálogo.

Em templates PHP, prefira a forma sem colchetes internos ao chamar `do_shortcode()` diretamente:

```php
echo do_shortcode('[streamVideo collection="1,2,3"]');
```

Para controladores inseridos dinamicamente por templates ou construtores, informe também `collection` no catálogo. A identificação automática do escopo consulta o conteúdo armazenado da página.

## Sincronização e acompanhamento

A fila usa Action Scheduler quando suas funções estão disponíveis. Caso contrário, agenda execuções pelo WP-Cron. A disponibilidade do agendador e o volume do acervo determinam o tempo de conclusão; salvar o repositório não importa todo o acervo imediatamente.

Em **MIDAS → Sincronização / Logs**, você pode:

- Consultar a operação, o estado, as tentativas e a data do último sucesso em UTC.
- Usar **Executar próximo lote** para processar uma tarefa elegível.
- Usar **Retomar** para reenfileirar uma tarefa preservando seu checkpoint.
- Consultar eventos recentes, com retenção de 30 dias.

| Estado | Significado |
| --- | --- |
| `queued` | Aguardando execução ou nova tentativa |
| `running` | Coleta em andamento, com mais lotes ou páginas |
| `idle` | Coleta concluída; aguardando o próximo intervalo |
| `failed` | Limite de tentativas atingido; verifique os logs antes de retomar |

Os registros são identificados pelo par **repositório + identificador OAI**, evitando duplicação durante reprocessamentos. Tokens expirados provocam a retomada da janela de coleta. Registros marcados como `deleted` pelo OAI deixam de aparecer no catálogo.

Após uma coleta completa, as seguintes usam a data do último sucesso. O código também prevê uma nova coleta completa após 30 dias para reconciliar vínculos de coleção; vínculos ausentes só são removidos quando essa coleta termina com sucesso.

Em sites com pouco tráfego, configure o agendador do servidor para executar as tarefas do WordPress ou utilize WP-CLI. O WP-Cron depende de oportunidades de execução no site.

### WP-CLI

Execute os comandos no diretório da instalação WordPress, com o plugin ativo:

```bash
# Enfileirar descoberta de conjuntos do repositório local 1.
wp midas discover 1

# Enfileirar sincronização da coleção local 3, já publicada.
wp midas sync 3

# Processar um lote elegível.
wp midas run

# Executar até 100 ciclos de processamento.
wp midas run --batches=100
```

Os ciclos respeitam a espera entre tentativas e podem não encontrar tarefas elegíveis. A mensagem de conclusão do comando não garante que todo o acervo tenha sido importado; consulte os logs.

## Mídias e formatos de metadados

O plugin procura os formatos anunciados `mets`, `xoai`, `ore` e `oai_dc`, nessa ordem. Quando utiliza um formato rico e `oai_dc` também está disponível, consulta Dublin Core para complementar os campos descritivos.

O formato `oai_dc` pode fornecer apenas metadados. Para obter arquivos, o MIDAS examina as URLs presentes nos registros e, quando configurada, consulta a API REST pública. Não fabrica endereços de bitstreams.

Na implementação atual, uma mídia precisa responder à verificação HTTP `HEAD` com status `200` e um MIME aceito. São aceitos vídeos, imagens JPEG/PNG/GIF/WebP/AVIF e PDFs. Servidores que bloqueiam `HEAD` ou não informam o MIME corretamente podem resultar em mídia indisponível, mesmo quando o arquivo abre no navegador.

| Modo | Comportamento |
| --- | --- |
| Links remotos | O navegador acessa os arquivos no repositório de origem |
| Cópia local | O plugin tenta importar os arquivos públicos para a Biblioteca de Mídia; mantém o link remoto como alternativa |

A cópia local limita cada arquivo ao menor valor entre o limite de upload do WordPress e **100 MiB**. Tipos recusados pelo WordPress, arquivos maiores ou falhas de cópia podem permanecer como links remotos. A reprodução de vídeo depende dos formatos e codecs suportados pelo navegador.

Verifique as licenças do acervo antes de habilitar cópias locais. A disponibilidade pública de um arquivo não substitui a autorização para redistribuí-lo.

## Atualizações pelo GitHub

Em **MIDAS → Configurações**, informe o repositório público no formato `OWNER/REPO`, substituindo o exemplo pelo proprietário e nome reais.

Para que o código reconheça uma atualização, a release precisa:

- Estar publicada e não ser uma prerelease.
- Ter uma tag como `v0.1.1` ou `0.1.1`, superior à versão instalada.
- Incluir um asset chamado exatamente **`midas-dspace.zip`**, com a estrutura de instalação descrita acima.

O campo pode permanecer vazio durante a instalação inicial. A consulta de releases fica em cache por até uma hora após uma resposta bem-sucedida. O mecanismo disponibiliza a atualização no fluxo do WordPress; não publica releases no GitHub.

Este projeto ainda não inclui um workflow de empacotamento/publicação. A disponibilidade do nome provisório também precisa ser confirmada antes de uma publicação oficial.

## Dados e desinstalação

O plugin usa tabelas com o prefixo do WordPress seguido de `midas_` para repositórios, coleções, itens, vínculos item–coleção, arquivos, checkpoints e logs. A versão do esquema é registrada para controle de instalação e evolução da estrutura.

Por padrão, os dados permanecem no banco ao desinstalar. Para removê-los, marque **Apagar tabelas e cópias locais do MIDAS ao desinstalar** em **MIDAS → Configurações**, salve e depois exclua o plugin pelo painel WordPress.

Essa opção também remove os anexos identificados como cópias criadas pelo MIDAS. Faça backup antes de usá-la. Desativar o plugin interrompe seu agendamento, sem executar a exclusão dos dados.

## Solução de problemas

| Sintoma | O que verificar |
| --- | --- |
| Falha ao salvar o repositório | URL pública do endpoint, resposta de `Identify`, formatos anunciados, certificado TLS e conectividade do servidor WordPress |
| Coleções ainda não aparecem | Estado da tarefa `sets` nos logs e funcionamento do agendador; execute o próximo lote se necessário |
| Catálogo vazio | Coleções publicadas, IDs locais nos shortcodes, filtros da URL e conclusão de ao menos um lote de registros |
| Mídia indisponível | URLs públicas nos metadados, API REST configurada, resposta a `HEAD` e MIME do arquivo |
| Vídeo não reproduz | Codec aceito pelo navegador, acesso ao arquivo e bloqueios do servidor de origem; teste o link do vídeo original |
| Cópia local não aparece | Limite de tamanho, espaço, permissões de uploads e tipos de arquivo aceitos pelo WordPress |
| Fila em `failed` | Mensagem do log; corrija a causa e clique em **Retomar** |
| Atualização não aparece | `OWNER/REPO`, versão da tag, release pública estável, nome do ZIP e cache da consulta |

## Estrutura do projeto

```text
README.md
SKILLS.md
midas-dspace/
  midas-dspace.php
  uninstall.php
  includes/
    Admin/
    Oai/
    DSpace/
    Sync/
    Storage/
    Frontend/
    Updates/
  assets/css/
  fixtures/
  tests/
```

`SKILLS.md` contém a especificação do projeto. Este README descreve os arquivos e comportamentos implementados, sem pressupor que toda a estrutura sugerida na especificação já exista.

## Testes para desenvolvimento

Na raiz do projeto, execute as verificações de protocolo, parser e filtros:

```bash
php midas-dspace/tests/run.php
```

Para os testes de integração, prepare uma instalação WordPress descartável com `wp-config.php` apontando para um banco cujo nome comece com **`midas_test_`**. Use o plugin a partir deste checkout, sem ativar uma segunda cópia nessa instalação:

```bash
php midas-dspace/tests/integration.php /caminho/absoluto/wordpress-de-testes
```

O teste cria ou utiliza essa instalação, limpa as tabelas MIDAS do banco de testes e simula respostas HTTP. **Não execute em um site de produção.** Esses testes não substituem a validação com o endpoint DSpace real da instituição.

## Licença

O cabeçalho do plugin declara **GPL-2.0-or-later**. As licenças dos registros e arquivos dos acervos permanecem as definidas pelos respectivos titulares.
