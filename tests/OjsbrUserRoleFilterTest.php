<?php

/**
 * @file plugins/generic/ojsbrUserRoleFilter/tests/OjsbrUserRoleFilterTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OjsbrUserRoleFilterTest
 *
 * @brief What the cookie may ask for, what reaches the query, the screen the
 *        selector is loaded on and the assets it loads.
 */

namespace APP\plugins\generic\ojsbrUserRoleFilter\tests;

use APP\plugins\generic\ojsbrUserRoleFilter\OjsbrUserRoleFilterPlugin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\tests\PKPTestCase;
use PKP\userGroup\UserGroup;
use ReflectionProperty;

#[CoversClass(OjsbrUserRoleFilterPlugin::class)]
class OjsbrUserRoleFilterTest extends PKPTestCase
{
    protected function plugin(): OjsbrUserRoleFilterPlugin
    {
        return new OjsbrUserRoleFilterPlugin();
    }

    /**
     * The group ids the plugin holds for the request being answered.
     */
    protected function requestedGroupIds(OjsbrUserRoleFilterPlugin $plugin): array
    {
        $property = new ReflectionProperty(OjsbrUserRoleFilterPlugin::class, 'requestedGroupIds');
        $property->setAccessible(true);
        return $property->getValue($plugin);
    }

    /**
     * An API request carrying the cookie and the context, as the controller
     * hands it to the hook.
     */
    protected function apiRequest(?string $cookie, $context): Request
    {
        $request = Request::create('/index.php/journal/api/v1/users', 'GET', [], $cookie === null ? [] : [OjsbrUserRoleFilterPlugin::COOKIE => $cookie]);
        $request->attributes->set('context', $context);
        return $request;
    }

    public function testOnlyPositiveIntegersSurviveTheCookie(): void
    {
        $this->assertSame([], OjsbrUserRoleFilterPlugin::parseGroupIds(null));
        $this->assertSame([], OjsbrUserRoleFilterPlugin::parseGroupIds('   '));
        $this->assertSame([], OjsbrUserRoleFilterPlugin::parseGroupIds('abc'));
        $this->assertSame([], OjsbrUserRoleFilterPlugin::parseGroupIds('0,-3'));
        $this->assertSame([16], OjsbrUserRoleFilterPlugin::parseGroupIds('16'));
        $this->assertSame([16, 14], OjsbrUserRoleFilterPlugin::parseGroupIds('16,14,16'));
        // A value that is not a list of numbers cannot smuggle anything through.
        $this->assertSame([12], OjsbrUserRoleFilterPlugin::parseGroupIds("12,' OR 1=1 --"));
    }

    public function testOnlyGroupsOfTheContextAreAccepted(): void
    {
        $context = \APP\core\Application::getContextDAO()->newDataObject();
        $context->setId(1);

        $ownGroups = array_values(UserGroup::withContextIds([1])->get()->map(fn ($group) => (int) $group->id)->all());
        $this->assertNotEmpty($ownGroups, 'the installation the suite runs on has user groups');
        $own = $ownGroups[0];
        $foreign = max($ownGroups) + 100000;

        $plugin = $this->plugin();
        $plugin->readRequestedGroups('API::users::params', [[], $this->apiRequest($own . ',' . $foreign, $context)]);
        $this->assertSame([$own], $this->requestedGroupIds($plugin));

        // No cookie, no filter; and no context, nothing at all.
        $plugin->readRequestedGroups('API::users::params', [[], $this->apiRequest(null, $context)]);
        $this->assertSame([], $this->requestedGroupIds($plugin));
        $plugin->readRequestedGroups('API::users::params', [[], $this->apiRequest((string) $own, null)]);
        $this->assertSame([], $this->requestedGroupIds($plugin));
    }

    public function testTheQueryIsRestrictedToTheAssignmentsOfThoseGroups(): void
    {
        $plugin = $this->plugin();
        $property = new ReflectionProperty(OjsbrUserRoleFilterPlugin::class, 'requestedGroupIds');
        $property->setAccessible(true);

        // Nothing asked for: the query is left exactly as the collector built it.
        $query = DB::table('users as u');
        $before = $query->toSql();
        $plugin->filterQuery('User::Collector', [$query, null]);
        $this->assertSame($before, $query->toSql());

        $property->setValue($plugin, [7, 9]);
        $plugin->filterQuery('User::Collector', [$query, null]);
        $sql = $query->toSql();
        $this->assertStringContainsString('exists', strtolower($sql));
        $this->assertStringContainsString('user_user_groups', $sql);
        $this->assertStringContainsString('ojsbr_uug', $sql);
        // The ids travel as bindings, never inlined in the SQL.
        $this->assertSame([7, 9], $query->getBindings());
    }

    public function testTheSelectorIsLoadedOnTheUsersScreenOnly(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/OjsbrUserRoleFilterPlugin.php');
        $this->assertSame('management/access.tpl', OjsbrUserRoleFilterPlugin::TEMPLATE);
        $this->assertStringContainsString('str_contains($template, self::TEMPLATE)', $source);

        preg_match_all("/Hook::add\('([^']+)'/", $source, $matches);
        $this->assertSame(
            ['TemplateManager::display', 'API::users::params', 'User::Collector'],
            $matches[1]
        );
    }

    public function testTheStylesAndTheScriptAreStaticFilesOfThePlugin(): void
    {
        $root = dirname(__DIR__);
        foreach (['css/roleFilter.css', 'js/roleFilter.js'] as $asset) {
            $this->assertFileExists($root . '/' . $asset);
        }

        $script = (string) file_get_contents($root . '/js/roleFilter.js');
        // The selector is added through the registry of the application, and no
        // function of the page — fetch included — is replaced.
        $this->assertStringContainsString('pkp.registry.registerComponent', $script);
        $this->assertStringContainsString("pkp.registry.storeExtendFn('userAccessManager', 'getTopItems'", $script);
        $this->assertStringNotContainsString('window.fetch =', $script);
        $this->assertStringNotContainsString('XMLHttpRequest', $script);
    }
}
