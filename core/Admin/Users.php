<?php
declare(strict_types=1);

namespace Wordless\Admin;

use Wordless\Activity;
use Wordless\Auth;
use Wordless\Config;
use Wordless\Crypto;
use Wordless\Db;
use Wordless\Http;
use Wordless\Mailer;
use Wordless\Settings;

/** Utilisateurs et rôles. */
final class Users
{
    public function index(): string
    {
        Kernel::need('manage_users');
        $rows = Db::all('SELECT u.*, (SELECT COUNT(*) FROM content c WHERE c.author_id = u.id AND c.status <> \'trash\') AS posts FROM users u ORDER BY u.role, u.name');
        $actions = '<a class="btn btn-primary" href="' . e(admin_url('users/edit')) . '">' . icon('plus') . e(__('Inviter quelqu’un')) . '</a>';
        return Kernel::page('users', ['rows' => $rows], __('Utilisateurs'), 'users', $actions);
    }

    public function edit(): string
    {
        Kernel::need('manage_users');
        $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
        $u = $id ? Db::one('SELECT * FROM users WHERE id = ?', [$id]) : null;
        if (Http::method() === 'POST') {
            $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
            $name = mb_substr(trim(strip_tags((string) ($_POST['name'] ?? ''))), 0, 80);
            $role = isset(Auth::ROLES[$_POST['role'] ?? '']) ? $_POST['role'] : 'author';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
                Kernel::flash(__('Nom et e-mail valides obligatoires.'), 'bad');
                redirect(admin_url('users/edit' . ($id ? '?id=' . $id : '')));
            }
            if (Db::val('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $id])) {
                Kernel::flash(__('Cette adresse est déjà utilisée.'), 'bad');
                redirect(admin_url('users/edit' . ($id ? '?id=' . $id : '')));
            }
            if ($u && (int) $u['id'] === Auth::id() && $role !== 'admin') {
                $role = 'admin'; // on ne se retire pas soi-même le rôle d'administrateur
            }
            if ($u) {
                Db::update('users', ['email' => $email, 'name' => $name, 'role' => $role, 'status' => in_array($_POST['status'] ?? 'active', ['active', 'disabled', 'invited'], true) ? $_POST['status'] : 'active'], ['id' => $u['id']]);
                if (($_POST['status'] ?? '') === 'disabled') {
                    Auth::logoutEverywhere((int) $u['id']);
                }
                Activity::log('user', 'user', (int) $u['id'], $name);
                Kernel::flash(__('Utilisateur enregistré.'));
            } else {
                $slug = slugify($name) ?: 'auteur';
                $base = $slug;
                $i = 2;
                while (Db::val('SELECT 1 FROM users WHERE slug = ?', [$slug])) {
                    $slug = $base . '-' . $i++;
                }
                $newId = Db::insert('users', ['email' => $email, 'name' => $name, 'slug' => $slug, 'role' => $role, 'password' => Auth::hashPassword(Crypto::token(24)), 'status' => 'active', 'created_at' => time()]);
                $token = Crypto::token(32);
                Db::insert('tokens', ['id' => Crypto::hash($token), 'kind' => 'reset', 'user_id' => $newId, 'expires_at' => time() + 7 * 86400, 'created_at' => time()]);
                $link = Config::baseUrl() . Config::adminPath() . '/reset?token=' . $token;
                $sent = Mailer::send($email, __('Invitation à rejoindre {s}', ['s' => Settings::get('site.name')]),
                    __("Bonjour {n},\n\n{a} vous invite à contribuer au site {s}.\nChoisissez votre mot de passe ici (lien valable 7 jours) :\n{l}", ['n' => $name, 'a' => Auth::user()['name'], 's' => Settings::get('site.name'), 'l' => $link]));
                Activity::log('user', 'user', $newId, $name);
                Kernel::flash($sent ? __('Invitation envoyée à {e}.', ['e' => $email]) : __('E-mail non envoyé : transmettez ce lien à la personne : {l}', ['l' => $link]), $sent ? 'good' : 'warn');
            }
            redirect(admin_url('users'));
        }
        return Kernel::page('user-edit', ['u' => $u], $u ? __('Modifier l’utilisateur') : __('Inviter quelqu’un'), 'users', '', ['narrow' => true]);
    }

    public function action(): string
    {
        Kernel::need('manage_users');
        $id = (int) ($_POST['id'] ?? 0);
        $u = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
        if ($u && ($_POST['action'] ?? '') === 'delete' && $id !== Auth::id()) {
            Db::run('UPDATE content SET author_id = ? WHERE author_id = ?', [Auth::id(), $id]);
            Db::delete('users', ['id' => $id]);
            Activity::log('delete', 'user', $id, $u['name']);
            Kernel::flash(__('Utilisateur supprimé ; ses contenus vous sont attribués.'));
        } elseif ($u && ($_POST['action'] ?? '') === 'reset2fa') {
            Db::update('users', ['totp_enabled' => 0, 'totp_secret' => '', 'recovery_codes' => '[]'], ['id' => $id]);
            Activity::log('2fa_off', 'user', $id, $u['name']);
            Kernel::flash(__('Double authentification réinitialisée pour {n}.', ['n' => $u['name']]), 'warn');
        }
        redirect(admin_url('users'));
    }
}
