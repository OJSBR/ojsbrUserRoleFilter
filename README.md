# User Role Filter — OJS and OMP plugin

> Brings back the **role filter** that **OJS 3.4** had on *Users & Roles* and that **OJS 3.5**
> replaced with a single free-text search box.
> Developed and maintained by **[OJSBR](https://ojsbr.com)**.

[![OJS](https://img.shields.io/badge/OJS-3.5-brightgreen)](https://pkp.sfu.ca/ojs/)
[![OMP](https://img.shields.io/badge/OMP-3.5-brightgreen)](https://pkp.sfu.ca/omp/)
[![Version](https://img.shields.io/badge/version-1.0.1.0-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OJS / OMP 3.5](https://github.com/OJSBR/ojsbrUserRoleFilter/releases/download/1.0.1.0/ojsbrUserRoleFilter-1.0.1.0.tar.gz) — or browse all [Releases](../../releases).

## Why this plugin exists

In OJS 3.4 you could pick a role and list only those users. OJS 3.5 dropped that control — the
change came with the GDPR rework of the screen — and left a single search box whose placeholder
invites you to type a role name.

That box does not filter by role. It matches `%word%`, in **any position**, with `OR` across:

`username` · `email` · `givenName` · `familyName` · `preferredPublicName` ·
**`affiliation`** · **`biography`** · `orcid` · **reviewing interests** · **role name**

(see `PKP\user\Collector::buildSearchFilter`)

So in a university portal, typing *manager* returns every account carrying "manager" in its
**affiliation** — "IT Manager", "Library Manager" — none of whom are journal managers. The list
becomes unusable exactly where it matters most.

PKP is aware. On [pkp-lib#11792](https://github.com/pkp/pkp-lib/issues/11792) their lead developer
wrote that the role filter *"was being broken by performance issues"* and that they are
*"speaking about moving this to a dropdown filter approach to relieve demand from the fulltext
search."* Until that lands, this plugin provides it.

## What it does

Adds a **Role** selector next to the native search box on *Users & Roles*, in OJS and in OMP.
Choosing a role lists only the users actually enrolled in it. The native search keeps working, and both combine.

Filtering happens **on the server**, not in the browser: the full result set is filtered, not just
the page you are looking at.

## How it works (technical)

Three hooks, because the obvious route does not work:

| Hook | Role |
|---|---|
| `TemplateManager::display` | injects the role list of the journal or press and loads the script, on that screen only |
| `API::users::params` | reads the `ojsbrUserGroupIds` cookie and validates it against the groups of that journal or press |
| **`User::Collector`** | adds the `whereExists` clause to the query the Collector just built |

The third one is the key. `api/v1/users` only whitelists `roleIds`, which is too coarse — *Journal
manager* and *Journal editor* are both role 16, so a role filter cannot tell them apart. And
`getMany()` assembles a fixed collector chain, ignoring unknown `$params` keys, so adding a
parameter alone changes nothing. `User::Collector` exposes the query builder itself, which is where
a real filter can be added.

On the browser side the component is registered through `pkp.registry.registerComponent` and
attached to the toolbar with `storeExtendFn('userAccessManager', 'getTopItems', …)`.

**Security.** Requested group ids are intersected with the groups that belong to the current
journal. Tampering with the parameter to reach another context returns an empty list, never
someone else's data.

## Consistent counts, nothing of the application replaced

Rather than fetching separately and pushing rows into the store — which leaves the header count and
the pagination footer stale, because they come from a computed the store does not expose for
writing — the plugin lets the application make its own request. The store exposes its search phrase
and its page, but not the query it sends, so the chosen role is recorded in a **session cookie of
the plugin** and the store is asked to fetch again; the request carries the cookie, and the server
side reads it. Items, count and pagination come from the native flow and always agree.

No function of the page is replaced — `window.fetch` included — and no file of the core, PHP or
compiled bundle, is patched. The cookie is read only by this plugin, on the users listing, and its
value is a list of numbers validated against the groups of the current journal or press.

## Compatibility & branches

| Application | Branch | Release |
|---|---|---|
| OJS 3.5.x and OMP 3.5.x | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 1.0.1.0 |

Requires PHP 8.2+.

## Tests

- **PHPUnit** (`tests/`): what the cookie may ask for, that only groups of the current journal or
  press survive, the clause added to the query and its bindings, the screen the selector is loaded
  on, and the assets it loads.
- **Cypress** (`cypress/tests/functional/`): enables the plugin, opens Users & Roles, picks a role
  actually held by a user and checks that every listed user holds it, then goes back to all roles
  and gets the whole list again. Each check fails with the part it covers removed.
- Verified on OJS 3.5.0.3 and OMP 3.5.0.3, each with the whole suite.

Tests are kept in the repository and are not part of the release package.

## AI use

Generative AI (Claude, by Anthropic) was used to write and run tests, improve the code and bring
it in line with PKP standards. Every change is reviewed and tested by OJSBR, which is responsible
for the published releases.

## Installation

Dashboard → Settings → Website → Plugins → Upload A New Plugin, or unpack into `plugins/generic/`
and enable it under Installed Plugins. No configuration is required.

## License

GNU GPL v3 — see [LICENSE](LICENSE).

---

## 🇧🇷 Português

> Repõe o **filtro por papel** que o **OJS 3.4** tinha na tela *Usuários e Papéis* e que o
> **OJS 3.5** substituiu por um único campo de busca em texto livre.
> Desenvolvido e mantido pela **[OJSBR](https://ojsbr.com)**.

### Por que este plugin existe

No OJS 3.4 dava para escolher um papel e listar apenas aqueles usuários. O OJS 3.5 removeu esse
controle — a mudança veio junto com a readequação da tela à LGPD/GDPR — e deixou só um campo de
busca cujo texto de apoio convida a digitar o nome do papel.

Esse campo não filtra por papel. Ele casa `%palavra%`, em **qualquer posição**, com `OR` sobre:

`username` · `email` · `givenName` · `familyName` · `preferredPublicName` ·
**`affiliation`** · **`biography`** · `orcid` · **interesses de avaliação** · **nome do papel**

(ver `PKP\user\Collector::buildSearchFilter`)

Num portal universitário, digitar *gerente* traz toda conta que tenha "gerente" na **afiliação** —
"Gerente de TI", "Gerente da Biblioteca" — e nenhuma delas é gerente de revista. A lista fica
inutilizável justamente onde mais importa.

A PKP sabe do problema. Na [pkp-lib#11792](https://github.com/pkp/pkp-lib/issues/11792) o líder de
desenvolvimento registrou que o filtro por papel *"estava sendo prejudicado por questões de
desempenho"* e que eles *"estão conversando sobre migrar isso para uma abordagem de filtro
suspenso, para aliviar a demanda sobre a busca em texto integral"*. Enquanto isso não sai, este
plugin resolve.

### O que faz

Acrescenta um seletor **Papel** ao lado da busca nativa na tela *Usuários e Papéis*. Escolher um
papel lista somente quem está de fato vinculado a ele. A busca nativa continua funcionando, e as
duas se combinam.

A filtragem acontece **no servidor**, não no navegador: filtra o conjunto inteiro, não apenas a
página exibida.

### Como funciona (técnico)

Três ganchos, porque o caminho óbvio não funciona:

| Gancho | Papel |
|---|---|
| `TemplateManager::display` | injeta a lista de papéis da revista ou editora e carrega o script, só nessa tela |
| `API::users::params` | lê o cookie `ojsbrUserGroupIds` e valida contra os grupos da própria revista ou editora |
| **`User::Collector`** | acrescenta o `whereExists` à consulta recém-montada pelo Collector |

O terceiro é a peça central. A `api/v1/users` só aceita `roleIds`, grosseiro demais — *Gerente da
revista* e *Editor da revista* são ambos papel 16, e o filtro não os separaria. E o `getMany()`
monta uma cadeia fixa, ignorando chaves desconhecidas de `$params`, de modo que acrescentar
parâmetro não mudaria nada. O `User::Collector` expõe o próprio construtor da consulta, e é ali
que um filtro de verdade pode entrar.

No navegador, o componente é registrado por `pkp.registry.registerComponent` e acoplado à barra
com `storeExtendFn('userAccessManager', 'getTopItems', …)`.

**Segurança.** Os ids de grupo pedidos são cruzados com os grupos que pertencem à revista corrente.
Alterar o parâmetro para alcançar outro contexto devolve lista vazia, nunca dado alheio.

### Contadores coerentes, sem substituir nada da aplicação

Em vez de buscar por fora e empurrar linhas para o store — o que deixa o contador do cabeçalho e o
rodapé de paginação defasados, porque vêm de um computed que o store não expõe para escrita —, o
plugin deixa a própria aplicação fazer a requisição. O store expõe a frase de busca e a página, mas
não a consulta que envia; então o papel escolhido é registrado num **cookie de sessão do plugin** e
o store é solicitado a buscar de novo. A requisição leva o cookie, e o lado servidor o lê. Itens,
contagem e paginação vêm do fluxo nativo e ficam sempre coerentes.

Nenhuma função da página é substituída — `window.fetch` inclusive — e nenhum arquivo do núcleo, PHP
ou pacote compilado, é alterado. O cookie só é lido por este plugin, na listagem de usuários, e seu
valor é uma lista de números validada contra os grupos da revista ou editora corrente.

### Compatibilidade e branches

| Aplicação | Branch | Release |
|---|---|---|
| OJS 3.5.x e OMP 3.5.x | [`stable-3_5_0`](../../tree/stable-3_5_0) *(padrão)* | 1.0.1.0 |

Requer PHP 8.2+.

### Testes

- **PHPUnit** (`tests/`): o que o cookie pode pedir, que só sobrevivem grupos da revista ou editora
  corrente, a cláusula acrescentada à consulta e seus bindings, a tela em que o seletor é carregado
  e os arquivos que ele carrega.
- **Cypress** (`cypress/tests/functional/`): liga o plugin, abre Usuários e Papéis, escolhe um papel
  realmente atribuído a alguém e confere que todo usuário listado o tem; depois volta para todos os
  papéis e recebe a lista inteira. Cada verificação reprova com a parte que ela cobre removida.
- Verificado no OJS 3.5.0.3 e no OMP 3.5.0.3, com a suíte inteira em cada um.

Os testes ficam no repositório e não fazem parte do pacote da release.

### Uso de IA

Foi usada IA generativa (Claude, da Anthropic) para escrever e rodar testes, melhorar o código e
alinhá-lo aos padrões da PKP. Toda mudança é revisada e testada pela OJSBR, que responde pelas
releases publicadas.

### Instalação

Painel → Configurações → Website → Plugins → Enviar um novo plugin, ou descompacte em
`plugins/generic/` e habilite em Plugins Instalados. Não requer configuração.

### Licença

GNU GPL v3 — veja [LICENSE](LICENSE).
