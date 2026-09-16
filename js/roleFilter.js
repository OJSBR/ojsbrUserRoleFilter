/**
 * @file plugins/generic/ojsbrUserRoleFilter/js/roleFilter.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * The role selector of the Users & Roles screen.
 *
 * The page is a compiled Vue application: pkp.registry.registerComponent and
 * pkp.registry.storeExtendFn are the supported way in — the component is added
 * to the items above the table, next to the search box. The store does not
 * expose the query it sends, so the chosen group is written to a session cookie
 * the plugin reads server-side, and the store is asked to fetch again. No
 * request and no function of the application is replaced.
 */
(function () {
	'use strict';

	var config = window.ojsbrUserRoleFilter;
	if (!config || !window.pkp || !pkp.registry) {
		return;
	}

	/** Session cookie, valid for this journal's path only. */
	function remember(groupId) {
		document.cookie = config.cookie + '=' + encodeURIComponent(groupId || '')
			+ '; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
	}

	/**
	 * Asks the store to fetch again. It watches [currentPage, searchPhrase], so
	 * going back to the first page is enough when the reader is not on it; on the
	 * first page a trailing space changes the value without changing the result,
	 * because the collector trims the phrase and splits it on whitespace.
	 */
	function reload() {
		var store = pkp.registry.getPiniaStore('userAccessManager');
		if (!store) {
			return;
		}
		if (store.currentPage !== 1) {
			store.setCurrentPage(1);
			return;
		}
		var phrase = store.searchPhrase || '';
		store.setSearchPhrase(/\s$/.test(phrase) ? phrase.replace(/\s+$/, '') : phrase + ' ');
	}

	pkp.registry.registerComponent('OjsbrUserRoleFilter', {
		name: 'OjsbrUserRoleFilter',
		template:
			'<label class="ojsbr-role-filter">' +
			'  <span class="ojsbr-role-filter__label">{{ label }}</span>' +
			'  <select v-model="groupId" @change="changed" class="ojsbr-role-filter__select">' +
			'    <option value="">{{ allRoles }}</option>' +
			'    <option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option>' +
			'  </select>' +
			'</label>',
		data: function () {
			return {
				groupId: '',
				roles: config.roles || [],
				allRoles: (config.i18n && config.i18n.all) || 'All roles',
				label: (config.i18n && config.i18n.label) || 'Role'
			};
		},
		mounted: function () {
			// The screen opens unfiltered, whatever an earlier visit left behind.
			remember('');
		},
		methods: {
			changed: function () {
				remember(this.groupId);
				reload();
			}
		}
	});

	// The extender hands over the original function or the list it returned:
	// both are accepted, so the native search box is never lost.
	pkp.registry.storeExtendFn('userAccessManager', 'getTopItems', function (original, args) {
		var base = (typeof original === 'function') ? original(args) : original;
		var items = Array.isArray(base) ? base.slice() : [];
		items.push({component: 'OjsbrUserRoleFilter', props: {}});
		return items;
	});
})();
