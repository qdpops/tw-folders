<?php

/**
 * Server side of the breeze skin.
 * User prefs (saved with utils/save-pref):
 *  - tw_folder_style: [folder id => ['icon' => name, 'color' => #hex]] from the "Создать папку" dialog
 *  - tw_preview: where an opened message goes: 'none' (replaces the list), 'right' or 'bottom'
 *  - tw_split: list size in split layouts, ['right' => px, 'bottom' => px]
 * Message list: names and sizes of attachments for the chips under list rows.
 */
class tw_folders extends rcube_plugin
{
    public $task = 'mail|settings|addressbook|login|logout';

    // lets utils/save-pref store them. Declared here, not in init(): Roundcube collects it when loading the plugin,
    // before init(), and for every task (save-pref runs in the 'utils' task)
    public $allowed_prefs = ['tw_folder_style', 'tw_preview', 'tw_split', 'tw_rules', 'addressbook_sort_col', 'mail_pagesize']; // sort select on the contacts page, "по 100" in the list bar

    const MAX_CHIPS = 3;
    const RULE_FIELDS = ['from', 'to', 'subject'];

    function init()
    {
        $rcmail = rcmail::get_instance();

        // the login page knows no user: show it in the language this browser last used (config 'language' otherwise)
        if ($rcmail->task == 'login' || $rcmail->task == 'logout') {
            $lang = rcube_utils::get_input_string('tw_lang', rcube_utils::INPUT_COOKIE);
            if ($lang && $lang != ($_SESSION['language'] ?? '') && isset($rcmail->list_languages()[$lang])) {
                $rcmail->load_language($lang);
                if ($rcmail->output instanceof rcmail_output_html) {
                    $rcmail->output->set_env('locale', $lang);
                }
            }
        }
        elseif (!empty($_SESSION['language']) && ($_COOKIE['tw_lang'] ?? '') !== $_SESSION['language']) {
            rcube_utils::setcookie('tw_lang', $_SESSION['language'], time() + 365 * 86400);
        }

        // the compose panel loads mail/compose&_framed=1, but Roundcube redirects to compose&_id=… without _framed:
        // the page then has the top bar and a full-page layout. The browser still says it is a frame.
        if ($rcmail->task == 'mail' && $rcmail->action == 'compose' && empty($_REQUEST['_framed'])
            && rcube_utils::request_header('Sec-Fetch-Dest') === 'iframe' && $rcmail->output instanceof rcmail_output_html
        ) {
            $rcmail->output->set_env('framed', 1);
        }

        // the reply block under a message (breeze.js quick_reply, _tw_qr=1, kept in the compose session):
        // the text goes above the quote, as in Timeweb
        $qr_id = $rcmail->action == 'compose' ? rcube_utils::get_input_string('_id', rcube_utils::INPUT_GET) : '';
        if ($qr_id !== '' && !empty($_SESSION['compose_data_' . $qr_id]['param']['tw_qr'])) {
            if (intval($rcmail->config->get('reply_mode')) < 1) {
                $rcmail->config->set('reply_mode', 1);
            }
            $rcmail->output->set_env('tw_qr', 1); // breeze.js folds the quote
        }

        $this->add_texts('localization/', true); // the skin's templates and breeze.js use them on every page
        $rcmail->config->set('product_name', $this->gettext('product_name')); // config/custom.inc.php has one language

        // core labels reworded where our localization has a core_<name> (ru: "Пометить прочитанным", "Очистить");
        // other languages keep Roundcube's
        $merge = [];
        foreach (['markallread', 'empty'] as $name) {
            if ($rcmail->text_exists("tw_folders.core_$name")) {
                $merge[$name] = $this->gettext("core_$name");
            }
        }
        if ($merge) {
            $rcmail->load_language(null, [], $merge); // $merge overrides
        }

        // read-only address book "Из писем": the same people as the contacts panel (mail and addressbook tasks)
        if ($rcmail->task == 'mail' || $rcmail->task == 'addressbook') {
            $this->add_hook('addressbooks_list', [$this, 'mail_book_source']);
            $this->add_hook('addressbook_get', [$this, 'mail_book_get']);
        }

        if ($rcmail->task == 'mail') {
            $this->add_hook('messages_list', [$this, 'messages_list']);
            $this->register_action('plugin.tw-contact-add', [$this, 'contact_add']);
            $this->add_hook('refresh', [$this, 'apply_rules']);          // every refresh_interval while Roundcube is open
            $this->register_action('plugin.tw-contacts', [$this, 'contacts']);
            $this->register_action('plugin.tw-years', [$this, 'years']);
            $this->register_action('plugin.tw-logo', [$this, 'logo']);
            $this->register_action('plugin.tw-snippets', [$this, 'snippets']);
            if (empty($rcmail->action) && $rcmail->user && $rcmail->user->ID) {
                $this->apply_rules([]);                                   // and when the mailbox is opened
            }
        }
        if ($rcmail->task == 'addressbook') {
            $this->register_action('plugin.tw-ab-phones', [$this, 'ab_phones']);
            // save-pref stores any string: keep only the sort columns the address books know
            if (!in_array($rcmail->config->get('addressbook_sort_col'), ['name', 'firstname', 'surname'], true)) {
                $rcmail->config->set('addressbook_sort_col', 'surname');
            }
            if (empty($rcmail->action) && $rcmail->output instanceof rcmail_output_html) {
                $counts = [];
                foreach ($rcmail->get_address_sources() as $id => $source) {
                    $book = $rcmail->get_address_book($id);
                    $counts[$id] = $book ? (int) $book->count()->count : 0;
                }
                $rcmail->output->set_env('tw_ab_counts', $counts);
                $rcmail->output->set_env('tw_ab_sort', (string) $rcmail->config->get('addressbook_sort_col', 'surname'));
            }
        }
        if ($rcmail->task == 'settings') {
            $this->add_hook('settings_actions', [$this, 'settings_actions']);
            $this->register_action('plugin.tw-filters', [$this, 'filters_page']);
        }

        if (!($rcmail->output instanceof rcmail_output_html)) {
            return; // ajax requests
        }

        $rcmail->output->set_env('tw_rules', $this->rules());

        // right-click menus of the skin (messages, folders) use Roundcube's own, already translated labels
        if ($rcmail->task == 'mail' && empty($rcmail->action)) {
            $rcmail->output->add_label('reply', 'replyall', 'forward', 'editasnew', 'delete', 'moveto', 'markread', 'markunread',
                'markflagged', 'markunflagged', 'markallread', 'empty');
        }

        // split layouts: the skin's toolbar in the preview frame has the list's buttons and dialogs,
        // but Roundcube and archive/markasjunk load these texts only for the list and full message pages
        if ($rcmail->task == 'mail' && $rcmail->action == 'preview') {
            $rcmail->output->add_label('mark', 'markallread', 'folders-cur', 'folders-sub', 'folders-all', 'cancel',
                'deletemessage', 'movemessagetotrash', 'movingmessage', 'copyingmessage', 'deletingmessage', 'markingmessage');
            foreach (['archive', 'markasjunk'] as $name) {
                if ($plugin = $rcmail->plugins->get_plugin($name)) {
                    $plugin->add_texts('localization', true);
                }
            }
        }

        $preview = $rcmail->config->get('tw_preview');
        $rcmail->output->set_env('tw_preview', in_array($preview, ['right', 'bottom']) ? $preview : 'none');

        if ($rcmail->task == 'mail' && empty($rcmail->action)) {
            $style = $rcmail->config->get('tw_folder_style');
            $split = $rcmail->config->get('tw_split');
            $rcmail->output->set_env('tw_folder_style', is_array($style) ? $style : []);
            $rcmail->output->set_env('tw_split', is_array($split) ? array_map('intval', $split) : []);
        }
    }

