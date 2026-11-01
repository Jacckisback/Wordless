<?php
declare(strict_types=1);

namespace Wordless\Admin;

use Wordless\Activity;
use Wordless\Analytics;
use Wordless\Auth;
use Wordless\Content;
use Wordless\Db;
use Wordless\Http;
use Wordless\Settings;

/** Tableau de bord 360°. */
final class Dashboard
{
    public function index(): string
    {
        if (Http::method() === 'POST') {
            Settings::set('onboarding.dismissed', true);
            redirect(admin_url());
        }
        if (Auth::can('view_stats')) {
            try {
                Analytics::aggregate();
            } catch (\Throwable) {
            }
        }
        $days = in_array((int) ($_GET['days'] ?? 30), [7, 30, 90], true) ? (int) ($_GET['days'] ?? 30) : 30;
        $pillars = Health::pillars();
        $counts = [];
        foreach (Db::all("SELECT type, status, COUNT(*) AS n FROM content WHERE status <> 'trash' GROUP BY type, status") as $r) {
            $counts[$r['type']][$r['status']] = (int) $r['n'];
        }
        require_once WL_CORE . '/hit.php';
        $actions = '<a class="btn" href="' . e(admin_url('content/new?type=page')) . '">' . icon('page') . e(__('Nouvelle page')) . '</a>'
            . '<a class="btn btn-primary" href="' . e(admin_url('content/new?type=post')) . '">' . icon('plus') . e(__('Écrire un article')) . '</a>';
        return Kernel::page('dashboard', [
            'pillars' => $pillars,
            'todo' => Health::todo($pillars, 6),
            'stats' => Auth::can('view_stats') ? Analytics::summary($days) : null,
            'days' => $days,
            'aiBots' => wl_ai_bots(),
            'counts' => $counts,
            'recent' => Content::query(['status' => 'any', 'order' => 'updated', 'limit' => 6]),
            'stale' => Content::query(['type' => 'post', 'visible' => true, 'order' => 'oldest_update', 'limit' => 3]),
            'scheduled' => Content::query(['status' => 'scheduled', 'limit' => 3]),
            'activity' => Activity::recent(8),
            'onboarding' => Settings::get('onboarding.dismissed', false) ? null : Health::onboarding(),
            'user' => Auth::user(),
        ], __('Tableau de bord'), 'dashboard', $actions, ['scripts' => ['dashboard.js']]);
    }
}
