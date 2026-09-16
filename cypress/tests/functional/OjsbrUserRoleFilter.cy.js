/**
 * @file cypress/tests/functional/OjsbrUserRoleFilter.cy.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Functional tests: the role selector on the Users & Roles screen.
 *
 * What these guard is what the manager sees: picking a role lists the users who
 * hold it and nobody else, and going back to "all roles" lists everyone again.
 *
 * Parameters (--env): contextPath, adminUser, adminPassword (captcha on login
 * must be off for the run). The defaults match the data set of PKP's continuous
 * integration; the first test enables the plugin when it is off. Nothing is
 * changed on the server: the choice lives in a cookie of the browser.
 */

describe('User Role Filter plugin', function() {
	const contextPath = Cypress.env('contextPath') || 'publicknowledge';
	const adminUser = Cypress.env('adminUser') || 'admin';
	const adminPassword = Cypress.env('adminPassword') || 'admin';

	const rowName = 'ojsbruserrolefilterplugin';
	const select = 'select.ojsbr-role-filter__select';

	// ---- OJSBR spec helpers (padrão v2): work on OJS/OMP 3.3, 3.4 and 3.5 and in PKP's CI ----

	const pageUrl = (path) => '/index.php/' + contextPath + (path ? '/' + path : '');

	// Same as PKP's cy.waitJQuery(), which the support files of OJS 3.3 test sites may lack.
	// The Plugins tab can keep requests open for a while (the plugin gallery), hence the timeout.
	// jQuery may not be on the page yet when this runs, so the check retries on the window
	// itself instead of on a property that would resolve as undefined.
	const waitJQuery = () => cy.window({timeout: 60000}).should((win) => {
		expect(win.jQuery && win.jQuery.active, 'pending jQuery requests').to.eq(0);
	});

	// Requests carry the browser's User-Agent: OJS 3.3 drops a session whose agent changes.
	const request = (options) => cy.window({log: false}).then((win) => cy.request(Object.assign(
		typeof options === 'string' ? {url: options} : options,
		{headers: Object.assign({'User-Agent': win.navigator.userAgent}, (typeof options === 'string' ? {} : options.headers) || {})}
	)));

	// Signs in through requests (the login page can re-render while it is typed into), then
	// falls back to the form when the session did not stick (OJS 3.3 cookie handling).
	const login = (username, password) => {
		cy.clearCookies();
		request(pageUrl('login')).then((response) => {
			const token = /name="csrfToken" value="([^"]+)"/.exec(response.body)[1];
			// The form posts to the URL with the language: a redirect would turn the POST into a GET.
			const action = /<form[^>]*id="login"[^>]*action="([^"]+)"/.exec(response.body)[1];
			request({method: 'POST', url: action, form: true, body: {csrfToken: token, username: username, password: password}, log: false});
		});
		cy.visit(pageUrl('submissions') + '?reload=' + Date.now());
		cy.get('body').then(($body) => {
			if ($body.find('form#login').length) {
				cy.get('form#login input[name="username"]').type(username, {delay: 0});
				cy.get('form#login input[name="password"]').type(password, {delay: 0, log: false});
				cy.get('form#login').submit();
				cy.get('form#login', {timeout: 30000}).should('not.exist');
			}
		});
	};

	// REST API calls made from the page itself, so they carry the browser's own session.
	const api = (path, options = {}) => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(path, Object.assign({credentials: 'same-origin'}, options)).then((response) => {
			if (!response.ok) {
				return response.text().then((text) => {
					throw new Error(path + ' answered ' + response.status + ': ' + text.slice(0, 300));
				});
			}
			return response.json();
		}),
		{log: false, timeout: 30000}
	));

	// The website settings page on its Plugins tab (a new query string forces a load). Load it
	// once per test: loading it again while its plugin gallery request is pending stalls the
	// web server of PKP's CI; API calls and settings modals work on the page already open.
	const openPluginsTab = () => {
		cy.visit(pageUrl('management/settings/website') + '?reload=' + Date.now() + '#plugins');
		cy.get('button[id="plugins-button"]', {timeout: 60000}).click();
		cy.get('button[id="plugins-button"]').should('have.attr', 'aria-selected', 'true');
		waitJQuery();
	};

	// Enables the plugin in the grid when it is off (never turns it off).
	const enablePlugin = (rowName) => {
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]', {timeout: 30000}).then(($checkbox) => {
			if (!$checkbox.is(':checked')) {
				cy.wrap($checkbox).click();
				waitJQuery();
			}
		});
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]').should('be.checked');
	};

	// ---- end of helpers ----

	// The rows of the users table: the screen also has the invitations one, so the table is
	// reached from the selector itself — the closest ancestor that holds a single table.
	const userRows = () => cy.get(select, {timeout: 60000})
		.parents()
		.filter((index, element) => element.querySelectorAll('table').length === 1)
		.first()
		.find('tbody tr');

	// The Users & Roles screen, with the table of users already listed.
	const openUsers = () => {
		cy.visit(pageUrl('management/settings/access') + '?reload=' + Date.now());
		cy.get(select, {timeout: 60000}).should('exist');
		userRows().should('have.length.at.least', 1);
	};

	// Picks a role by the text of its option (or "" for all roles) and waits for the list the
	// application fetches again, instead of a fixed pause.
	const chooseRole = (label) => {
		cy.intercept('GET', '**/api/v1/users?*').as('userList');
		cy.get(select).select(label);
		cy.wait('@userList', {timeout: 60000});
	};

	// The roles a row shows, as one string.
	const rolesOf = ($row) => $row.find('td').eq(2).text();

	it('Enables the plugin and shows the selector with the roles of the journal', function() {
		login(adminUser, adminPassword);
		openPluginsTab();
		enablePlugin(rowName);

		openUsers();
		// One option for every user group of the journal, plus "all roles".
		api(pageUrl('api/v1/users?count=1')).then(() => {
			cy.get(select + ' option').should('have.length.at.least', 2);
			cy.get(select).should('have.value', '');
		});
	});

	it('Lists only the users who hold the chosen role, and everyone again afterwards', function() {
		login(adminUser, adminPassword);
		openUsers();

		// A role really held by somebody: the detail of the first user lists its groups.
		api(pageUrl('api/v1/users?count=1')).then((users) => {
			expect(users.items, 'users of the journal').to.have.length.at.least(1);
			api(pageUrl('api/v1/users/' + users.items[0].id)).then((user) => {
				const group = (user.groups || [])[0];
				expect(group, 'a role of the first user').to.not.be.undefined;
				const label = typeof group.name === 'string' ? group.name : Object.values(group.name).find((value) => value);

				userRows().then(($all) => {
					const everyone = $all.length;

					chooseRole(label);
					userRows().should(($filtered) => {
						expect($filtered.length, 'users with the role').to.be.at.least(1);
						$filtered.each((index, row) => {
							expect(rolesOf(Cypress.$(row)), 'roles of a listed user').to.contain(label);
						});
					});

					// Back to all roles: the list is the one the screen opened with.
					chooseRole('');
					userRows().should('have.length', everyone);
				});
			});
		});
	});
});