    /** Valid rules from the user pref: [['folder', 'field' => from|to|subject, 'value', 'created' => Y-m-d], ...] */
    private function rules()
    {
        $out = [];
        foreach ((array) rcmail::get_instance()->config->get('tw_rules', []) as $r) {
            if (is_array($r) && !empty($r['folder']) && $r['folder'] !== 'INBOX'
                && in_array($r['field'] ?? '', self::RULE_FIELDS, true)
                && isset($r['value']) && trim($r['value']) !== ''
                && !empty($r['created']) && preg_match('/^\d{4}-\d\d-\d\d$/', $r['created'])
            ) {
                $out[] = ['folder' => (string) $r['folder'], 'field' => $r['field'],
                    'value' => trim((string) $r['value']), 'created' => $r['created']];
            }
        }
        return $out;
    }

    /**
     * Folder rules ("move here messages where From/To/Subject contains ..."), applied to INBOX.
     * Only mail received since the rule was created (IMAP SINCE), so older Inbox mail is never touched.
     */
    function apply_rules($args)
    {
        $rcmail = rcmail::get_instance();
        $rules  = $this->rules();
        if (!$rules) {
            return $args;
        }

        $storage = $rcmail->get_storage();
        $moved   = 0;

        foreach ($rules as $r) {
            $since    = date('j-M-Y', strtotime($r['created']));
            $criteria = strtoupper($r['field']) . ' ' . rcube_imap_generic::escape($r['value']) . ' SINCE ' . $since;
            $charset  = preg_match('/[^\x20-\x7e]/', $r['value']) ? 'CHARSET UTF-8 ' : '';
            $found    = $storage->search_once('INBOX', $charset . $criteria); // connects first (on page load nothing has yet); UIDs
            $uids     = $found && !$found->is_error() ? $found->get() : [];

            if ($uids && $storage->folder_exists($r['folder']) && $storage->move_message($uids, $r['folder'], 'INBOX')) {
                $moved += count($uids);
            }
        }

        if ($moved && $rcmail->output->type == 'js') {
            $rcmail->output->command('plugin.tw_rules_moved', $moved); // the page refreshes the list
        }

        return $args;
    }

