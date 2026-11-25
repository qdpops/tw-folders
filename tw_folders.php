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
    public $allowed_prefs = ['tw_folder_style', 'tw_preview', 'tw_split', 'tw_rules', 'addressbook_sort_col']; // the last one: sort select on the contacts page

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

        $this->add_texts('localization/', true); // the skin's templates and breeze.js use them on every page
        $rcmail->config->set('product_name', $this->gettext('product_name')); // config/custom.inc.php has one language

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
