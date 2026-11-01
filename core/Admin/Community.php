<?php
declare(strict_types=1);

namespace Wordless\Admin;

use Wordless\Comments;
use Wordless\Content;
use Wordless\Db;

/** Commentaires et messages du formulaire de contact. */
final class Community
{
    public function comments(): string
    {
        Kernel::need('moderate');
        $status = in_array($_GET['status'] ?? '', ['pending', 'approved', 'spam', 'trash'], true) ? $_GET['status'] : 'pending';
        $rows = Db::all('SELECT c.*, ct.title AS content_title, ct.id AS cid FROM comments c LEFT JOIN content ct ON ct.id = c.content_id WHERE c.status = ? ORDER BY c.created_at DESC LIMIT 200', [$status]);
        return Kernel::page('comments', ['rows' => $rows, 'status' => $status, 'counts' => Comments::counts()], __('Commentaires'), 'comments');
    }

    public function commentAction(): string
    {
        Kernel::need('moderate');
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        if (!empty($_POST['one'])) {
            [$action, $id] = array_pad(explode(':', (string) $_POST['one'], 2), 2, '0');
            $ids = [(int) $id];
        } else {
            $action = (string) ($_POST['action'] ?? '');
        }
        foreach ($ids as $id) {
            if ($action === 'delete') {
                Db::delete('comments', ['id' => $id]);
            } else {
                Comments::setStatus($id, $action);
            }
        }
        \Wordless\Cache::purge();
        Kernel::flash(_n('{n} commentaire mis à jour.', '{n} commentaires mis à jour.', count($ids)));
        redirect(Kernel::safeRedirect($_POST['back'] ?? null, admin_url('comments')));
    }

    public function messages(): string
    {
        Kernel::need('moderate');
        $status = ($_GET['status'] ?? '') === 'archived' ? 'archived' : 'inbox';
        $rows = Db::all('SELECT f.*, c.title AS content_title FROM form_entries f LEFT JOIN content c ON c.id = f.content_id WHERE ' . ($status === 'archived' ? "f.status = 'archived'" : "f.status <> 'archived'") . ' ORDER BY f.created_at DESC LIMIT 200');
        $open = !empty($_GET['id']) ? Db::one('SELECT f.*, c.title AS content_title FROM form_entries f LEFT JOIN content c ON c.id = f.content_id WHERE f.id = ?', [(int) $_GET['id']]) : null;
        if ($open && $open['status'] === 'new') {
            Db::update('form_entries', ['status' => 'read'], ['id' => $open['id']]);
        }
        $actions = '<a class="btn" href="' . e(admin_url('messages/export')) . '">' . icon('import') . e(__('Exporter (CSV)')) . '</a>';
        return Kernel::page('messages', ['rows' => $rows, 'open' => $open, 'status' => $status], __('Messages'), 'messages', $actions);
    }

    public function messageAction(): string
    {
        Kernel::need('moderate');
        $id = (int) ($_POST['id'] ?? 0);
        match ((string) ($_POST['action'] ?? '')) {
            'archive' => Db::update('form_entries', ['status' => 'archived'], ['id' => $id]),
            'unread' => Db::update('form_entries', ['status' => 'new'], ['id' => $id]),
            'delete' => Db::delete('form_entries', ['id' => $id]),
            default => null,
        };
        Kernel::flash(__('Message mis à jour.'));
        redirect(admin_url('messages'));
    }

    public function exportMessages(): never
    {
        Kernel::need('moderate');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="messages-' . gmdate('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['date', 'nom', 'email', 'telephone', 'objet', 'message', 'page']);
        foreach (Db::all('SELECT f.*, c.title AS content_title FROM form_entries f LEFT JOIN content c ON c.id = f.content_id ORDER BY f.created_at DESC') as $r) {
            $d = json_dec($r['data'], []);
            $row = [gmdate('Y-m-d H:i', (int) $r['created_at']), $d['name'] ?? '', $d['email'] ?? '', $d['phone'] ?? '', $d['subject'] ?? '', $d['message'] ?? '', $r['content_title'] ?? ''];
            // Neutralise les formules (injection CSV dans un tableur).
            fputcsv($out, array_map(static fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : $v, $row));
        }
        fclose($out);
        exit;
    }
}