    /**
     * Contacts panel (top bar "Контакты"): address book entries plus people from the latest Inbox and Sent mail,
     * like Timeweb's list. Nothing is saved. Output: {contacts: [[name, email, unix time of last mail or 0, in address book 0|1], ...]}
     */
    const MAIL_BOOK = 'twmail';
    const MAIL_CACHE_TTL = 300; // seconds; adding a contact drops the cache

    function mail_book_source($p)
    {
        // contacts page: only "Мои контакты" and "Из писем". Roundcube's collected books stay in use elsewhere
        // (trusted senders' images, "To" suggestions); they're just not listed
        if (rcmail::get_instance()->task == 'addressbook') {
            unset($p['sources'][rcube_addressbook::TYPE_RECIPIENT], $p['sources'][rcube_addressbook::TYPE_TRUSTED_SENDER]);
        }
        $p['sources'][self::MAIL_BOOK] = [
            'id' => self::MAIL_BOOK, 'name' => $this->gettext('book_mail'), 'readonly' => true, 'groups' => false,
        ];
        return $p;
    }

    function mail_book_get($p)
    {
        if ($p['id'] === self::MAIL_BOOK) {
            require_once __DIR__ . '/tw_mail_book.php';
            $p['instance'] = new tw_mail_book($this->gettext('book_mail'), function() { return $this->collect(); });
        }
        return $p;
    }

    /**
     * "Период" in the list bar: messages per year in a folder, newest year first, by the Date header (SENTSINCE),
     * the same date the list shows. Output: plugin.tw_years {mbox, total, years: [[year, count], ...]}
     */
    function years()
    {
        $rcmail  = rcmail::get_instance();
        $storage = $rcmail->get_storage();
        $mbox    = rcube_utils::get_input_string('_mbox', rcube_utils::INPUT_GET, true) ?: 'INBOX';
        $total   = (int) $storage->count($mbox, 'ALL');
        $years   = [];
        $sum     = 0;

        // newest year down until every message is counted; 30 years at most (one IMAP SEARCH each)
        for ($y = (int) date('Y'); $sum < $total && $y > (int) date('Y') - 30; $y--) {
            $n = $storage->search_once($mbox, sprintf('SENTSINCE 1-Jan-%d SENTBEFORE 1-Jan-%d', $y, $y + 1))->count();
            if ($n) {
                $years[] = [$y, $n];
                $sum += $n;
            }
        }

        $rcmail->output->command('plugin.tw_years', ['mbox' => $mbox, 'total' => $total, 'years' => $years]);
        $rcmail->output->send();
    }

    /**
     * Sender logo for the list avatar: GET _domain=example.com, answers with the image or 404.
     * Found once per domain (BIMI, then the site's icons), kept in temp_dir: a week, a miss for a day.
     */
    function logo()
    {
        $rcmail = rcmail::get_instance();
        $domain = strtolower(rcube_utils::get_input_string('_domain', rcube_utils::INPUT_GET));

        if (!preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/', $domain)) {
            $this->logo_send('');
        }

        $dir = slashify($rcmail->config->get('temp_dir') ?: sys_get_temp_dir()) . 'tw_logos';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        $file = $dir . '/' . md5($domain);
        if (is_file($file) && filemtime($file) > time() - (filesize($file) ? 7 : 1) * 86400) {
            $this->logo_send((string) file_get_contents($file));
        }

        // the domain itself, then the organisation's (news.example.com -> example.com)
        $data  = '';
        $parts = explode('.', $domain);
        foreach (array_unique([$domain, implode('.', array_slice($parts, -2))]) as $d) {
            if (($data = $this->logo_find($d)) !== '') {
                break;
            }
        }

        @file_put_contents($file, $data);
        $this->logo_send($data);
    }

