<?php
declare(strict_types=1);

namespace Wordless;

/** Formulaire de contact : messages enregistrés dans l'admin et envoyés par e-mail. */
final class Forms
{
    public static function submit(): void
    {
        $contentId = (int) ($_POST['content_id'] ?? 0);
        $blockId = preg_replace('/[^a-z0-9_-]/i', '', (string) ($_POST['block'] ?? '')) ?? '';
        $c = Content::find($contentId);
        $back = $c ? Content::href($c) : href('/');
        $block = null;
        foreach ($c['blocks'] ?? [] as $b) {
            if ($b['id'] === $blockId && $b['type'] === 'contact') {
                $block = $b;
            }
        }
        $fail = static fn () => redirect($back . '?form=' . rawurlencode($blockId) . '&form_error=1#' . rawurlencode($blockId), 303);
        if (!$c || !$block || !Content::isVisible($c)) {
            $fail();
        }
        $ts = (string) ($_POST['ts'] ?? '');
        $age = time() - (int) $ts;
        if (!Crypto::verify($ts . '|' . $blockId, (string) ($_POST['sig'] ?? ''), 'form') || $age < 2 || $age > 3 * 86400) {
            $fail();
        }
        if (($_POST['website'] ?? '') !== '') {
            redirect($back . '?sent=' . rawurlencode($blockId) . '#' . rawurlencode($blockId), 303); // piège à robots
        }
        $key = 'form:' . ip_hash();
        if (Throttle::blocked($key) || Throttle::hit($key, 5, 3600, 3600) > 0) {
            $fail();
        }
        $data = [
            'name' => mb_substr(trim(strip_tags((string) ($_POST['name'] ?? ''))), 0, 120),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'phone' => mb_substr(preg_replace('/[^\d+ ().-]/', '', (string) ($_POST['phone'] ?? '')) ?? '', 0, 40),
            'subject' => mb_substr(trim(strip_tags((string) ($_POST['subject'] ?? ''))), 0, 200),
            'message' => mb_substr(trim(strip_tags((string) ($_POST['message'] ?? ''))), 0, 5000),
        ];
        if ($data['name'] === '' || !filter_var($data['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($data['message']) < 2) {
            $fail();
        }
        if (preg_match_all('#https?://#i', $data['message']) > 3) {
            $fail();
        }
        Db::insert('form_entries', [
            'content_id' => $contentId, 'form' => $blockId, 'data' => json_enc(array_filter($data, static fn ($v) => $v !== '')),
            'status' => 'new', 'ip_hash' => ip_hash(), 'created_at' => time(),
        ]);
        $to = (string) ($block['data']['recipient'] ?? '');
        $subject = __('Nouveau message de {n} via {s}', ['n' => $data['name'], 's' => Settings::get('site.name')]);
        $text = __('Page : {p}', ['p' => Content::permalink($c)]) . "\n" . __('Nom : {n}', ['n' => $data['name']]) . "\n" . 'E-mail : ' . $data['email'] . "\n"
            . ($data['phone'] !== '' ? __('Téléphone : {t}', ['t' => $data['phone']]) . "\n" : '')
            . ($data['subject'] !== '' ? __('Objet : {o}', ['o' => $data['subject']]) . "\n" : '') . "\n" . $data['message'] . "\n";
        if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Mailer::send($to, $subject, $text, $data['email']);
        } else {
            Mailer::toAdmins($subject, $text, $data['email']);
        }
        redirect($back . '?sent=' . rawurlencode($blockId) . '#' . rawurlencode($blockId), 303);
    }
}
