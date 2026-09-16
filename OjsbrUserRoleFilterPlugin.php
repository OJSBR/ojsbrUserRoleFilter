<?php

/**
 * @file plugins/generic/ojsbrUserRoleFilter/OjsbrUserRoleFilterPlugin.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OjsbrUserRoleFilterPlugin
 *
 * @brief Brings the role filter back to the Users & Roles screen.
 *
 * OJS 3.4 had a role selector there. OJS 3.5 replaced it with a single free-text
 * box that matches %word% across username, e-mail, given and family name,
 * preferred public name, affiliation, biography, ORCID, reviewing interests and
 * the role name (PKP\user\Collector::buildSearchFilter). Typing "manager" then
 * also returns everyone with "manager" in their affiliation.
 *
 * Filtering by roleIds — the only filter api/v1/users whitelists — is too coarse:
 * "Journal manager" and "Journal editor" are both role 16. This plugin filters by
 * user group, reaching the query through the User::Collector hook.
 *
 * The screen is a compiled Vue page: the store that lists the users exposes its
 * search phrase and its page, but not the query it sends. So the selector this
 * plugin adds records the chosen group in a session cookie of its own and asks
 * the store to fetch again; the request carries the cookie, and this plugin reads
 * it server-side. Nothing of the core — neither PHP nor the compiled bundle — is
 * replaced or patched.
 *
 * @see https://github.com/pkp/pkp-lib/issues/11474
 * @see https://github.com/pkp/pkp-lib/issues/11792
 */

namespace APP\plugins\generic\ojsbrUserRoleFilter;

use APP\core\Application;
use APP\template\TemplateManager;
use PKP\plugins\Hook;
use PKP\userGroup\UserGroup;

class OjsbrUserRoleFilterPlugin extends \PKP\plugins\GenericPlugin
{
    /** Name of the cookie the selector writes and this plugin reads. */
    public const COOKIE = 'ojsbrUserGroupIds';

    /** The screen this plugin adds the selector to. */
    public const TEMPLATE = 'management/access.tpl';

    /** Group ids asked for by the current request, already validated. */
    private array $requestedGroupIds = [];

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }
        if ($this->getEnabled($mainContextId)) {
            Hook::add('TemplateManager::display', $this->addSelector(...));
            Hook::add('API::users::params', $this->readRequestedGroups(...));
            Hook::add('User::Collector', $this->filterQuery(...));
        }
        return true;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.generic.ojsbrUserRoleFilter.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.generic.ojsbrUserRoleFilter.description');
    }

    /**
     * Hook API::users::params — reads the cookie the selector writes and keeps
     * only groups that belong to this journal, so it cannot reach into another
     * context.
     *
     * The request here is the API one (Illuminate\Http\Request), which carries
     * the context as an attribute and the cookies unmodified.
     *
     * @param array $args [&$params, $request]
     */
    public function readRequestedGroups(string $hookName, array $args): bool
    {
        $request = $args[1];
        $this->requestedGroupIds = [];

        $context = $request->attributes->get('context');
        if (!$context) {
            return Hook::CONTINUE;
        }

        $ids = self::parseGroupIds($request->cookies->get(self::COOKIE));
        if (!$ids) {
            return Hook::CONTINUE;
        }

        $allowed = UserGroup::withContextIds([$context->getId()])->get()
            ->map(fn ($group) => (int) $group->id)
            ->all();
        $this->requestedGroupIds = array_values(array_intersect($ids, $allowed));

        return Hook::CONTINUE;
    }

    /**
     * The group ids of a cookie value: a comma separated list of positive
     * integers, anything else discarded.
     *
     * @return int[]
     */
    public static function parseGroupIds(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }
        $ids = array_map(intval(...), explode(',', $value));
        return array_values(array_unique(array_filter($ids, fn (int $id) => $id > 0)));
    }

    /**
     * Hook User::Collector — adds the group restriction onto the query the
     * collector built. getMany() assembles a fixed chain and ignores unknown
     * parameters, so this is where a plugin can add a filter of its own.
     *
     * @param array $args [$query, $collector]
     */
    public function filterQuery(string $hookName, array $args): bool
    {
        if (!$this->requestedGroupIds) {
            return Hook::CONTINUE;
        }

        $query = $args[0];
        $ids = $this->requestedGroupIds;

        $query->whereExists(function ($subQuery) use ($ids) {
            $subQuery->from('user_user_groups as ojsbr_uug')
                ->whereColumn('ojsbr_uug.user_id', '=', 'u.user_id')
                ->whereIn('ojsbr_uug.user_group_id', $ids);
        });

        return Hook::CONTINUE;
    }

    /**
     * Hook TemplateManager::display — loads the selector on the Users & Roles
     * screen only.
     *
     * @param array $args [$templateMgr, &$template]
     */
    public function addSelector(string $hookName, array $args): bool
    {
        $templateMgr = $args[0];
        $template = (string) ($args[1] ?? '');

        if (!str_contains($template, self::TEMPLATE)) {
            return Hook::CONTINUE;
        }

        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context) {
            return Hook::CONTINUE;
        }

        $data = json_encode([
            'cookie' => self::COOKIE,
            'roles' => $this->contextUserGroups($context->getId()),
            'i18n' => [
                'all' => __('plugins.generic.ojsbrUserRoleFilter.allRoles'),
                'label' => __('plugins.generic.ojsbrUserRoleFilter.label'),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $assetsUrl = $request->getBaseUrl() . '/' . $this->getPluginPath();

        $templateMgr->addStyleSheet(
            'ojsbrUserRoleFilter',
            $assetsUrl . '/css/roleFilter.css',
            ['contexts' => ['backend']]
        );
        $templateMgr->addJavaScript(
            'ojsbrUserRoleFilterData',
            "window.ojsbrUserRoleFilter = {$data};",
            ['inline' => true, 'contexts' => ['backend'], 'priority' => TemplateManager::STYLE_SEQUENCE_LATE]
        );
        $templateMgr->addJavaScript(
            'ojsbrUserRoleFilter',
            $assetsUrl . '/js/roleFilter.js',
            ['contexts' => ['backend'], 'priority' => TemplateManager::STYLE_SEQUENCE_LAST]
        );

        return Hook::CONTINUE;
    }

    /**
     * The user groups of the journal, by their name in the current language.
     *
     * @return array<array{id: int, name: string}>
     */
    public function contextUserGroups(int $contextId): array
    {
        $groups = [];
        foreach (UserGroup::withContextIds([$contextId])->get() as $group) {
            $groups[] = ['id' => (int) $group->id, 'name' => (string) $group->getLocalizedData('name')];
        }
        usort($groups, fn (array $a, array $b) => strcoll($a['name'], $b['name']));

        return $groups;
    }
}