    private function logo_send($data)
    {
        if ($data === '') {
            header('HTTP/1.1 404 Not Found');
            header('Cache-Control: private, max-age=86400');
            exit;
        }

        $svg  = stripos(substr($data, 0, 512), '<svg') !== false;
        $info = $svg ? null : @getimagesizefromstring($data);
        header('Content-Type: ' . ($svg ? 'image/svg+xml' : ($info['mime'] ?? 'image/x-icon')));
        header('Cache-Control: private, max-age=604800');
        header('X-Content-Type-Options: nosniff');
        // an SVG opened directly must not run scripts in the webmail's origin
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        echo $data;
        exit;
    }

    // BIMI logo (DNS), the site's apple-touch-icon / declared icons, then favicon.ico; '' when none is an image
    private function logo_find($domain)
    {
        foreach ((array) @dns_get_record("default._bimi.$domain", DNS_TXT) as $rec) {
            if (preg_match('/v=BIMI1;.*\bl=(https:\/\/[^;\s]+)/i', $rec['txt'] ?? '', $m)
                && ($img = $this->logo_image($this->http_get($m[1]))) !== ''
            ) {
                return $img;
            }
        }

        $base = "https://$domain/";
        $urls = [$base . 'apple-touch-icon.png'];

        // icons the home page declares, biggest first
        if (preg_match_all('/<link\b[^>]*>/i', substr($this->http_get($base), 0, 100000), $links)) {
            $found = [];
            foreach ($links[0] as $tag) {
                if (preg_match('/\brel=["\']?[^"\'>]*icon/i', $tag) && preg_match('/\bhref=["\']?([^"\'\s>]+)/i', $tag, $h)) {
                    $size = preg_match('/\bsizes=["\']?(\d+)/i', $tag, $s) ? (int) $s[1] : (stripos($tag, 'apple') ? 180 : 16);
                    $href = html_entity_decode($h[1]);
                    $found[] = [$size, strpos($href, '//') === 0 ? "https:$href"
                        : (preg_match('#^https?://#i', $href) ? $href : $base . ltrim($href, '/'))];
                }
            }
            usort($found, function($a, $b) { return $b[0] <=> $a[0]; });
            $urls = array_merge($urls, array_column($found, 1));
        }

        $urls[] = $base . 'favicon.ico';
        foreach (array_slice(array_unique($urls), 0, 5) as $url) {
            if (($img = $this->logo_image($this->http_get($url))) !== '') {
                return $img;
            }
        }

        return '';
    }

    // keep real pictures only (a 200 page with HTML is not a logo); data: URIs are not followed
    private function logo_image($data)
    {
        if ($data === '') {
            return '';
        }
        if (preg_match('/^\s*(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*(<!DOCTYPE svg[^>]*>\s*)?<svg\b/is', $data)) {
            return $data;
        }
        $info = @getimagesizefromstring($data);
        return $info && $info[0] >= 16 ? $data : '';
    }

    /**
     * GET of a public https URL: at most 3 redirects, 300 KB, 4 s; '' on anything else.
     * Every host must resolve to public addresses only, so a sender's domain can't point the server at its own network.
     */
    private function http_get($url)
    {
        $rcmail = rcmail::get_instance();

        for ($hop = 0; $hop < 4; $hop++) {
            $host = parse_url($url, PHP_URL_HOST);
            if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !$host || !$this->public_host($host)) {
                return '';
            }

            try {
                $response = $rcmail->get_http_client()->request('GET', $url, [
                    'allow_redirects' => false,
                    'connect_timeout' => 2,
                    'timeout'         => 4,
                    'http_errors'     => false,
                    'stream'          => true,
                    'headers'         => ['User-Agent' => 'Mozilla/5.0 (Roundcube webmail; sender logo)'],
                ]);
            }
            catch (Exception $e) {
                return '';
            }

            $code = $response->getStatusCode();
            if ($code >= 300 && $code < 400 && ($loc = $response->getHeaderLine('Location')) !== '') {
                $url = preg_match('#^https?://#i', $loc) ? $loc : (strpos($loc, '//') === 0 ? "https:$loc"
                    : "https://$host/" . ltrim($loc, '/'));
                continue;
            }
            if ($code != 200) {
                return '';
            }

            $body = $response->getBody();
            $data = '';
            while (!$body->eof() && strlen($data) <= 300000) {
                $data .= $body->read(65536);
            }
            return strlen($data) > 300000 ? '' : $data;
        }

