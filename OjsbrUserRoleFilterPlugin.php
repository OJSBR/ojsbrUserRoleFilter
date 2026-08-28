<?php

/**
 * @file plugins/generic/ojsbrUserRoleFilter/OjsbrUserRoleFilterPlugin.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3.
 *
 * @class OjsbrUserRoleFilterPlugin
 *
 * @brief Brings back the role filter on the Users & Roles screen.
 *
 * OJS 3.4 had a role selector there. OJS 3.5 replaced it with a single free-text
 * box that matches %word% across username, e-mail, given/family name, preferred
 * public name, affiliation, biography, ORCID, reviewing interests AND the role
 * name (PKP\user\Collector::buildSearchFilter). Typing "manager" therefore also
 * returns everyone with "manager" in their affiliation — useless in a university.
 *
 * Filtering by roleIds (the only filter api/v1/users whitelists) is too coarse:
 * "Journal manager" and "Journal editor" are both role 16. So we filter by user
 * GROUP, reaching the query through the User::Collector hook.
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
    /** Group ids requested by the current request, already validated. */
    private array $gruposPedidos = [];

    public function register($category, $path, $mainContextId = null)
    {
        if (parent::register($category, $path, $mainContextId)) {
            if ($this->getEnabled($mainContextId)) {
                Hook::add('TemplateManager::display', $this->injetar(...));
                Hook::add('API::users::params', $this->lerParametro(...));
                Hook::add('User::Collector', $this->filtrarConsulta(...));
            }
            return true;
        }
        return false;
    }

    public function getDisplayName()
    {
        return __('plugins.generic.ojsbrUserRoleFilter.displayName');
    }

    public function getDescription()
    {
        return __('plugins.generic.ojsbrUserRoleFilter.description');
    }

    /**
     * Reads our own query parameter and keeps only groups that really belong to
     * this journal, so it cannot be used to reach into another context.
     *
     * @param array $args [&$params, $request]
     */
    public function lerParametro($hookName, $args)
    {
        $request = $args[1];
        $bruto = $request->query('ojsbrUserGroupIds');

        if ($bruto === null || $bruto === '') {
            return false;
        }

        $ids = array_values(array_filter(array_map(
            intval(...),
            is_array($bruto) ? $bruto : explode(',', (string) $bruto)
        )));
        if (!$ids) {
            return false;
        }

        $context = $request->attributes->get('context');
        if (!$context) {
            return false;
        }

        $permitidos = UserGroup::withContextIds([$context->getId()])->get()
            ->map(fn ($g) => (int) $g->id)
            ->all();

        $this->gruposPedidos = array_values(array_intersect($ids, $permitidos));

        return false;
    }

    /**
     * Adds the group restriction straight onto the query the Collector built.
     * getMany() assembles a fixed chain and ignores unknown $params keys, so this
     * is the only place where a plugin can add a real filter.
     *
     * @param array $args [$query, $collector]
     */
    public function filtrarConsulta($hookName, $args)
    {
        if (!$this->gruposPedidos) {
            return false;
        }

        $query = $args[0];
        $ids = $this->gruposPedidos;

        $query->whereExists(function ($sub) use ($ids) {
            $sub->from('user_user_groups as ojsbr_uug')
                ->whereColumn('ojsbr_uug.user_id', '=', 'u.user_id')
                ->whereIn('ojsbr_uug.user_group_id', $ids);
        });

        return false;
    }

    /**
     * Loads the selector only on the Users & Roles screen.
     */
    public function injetar($hookName, $args)
    {
        $templateMgr = $args[0];
        $template = (string) ($args[1] ?? '');

        if (!str_contains($template, 'management/access.tpl')) {
            return false;
        }

        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context) {
            return false;
        }

        $papeis = [];
        foreach (UserGroup::withContextIds([$context->getId()])->get() as $grupo) {
            $papeis[] = ['id' => (int) $grupo->id, 'name' => $grupo->getLocalizedData('name')];
        }
        usort($papeis, fn ($a, $b) => strcoll($a['name'], $b['name']));

        $dados = json_encode([
            'roles' => $papeis,
            'apiUrl' => $request->getDispatcher()->url($request, Application::ROUTE_API, $context->getPath(), 'users'),
            'i18n' => [
                'all' => __('plugins.generic.ojsbrUserRoleFilter.allRoles'),
                'label' => __('plugins.generic.ojsbrUserRoleFilter.label'),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $templateMgr->addJavaScript(
            'ojsbrUserRoleFilterData',
            "window.ojsbrUserRoleFilter = {$dados};",
            ['inline' => true, 'contexts' => ['backend'], 'priority' => TemplateManager::STYLE_SEQUENCE_LATE]
        );

        $templateMgr->addJavaScript(
            'ojsbrUserRoleFilter',
            $request->getBaseUrl() . '/' . $this->getPluginPath() . '/js/roleFilter.js',
            ['contexts' => ['backend'], 'priority' => TemplateManager::STYLE_SEQUENCE_LAST]
        );

        return false;
    }
}
