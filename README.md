# User Role Filter — OJS plugin

> Brings back the **role filter** that **OJS 3.4** had on *Users & Roles* and that **OJS 3.5**
> replaced with a single free-text search box.
> Developed and maintained by **[OJSBR](https://ojsbr.com)**.

[![OJS](https://img.shields.io/badge/OJS-3.5-brightgreen)](https://pkp.sfu.ca/ojs/)
[![Version](https://img.shields.io/badge/version-1.0.0.1-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OJS 3.5](https://github.com/OJSBR/ojsbrUserRoleFilter/releases/download/1.0.0.1/ojsbrUserRoleFilter-1.0.0.1.tar.gz) — or browse all [Releases](../../releases).

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

Adds a **Role** selector next to the native search box on *Users & Roles*. Choosing a role lists
only the users actually enrolled in it. The native search keeps working, and both combine.

Filtering happens **on the server**, not in the browser: the full result set is filtered, not just
the page you are looking at.

## How it works (technical)

Three hooks, because the obvious route does not work:

| Hook | Role |
|---|---|
| `TemplateManager::display` | injects the journal's role list and loads the script, on that screen only |
| `API::users::params` | reads `ojsbrUserGroupIds` and validates it against the journal's own groups |
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

## Consistent counts

Rather than fetching separately and pushing rows into the store — which leaves the header count and
the pagination footer stale, because they come from a computed the store does not expose for
writing — the plugin **intercepts the application own fetch** and appends the filter parameter. The
native OJS flow then produces items, count and pagination together, so everything agrees.

The interception is narrow: only the `/api/v1/users` listing, never `/api/v1/users/123` or any
other endpoint, and it is wrapped so it can never block the original request.

## Compatibility & branches

| OJS/OPS | Branch | Release |
|---|---|---|
| 3.5.x | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 1.0.0.1 |

Requires PHP 8.2+.

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
| `TemplateManager::display` | injeta a lista de papéis da revista e carrega o script, só nessa tela |
| `API::users::params` | lê `ojsbrUserGroupIds` e valida contra os grupos da própria revista |
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

### Contadores coerentes

Em vez de buscar por fora e empurrar linhas para o store — o que deixa o contador do cabeçalho e o
rodapé de paginação defasados, porque vêm de um computed que o store não expõe para escrita —, o
plugin **intercepta o fetch da própria aplicação** e acrescenta o parâmetro do filtro. O fluxo
nativo do OJS então produz itens, contagem e paginação juntos, e tudo fica coerente.

A interceptação é estreita: apenas a listagem `/api/v1/users`, nunca `/api/v1/users/123` nem outro
endpoint, e é protegida para jamais impedir a requisição original.

### Compatibilidade e branches

| OJS/OPS | Branch | Release |
|---|---|---|
| 3.5.x | [`stable-3_5_0`](../../tree/stable-3_5_0) *(padrão)* | 1.0.0.1 |

Requer PHP 8.2+.

### Instalação

Painel → Configurações → Website → Plugins → Enviar um novo plugin, ou descompacte em
`plugins/generic/` e habilite em Plugins Instalados. Não requer configuração.

### Licença

GNU GPL v3 — veja [LICENSE](LICENSE).