        return '';
    }

    private function public_host($host)
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        }
        else {
            $ips = [];
            foreach ((array) @dns_get_record($host, DNS_A | DNS_AAAA) as $rec) {
                $ips[] = $rec['ip'] ?? ($rec['ipv6'] ?? '');
            }
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }
        return (bool) $ips;
    }

    /**
     * Grey third line of a list row: the start of each message's text. POST _uids=row ids (uid or uid-folder), _mbox.
     * One BODYSTRUCTURE fetch, then a partial read of each message's text part. Output: plugin.tw_snippets {asked: [rowid], text: {rowid: text}}
     */
    function snippets()
    {
        $rcmail  = rcmail::get_instance();
        $storage = $rcmail->get_storage();
        $mbox    = rcube_utils::get_input_string('_mbox', rcube_utils::INPUT_POST, true) ?: $storage->get_folder();
        $ids     = [];

        $rcmail->storage_connect(); // conn->fetch below doesn't open the IMAP connection by itself

        foreach (array_slice(explode(',', (string) rcube_utils::get_input_string('_uids', rcube_utils::INPUT_POST, true)), 0, 200) as $id) {
            if (preg_match('/^(\d+)(?:-(.+))?$/', $id, $m)) {
                $ids[$m[2] ?? $mbox][(int) $m[1]] = $id;
            }
        }

        $out = [];
        foreach ($ids as $folder => $rows) {
            $parts = [];
            $found = array_fill_keys(array_keys($rows), ''); // '' too: a message without text isn't read again
            foreach ((array) $storage->conn->fetch($folder, array_keys($rows), true, ['BODYSTRUCTURE']) as $msg) {
                if ($part = $this->text_part($msg->bodystructure ?? null)) {
                    $parts[$part[0]][$msg->uid] = $part;
                }
            }

            // one partial read per message: rcube_imap_generic::fetch() reads only the first line of a partial
            // BODY[]<0.n> literal; handlePartBody() reads it whole and decodes base64 / quoted-printable
            foreach ($parts as $list) {
                foreach ($list as $uid => $part) {
                    // HTML mail often starts with a long <style> block: read more of it
                    $raw  = $storage->conn->handlePartBody($folder, $uid, true, $part[0], $part[2], false, null, false,
                        $part[1] == 'html' ? 12288 : 2048);
                    $text = is_string($raw) ? $this->snippet_text($raw, $part) : '';
                    if ($text !== '') {
                        $out[$rows[$uid]] = $text;
                    }
                    if ($raw !== false) { // a failed read is asked again next time
                        $found[$uid] = $text;
                    }
                    elseif (isset($found[$uid])) {
                        unset($found[$uid]);
                    }
                }
            }
            $this->snippet_cache($folder, $found);
        }

        // 'asked' too: rows without any text are not asked again
        $asked = [];
        foreach ($ids as $rows) {
            $asked = array_merge($asked, array_values($rows));
        }
        $rcmail->output->command('plugin.tw_snippets', ['asked' => $asked, 'text' => (object) $out]);
        $rcmail->output->send();
    }

    /**
     * Snippet cache of one folder, {uid: text}, in Roundcube's cache table (per user, 30 days).
     * Read with one argument; with $add, merge it in and store. Keyed by the folder's UIDVALIDITY too:
     * when the server renumbers the folder, its old entries are no longer found.
     * shortcut: keeps the newest 1000 messages per folder (read whole on every list), enough for the first pages;
     * older pages read their text again after the list
     */
    private function snippet_cache($folder, $add = null)
    {
        $rcmail = rcmail::get_instance();
        $cache  = $rcmail->get_cache('tw_snippets', 'db', '30d');
        if (!$cache) {
            return [];
        }

        $data = $rcmail->get_storage()->folder_data($folder);
        $key  = md5($folder . '|' . ($data['UIDVALIDITY'] ?? ''));
        $map  = $cache->get($key);
        $map  = is_array($map) ? $map : [];

        if ($add !== null && $add) {
            $map = array_replace($map, $add);
            ksort($map, SORT_NUMERIC);
            $map = array_slice($map, -1000, null, true);
            $cache->set($key, $map);
        }
        return $map;
    }

    // first text/plain (else text/html) part that isn't an attachment: [part number, subtype, encoding, charset]
    private function text_part($bs)
    {
        $found = [];
        $walk = function($part, $path) use (&$walk, &$found) {
            if (!is_array($part)) {
                return;
            }
            if (is_array($part[0])) {
                foreach ($part as $i => $sub) {
                    if (!is_array($sub)) {
                        break;
                    }
                    $walk($sub, $path === '' ? (string) ($i + 1) : $path . '.' . ($i + 1));
                }
                return;
            }
            $type = strtolower($part[0] . '/' . $part[1]);
            $disp = isset($part[9]) && is_array($part[9]) && is_string($part[9][0]) ? strtolower($part[9][0]) : '';
            if (($type == 'text/plain' || $type == 'text/html') && $disp != 'attachment') {
                $params  = $this->pairs($part[2] ?? null);
                $found[] = [$path === '' ? '1' : $path, strtolower($part[1]), strtolower((string) ($part[5] ?? '')), $params['charset'] ?? ''];
            }
        };
        $walk($bs, '');

        foreach ($found as $f) {
            if ($f[1] == 'plain') {
                return $f;
            }
        }
        return $found[0] ?? null;
    }

    private function snippet_text($raw, $part)
    {
        [, $subtype, , $charset] = $part; // already decoded by handlePartBody()

        $raw = rcube_charset::convert($raw, $charset ?: 'UTF-8', 'UTF-8');
        $raw = mb_scrub($raw, 'UTF-8'); // the cut may end inside a character

        if ($subtype == 'html') {
            $raw = preg_replace('#<(head|style|script|title)\b.*?(</\1\s*>|$)#is', ' ', $raw);
            $raw = preg_replace('#<br\s*/?>|</(p|div|tr|td|li|h\d)\s*>#i', ' ', $raw);
            $raw = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // "\u{200C}" and friends pad newsletter preheaders
        $raw = preg_replace('/[\x{00AD}\x{034F}\x{200B}-\x{200F}\x{2060}\x{FEFF}]+/u', '', $raw);
        $raw = trim(preg_replace('/\s+/u', ' ', $raw));
        return mb_substr($raw, 0, 200);
    }

    function contacts()
    {
        $rcmail = rcmail::get_instance();
        $rcmail->output->command('plugin.tw_contacts', ['contacts' => $this->collect()]);
        $rcmail->output->send();
    }

    /**
     * Contacts panel "+": saves a person from the mail to the default address book.
     * Output: plugin.tw_contact_added {email, ok}
     */
    function contact_add()
    {
        $rcmail = rcmail::get_instance();
        $email  = trim(rcube_utils::get_input_string('_email', rcube_utils::INPUT_POST));
        $name   = trim(rcube_utils::get_input_string('_name', rcube_utils::INPUT_POST, true));
        $book   = $rcmail->get_address_book(rcube_addressbook::TYPE_DEFAULT, true);
        $ok     = false;

        if ($book && !$book->readonly && rcube_utils::check_email($email, false)) {
            $ok = $book->search('email', $email, 1, false)->count > 0; // already there: nothing to do
            if (!$ok) {
                $record = ['name' => mb_substr($name, 0, 128), 'email' => $email];
                $plugin = $rcmail->plugins->exec_hook('contact_create',
                    ['record' => $record, 'source' => $rcmail->config->get('default_addressbook')]);
                $ok = empty($plugin['abort']) && $book->insert($plugin['record'], false);
            }
            unset($_SESSION['tw_mail_contacts']);
        }

        $rcmail->output->command('plugin.tw_contact_added', ['email' => $email, 'ok' => (bool) $ok]);
        $rcmail->output->send();
    }

    /**
     * People from the address books and the latest Inbox/Sent mail: [name, email, last time, in a book], newest first.
     * Cached in the session: the contacts page asks for it on every list, search and contact view.
     */
    private function collect()
    {
        $cache = $_SESSION['tw_mail_contacts'] ?? null;
        if (is_array($cache) && $cache['time'] > time() - self::MAIL_CACHE_TTL) {
            return $cache['list'];
        }

        $rcmail  = rcmail::get_instance();
        $storage = $rcmail->get_storage();
        $out     = [];

        $add = function($name, $email, $time, $book) use (&$out) {
            $key = mb_strtolower(trim($email));
            if ($key === '' || strpos($key, '@') === false) {
                return;
            }
            $c = $out[$key] ?? ['', $key, 0, 0];
            if ($name !== '' && ($book || $c[0] === '')) {
                $c[0] = $name;                         // the address book name wins over a header name
            }
            $c[2] = max($c[2], (int) $time);
            $c[3] = $c[3] || $book ? 1 : 0;
            $out[$key] = $c;
        };

        foreach ($rcmail->get_address_sources() as $id => $source) {
            $book = $id === self::MAIL_BOOK ? null : $rcmail->get_address_book($id);
            if (!$book) {
                continue;
            }
            $book->set_pagesize(1000); // shortcut: first 1000 per book, paging if anyone has more
            $result = $book->list_records();
            while ($result && ($row = $result->next())) {
                foreach (rcube_addressbook::get_col_values('email', $row, true) as $email) {
                    $add(rcube_addressbook::compose_display_name($row, true), $email, 0, true);
                }
            }
        }

        $own = array_map('mb_strtolower', array_column((array) $rcmail->user->list_emails(), 'email'));
        $storage->set_pagesize(300); // latest 300 messages of each folder
        foreach (['INBOX' => 'from', (string) $rcmail->config->get('sent_mbox') => 'to'] as $folder => $field) {
            if ($folder === '' || !$storage->folder_exists($folder)) {
                continue;
            }
            foreach ((array) $storage->list_messages($folder, 1, 'arrival', 'DESC') as $h) {
                foreach (rcube_mime::decode_address_list($h->$field ?? '', null, true) as $a) {
                    if (!in_array(mb_strtolower($a['mailto']), $own)) {
                        $add($a['name'] !== $a['mailto'] ? (string) $a['name'] : '', $a['mailto'], $h->timestamp, false);
                    }
                }
            }
        }

        usort($out, function($a, $b) { return $b[2] <=> $a[2]; });
        $_SESSION['tw_mail_contacts'] = ['time' => time(), 'list' => $out];
        return $out;
    }

    /**
     * Contacts page: phone numbers for the listed rows (Roundcube's list sends only name and e-mail).
     * _cids: row ids as the list has them ("ID" or "ID-source"). Output: {row id: first phone}
     */
    function ab_phones()
    {
        $rcmail = rcmail::get_instance();
        $source = rcube_utils::get_input_string('_source', rcube_utils::INPUT_GET);
        $bysrc  = [];

        foreach (explode(',', rcube_utils::get_input_string('_cids', rcube_utils::INPUT_GET)) as $rid) {
            if (preg_match('/^([^-]+)(?:-(.+))?$/', $rid, $m)) {
                $bysrc[$m[2] ?? $source][$m[1]] = $rid;
            }
        }

        $out = [];
        foreach ($bysrc as $src => $ids) {
            $book = $src !== '' ? $rcmail->get_address_book($src) : null;
            if (!$book) {
                continue;
            }
            $result = $book->search($book->primary_key, array_keys($ids), 0, true, true);
            while ($result && ($row = $result->next())) {
                $phones = rcube_addressbook::get_col_values('phone', $row, true);
                if ($phones && isset($ids[$row['ID']])) {
                    $out[$ids[$row['ID']]] = reset($phones);
                }
            }
        }

        $rcmail->output->command('plugin.tw_ab_phones', $out);
        $rcmail->output->send();
    }

    /** "Фильтры" item in the settings menu */
    function settings_actions($args)
    {
        $args['actions'][] = [
            'action' => 'plugin.tw-filters',
            'class'  => 'filters',
            'label'  => 'filters',
            'title'  => 'filters',
            'domain' => 'tw_folders',
        ];
        return $args;
    }

    /** Settings > Фильтры: all folder rules; the page is rendered by the skin's breeze.js */
    function filters_page()
    {
        $rcmail  = rcmail::get_instance();
        $storage = $rcmail->get_storage();
        $folders = [];

        foreach ($storage->list_folders_subscribed('', '*', 'mail') as $folder) {
            if ($folder !== 'INBOX') {
                $folders[$folder] = $rcmail->localize_foldername($folder, false, true);
            }
        }

        $rcmail->output->set_pagetitle($this->gettext('filters'));
        $rcmail->output->set_env('tw_rule_folders', $folders);
        $rcmail->output->add_handler('plugin.body', function() {
            return '<div id="tw-filters" class="tw-filters-page"></div>';
        });
        $rcmail->output->send('plugin');
    }

    /**
     * One BODYSTRUCTURE fetch for the listed messages that may have attachments,
     * sent to the page as {uid: {n: total, files: [[name, size, part, mimetype], ...]}}.
     */
    function messages_list($args)
    {
        $rcmail  = rcmail::get_instance();
        $storage = $rcmail->get_storage();
        $byfolder = [];

        foreach ((array) $args['messages'] as $header) {
            if (!empty($header->ctype) && preg_match('/^multipart\/(mixed|signed|report)/i', $header->ctype)) {
                $folder = !empty($header->folder) ? $header->folder : $storage->get_folder();
                $byfolder[$folder][] = $header->uid;
            }
        }

        $result = [];
        foreach ($byfolder as $folder => $uids) {
            $fetched = $storage->conn->fetch($folder, $uids, true, ['BODYSTRUCTURE']);
            foreach ((array) $fetched as $msg) {
                if (empty($msg->bodystructure)) {
                    continue;
                }
                $files = [];
                $this->walk($msg->bodystructure, '', $files);
                if ($files) {
                    // always "uid-folder": unique across folders; the page maps it to its row
                    $result[$msg->uid . '-' . $folder] = ['n' => count($files), 'files' => array_slice($files, 0, self::MAX_CHIPS)];
                }
            }
        }

        if ($result) {
            $rcmail->output->command('plugin.tw_attachments', $result);
        }

        // the start of the text of messages already read once comes with the list (snippets() caches it),
        // so the third line is there at once; only new messages are asked for after the list
        $search = $storage->get_search_set();
        $multi  = $search && !empty($search[1]->multi);
        $cached = ['asked' => [], 'text' => []];
        $caches = [];
        foreach ((array) $args['messages'] as $h) {
            $folder = !empty($h->folder) ? $h->folder : $storage->get_folder();
            $caches[$folder] = $caches[$folder] ?? $this->snippet_cache($folder);
            if (isset($caches[$folder][$h->uid])) {
                $rowid = $multi ? $h->uid . '-' . $folder : (string) $h->uid; // as Roundcube names the row
                $cached['asked'][] = $rowid;
                $cached['text'][$rowid] = $caches[$folder][$h->uid];
            }
        }
        if ($cached['asked']) {
            $cached['text'] = (object) $cached['text'];
            $rcmail->output->command('plugin.tw_snippets', $cached);
        }

        // "Вложения" / "С вложением" search by Content-Type multipart/mixed, which also matches mail whose only
        // extra part is an inline picture: keep the rows with real files.
        // shortcut: the folder count and pages still come from that search (a few rows less per page)
        $filter = (string) ($_SESSION['search_filter'] ?? '');
        if (!empty($_SESSION['search']) && stripos($filter, 'Content-Type multipart/m') !== false) {
            $folder = $storage->get_folder();
            $args['messages'] = array_values(array_filter((array) $args['messages'], function($h) use ($result, $folder) {
                return isset($result[$h->uid . '-' . (!empty($h->folder) ? $h->folder : $folder)]);
            }));
        }

        return $args;
    }

    /** Collect attachment parts of a parsed BODYSTRUCTURE (IMAP part numbers in $path). */
    private function walk($part, $path, &$files)
    {
        if (!is_array($part)) {
            return;
        }

        // multipart: sub-parts first, then the subtype string
        if (is_array($part[0])) {
            $i = 0;
            foreach ($part as $sub) {
                if (!is_array($sub)) {
                    break;
                }
                $i++;
                $this->walk($sub, $path === '' ? (string) $i : "$path.$i", $files);
            }
            return;
        }

        $type    = strtolower($part[0] . '/' . $part[1]);
        $params  = $this->pairs($part[2] ?? null);
        $size    = (int) ($part[6] ?? 0);
        // disposition sits after type-specific fields
        $dpos    = $type == 'message/rfc822' ? 11 : (strpos($type, 'text/') === 0 ? 9 : 8);
        $disp    = isset($part[$dpos]) && is_array($part[$dpos]) ? $part[$dpos] : null;
        $dtype   = $disp && is_string($disp[0]) ? strtolower($disp[0]) : '';
        $dparams = $disp ? $this->pairs($disp[1] ?? null) : [];

        $name = $this->filename($dparams) ?: $this->filename($params);
        $is_attachment = $dtype == 'attachment'
            || ($name !== '' && !in_array($type, ['text/plain', 'text/html']) && $dtype != 'inline');

        if ($is_attachment) {
            $files[] = [$name !== '' ? $name : $type, $size, $path === '' ? '1' : $path, $type];
        }
    }

    /** IMAP parameter list (k, v, k, v...) to a lowercase-keyed array. */
    private function pairs($list)
    {
        $out = [];
        if (is_array($list)) {
            for ($i = 0; $i + 1 < count($list); $i += 2) {
                if (is_string($list[$i]) && is_string($list[$i + 1])) {
                    $out[strtolower($list[$i])] = $list[$i + 1];
                }
            }
        }
        return $out;
    }

    /** File name from filename / name, RFC 2231 (filename*, filename*0*) and RFC 2047 encodings. */
    private function filename($p)
    {
        foreach (['filename', 'name'] as $key) {
            if (isset($p[$key . '*'])) {
                return $this->rfc2231($p[$key . '*']);
            }
            if (isset($p[$key . '*0*']) || isset($p[$key . '*0'])) {
                $joined = '';
                for ($i = 0; isset($p["$key*$i*"]) || isset($p["$key*$i"]); $i++) {
                    $joined .= $p["$key*$i*"] ?? $p["$key*$i"];
                }
                return isset($p[$key . '*0*']) ? $this->rfc2231($joined) : $joined;
            }
            if (isset($p[$key])) {
                return rcube_mime::decode_mime_string($p[$key], 'UTF-8');
            }
        }
        return '';
    }

    private function rfc2231($value)
    {
        if (preg_match("/^([^']*)'[^']*'(.*)$/", $value, $m)) {
            return rcube_charset::convert(rawurldecode($m[2]), $m[1] ?: 'UTF-8', 'UTF-8');
        }
        return rawurldecode($value);
    }
}
