/**
 * OJSBR 2026 — devolve o filtro por papel na tela Usuários e Papéis do OJS 3.5.
 *
 * Abordagem: em vez de buscar por fora e empurrar dados para dentro do store
 * (o que deixava contador e paginação defasados, porque eles vêm de um computed
 * que o store não expõe para escrita), interceptamos o fetch da própria aplicação
 * e acrescentamos o parâmetro do filtro. O fluxo nativo do OJS então cuida de
 * itens, contagem e paginação — tudo coerente, sem duplicar lógica.
 *
 * O lado PHP do plugin lê `ojsbrUserGroupIds` no hook API::users::params e aplica
 * o filtro na consulta pelo hook User::Collector.
 */
(function () {
	'use strict';

	var cfg = window.ojsbrUserRoleFilter;
	if (!cfg || !window.pkp || !pkp.registry) { return; }

	var estado = { roleId: '' };

	/** Só a listagem de usuários; nunca /users/123 nem outros endpoints. */
	function ehListagemDeUsuarios(url) {
		if (!url) { return false; }
		var semQuery = url.split('?')[0];
		return /\/api\/v1\/users\/?$/.test(semQuery);
	}

	function comFiltro(url) {
		if (!estado.roleId || !ehListagemDeUsuarios(url) || url.indexOf('ojsbrUserGroupIds') !== -1) {
			return url;
		}
		return url + (url.indexOf('?') === -1 ? '?' : '&')
			+ 'ojsbrUserGroupIds=' + encodeURIComponent(estado.roleId);
	}

	var fetchOriginal = window.fetch;
	window.fetch = function (entrada, opcoes) {
		try {
			if (typeof entrada === 'string') {
				entrada = comFiltro(entrada);
			} else if (entrada && entrada.url) {
				var nova = comFiltro(entrada.url);
				if (nova !== entrada.url) { entrada = new Request(nova, entrada); }
			}
		} catch (e) { /* nunca impedir a requisição original */ }
		return fetchOriginal.apply(this, arguments.length > 1 ? [entrada, opcoes] : [entrada]);
	};

	/**
	 * Força o store a refazer a busca. Ele observa [currentPage, searchPhrase];
	 * alternar um espaço no fim muda o valor (dispara) sem mudar o resultado,
	 * porque o Collector faz trim e quebra por \s+.
	 */
	function recarregar() {
		var s = pkp.registry.getPiniaStore('userAccessManager');
		if (!s) { return; }
		if (s.currentPage !== 1) { s.setCurrentPage(1); return; }   // já dispara sozinho
		var atual = s.searchPhrase || '';
		s.setSearchPhrase(/\s$/.test(atual) ? atual.replace(/\s+$/, '') : atual + ' ');
	}

	pkp.registry.registerComponent('OjsbrUserRoleFilter', {
		name: 'OjsbrUserRoleFilter',
		template:
			'<label class="ojsbr-role-filter">' +
			'  <span class="ojsbr-role-filter__label">{{ rotulo }}</span>' +
			'  <select v-model="roleId" @change="mudou" class="ojsbr-role-filter__select">' +
			'    <option value="">{{ todos }}</option>' +
			'    <option v-for="p in papeis" :key="p.id" :value="p.id">{{ p.name }}</option>' +
			'  </select>' +
			'</label>',
		data: function () {
			return {
				roleId: estado.roleId,
				papeis: cfg.roles || [],
				todos: (cfg.i18n && cfg.i18n.all) || 'Todos os papéis',
				rotulo: (cfg.i18n && cfg.i18n.label) || 'Papel'
			};
		},
		methods: {
			mudou: function () {
				estado.roleId = this.roleId;
				recarregar();
			}
		}
	});

	// O extender pode entregar a função original OU já o resultado dela.
	// Aceitamos os dois para não perder a busca nativa (UserAccessManagerActionSearch).
	pkp.registry.storeExtendFn('userAccessManager', 'getTopItems', function (original, args) {
		var base = (typeof original === 'function') ? original(args) : original;
		var itens = Array.isArray(base) ? base.slice() : [];
		itens.push({ component: 'OjsbrUserRoleFilter', props: {} });
		return itens;
	});
})();
