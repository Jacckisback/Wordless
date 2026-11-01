<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Commentaires : anti-spam sans service externe (champ piège, délai minimal,
 * signature, limite de fréquence, filtre de liens), modération, fils de discussion.
 */
final class Comments
{
    public static function enabled(): bool
    {
        return (bool) Settings::get('comments.enabled', true);
    }

    public static function count(int $contentId): int
    {
        return (int) Db::val("SELECT COUNT(*) FROM comments WHERE content_id = ? AND status = 'approved'", [$contentId]);
    }

    /** Commentaires approuvés en arbre (3 niveaux). */
    public static function tree(int $contentId): array
    {
        $rows = Db::all("SELECT * FROM comments WHERE content_id = ? AND status = 'approved' ORDER BY created_at ASC", [$contentId]);
        $byId = [];
        foreach ($rows as $r) {
            $r['children'] = [];
            $byId[(int) $r['id']] = $r;
        }
        $tree = [];
        foreach (array_keys($byId) as $id) {
            $pid = (int) ($byId[$id]['parent_id'] ?? 0);
            if ($pid && isset($byId[$pid])) {
                $byId[$pid]['children'][] = &$byId[$id];
            } else {
                $tree[] = &$byId[$id];
            }
        }
        return $tree;
    }

    public static function submit(): void
    {
        $contentId = (int) ($_POST['content_id'] ?? 0);
        $c = Content::find($contentId);
        $back = $c ? Content::href($c) : href('/');
        $fail = static fn () => redirect($back . '?comment=error#comments', 303);
        if (!$c || !Content::isVisible($c) || (int) $c['comments'] !== 1 || !self::enabled()) {
            $fail();
        }
        $ts = (string) ($_POST['ts'] ?? '');
        $age = time() - (int) $ts;
        if (!Crypto::verify($ts . '|c' . $contentId, (string) ($_POST['sig'] ?? ''), 'comment') || $age < 3 || $age > 3 * 86400 || ($_POST['website'] ?? '') !== '') {
            redirect($back . '?comment=pending#comments', 303); // robot : on ne lui dit rien
        }
        if (Throttle::blocked('comment:' . ip_hash()) || Throttle::hit('comment:' . ip_hash(), 5, 600, 1800) > 0) {
            $fail();
        }
        $author = mb_substr(trim(strip_tags((string) ($_POST['author'] ?? ''))), 0, 80);
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($author === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($body) < 2 || mb_strlen($body) > 5000) {
            $fail();
        }
        $links = preg_match_all('#https?://#i', $body);
        $spammy = $links > 2 || preg_match('/\b(viagra|cialis|casino|crypto ?airdrop|porn|loan|bitcoin doubler)\b/i', $body);
        $mode = (string) Settings::get('comments.moderation', 'first');
        $known = (bool) Db::val("SELECT 1 FROM comments WHERE email = ? AND status = 'approved' LIMIT 1", [$email]);
        $status = $spammy ? 'spam' : ($mode === 'none' || ($mode === 'first' && $known) ? 'approved' : 'pending');
        $html = Html::clean(Html::autop(e($body)), 'comment');
        $id = Db::insert('comments', [
            'content_id' => $contentId, 'parent_id' => null, 'author' => $author, 'email' => $email, 'url' => '',
            'body' => $html, 'status' => $status, 'ip_hash' => ip_hash(), 'created_at' => time(),
        ]);
        if ($status === 'approved') {
            Cache::purge();
        }
        if ($status === 'pending' && Settings::get('comments.notify', true)) {
            Mailer::toAdmins(__('Nouveau commentaire à valider sur « {t} »', ['t' => $c['title']]),
                $author . " :\n\n" . plain_text($html) . "\n\n" . __('Modérer :') . ' ' . Config::baseUrl() . Config::adminPath() . '/comments');
        }
        redirect($back . ($status === 'approved' ? '#comment-' . $id : '?comment=pending#comments'), 303);
    }

    public static function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['approved', 'pending', 'spam', 'trash'], true)) {
            return;
        }
        Db::update('comments', ['status' => $status], ['id' => $id]);
        Cache::purge();
    }

    public static function counts(): array
    {
        $out = ['pending' => 0, 'approved' => 0, 'spam' => 0, 'trash' => 0];
        foreach (Db::all('SELECT status, COUNT(*) AS n FROM comments GROUP BY status') as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }
}
