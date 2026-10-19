<?php

namespace App\Support;

use App\Models\User;

/**
 * The admin panel's menu and the tabs of pages that belong together, in one place.
 *
 * The sidebar has a link per area, grouped by what you are doing (Overview, Channels, Users, Money, Reports, Maintenance). Pages
 * of one area that belong together share a tab bar instead of each having a menu link (Channels: all, duplicates, cleanup
 * rules, playlist options; Activity: plays, refused; Money: payments, sales, statements, credit history). Resellers see only
 * what their role allows (the routes' middleware decides access; this only decides what is shown).
 */
final class AdminNav
{
    /** Areas a reseller can open. */
    private const RESELLER_ROUTES = [
        'admin.dashboard', 'admin.alerts.index', 'admin.live.index', 'admin.users.index', 'admin.hide-channels.index',
        'admin.logs.index', 'admin.logs.refused', 'admin.payments.index', 'admin.statements.index', 'admin.credits.index',
    ];

    /**
     * Sidebar: section headings and links (route, the routes that mark it active, icon, label).
     *
     * @return list<array{section: string}|array{route: string, match: list<string>, icon: string, label: string}>
     */
    public static function sidebar(User $user): array
    {
        $items = [
            ['section' => __('Overview')],
            ['route' => 'admin.dashboard', 'match' => ['admin.dashboard'], 'icon' => 'dashboard', 'label' => __('Dashboard')],
            ['route' => 'admin.alerts.index', 'match' => ['admin.alerts.*'], 'icon' => 'bell', 'label' => __('Alerts')],
            ['route' => 'admin.live.index', 'match' => ['admin.live.*'], 'icon' => 'eye', 'label' => __('Watching now')],
            ['section' => __('Channels')],
            ['route' => 'admin.channels.index', 'match' => ['admin.channels.*', 'admin.cleanup.*', 'admin.playlist-options.*'], 'icon' => 'tv', 'label' => __('Channels')],
            ['route' => 'admin.bouquets.index', 'match' => ['admin.bouquets.*'], 'icon' => 'package', 'label' => __('Bouquets')],
            ['route' => 'admin.categories.index', 'match' => ['admin.categories.*'], 'icon' => 'folder', 'label' => __('Categories')],
            ['route' => 'admin.sources.index', 'match' => ['admin.sources.*', 'admin.receiver.*'], 'icon' => 'source', 'label' => __('Sources')],
            ['route' => 'admin.epg.index', 'match' => ['admin.epg.*'], 'icon' => 'clock', 'label' => __('TV guide')],
            ['section' => __('Users')],
            ['route' => 'admin.users.index', 'match' => ['admin.users.*'], 'icon' => 'users', 'label' => __('Users')],
            ['route' => 'admin.hide-channels.index', 'match' => ['admin.hide-channels.*'], 'icon' => 'tv', 'label' => __('Hide channels')],
            ['route' => 'admin.logs.index', 'match' => ['admin.logs.*'], 'icon' => 'activity', 'label' => __('Activity')],
            ['section' => __('Money')],
            ['route' => 'admin.payments.index', 'match' => ['admin.payments.*', 'admin.sales.*', 'admin.statements.*', 'admin.credits.*'], 'icon' => 'check-circle', 'label' => __('Money')],
            ['section' => __('Reports')],
            ['route' => 'admin.statistics.index', 'match' => ['admin.statistics.*'], 'icon' => 'signal', 'label' => __('Statistics')],
            ['section' => __('Maintenance')],
            ['route' => 'admin.backups.index', 'match' => ['admin.backups.*'], 'icon' => 'shield', 'label' => __('Backups')],
            ['route' => 'admin.audit.index', 'match' => ['admin.audit.*'], 'icon' => 'activity', 'label' => __('Audit log')],
            ['route' => 'admin.launch-checklist.index', 'match' => ['admin.launch-checklist.*'], 'icon' => 'check', 'label' => __('Launch checklist')],
        ];

        return self::forUser($items, $user);
    }

    /**
     * The tab bar for the page being shown, or [] when it isn't part of a group.
     *
     * @return list<array{route: string, match: list<string>, label: string}>
     */
    public static function tabs(User $user): array
    {
        $groups = [
            [
                ['route' => 'admin.channels.index', 'match' => ['admin.channels.index', 'admin.channels.create', 'admin.channels.edit'], 'label' => __('All channels')],
                ['route' => 'admin.channels.duplicates', 'match' => ['admin.channels.duplicates'], 'label' => __('Duplicates')],
                ['route' => 'admin.cleanup.index', 'match' => ['admin.cleanup.*'], 'label' => __('Cleanup rules')],
                ['route' => 'admin.playlist-options.index', 'match' => ['admin.playlist-options.*'], 'label' => __('Playlist options')],
            ],
            [
                ['route' => 'admin.logs.index', 'match' => ['admin.logs.index'], 'label' => __('Plays')],
                ['route' => 'admin.logs.refused', 'match' => ['admin.logs.refused'], 'label' => __('Refused')],
            ],
            [
                ['route' => 'admin.payments.index', 'match' => ['admin.payments.*'], 'label' => __('Payments')],
                ['route' => 'admin.sales.index', 'match' => ['admin.sales.*'], 'label' => __('Sales')],
                ['route' => 'admin.statements.index', 'match' => ['admin.statements.*'], 'label' => __('Statements')],
                ['route' => 'admin.credits.index', 'match' => ['admin.credits.*'], 'label' => __('Credit history')],
            ],
        ];

        foreach ($groups as $tabs) {
            if (collect($tabs)->contains(fn ($tab) => request()->routeIs(...$tab['match']))) {
                $visible = self::forUser($tabs, $user);

                return count($visible) > 1 ? $visible : [];
            }
        }

        return [];
    }

    /**
     * Leave out what a reseller can't open, and section headings left without links.
     *
     * @template T of array
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    private static function forUser(array $items, User $user): array
    {
        if ($user->isAdmin()) {
            return $items;
        }

        $items = array_values(array_filter($items, fn ($item) => isset($item['section']) || in_array($item['route'], self::RESELLER_ROUTES, true)));

        return array_values(array_filter($items, fn ($item, $i) => !isset($item['section']) || (isset($items[$i + 1]) && !isset($items[$i + 1]['section'])), ARRAY_FILTER_USE_BOTH));
    }
}
