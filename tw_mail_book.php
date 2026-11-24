<?php

/**
 * Read-only address book "Из писем": people from the address books and the latest mail (tw_folders::collect()).
 * Records: ID = md5 of the e-mail, name, email. Newest correspondence first.
 */
class tw_mail_book extends rcube_addressbook
{
    public $primary_key = 'ID';
    public $readonly    = true;
    public $groups      = false;

    private $name;
    private $loader;
    private $records;
    private $filter;
    private $result;

    function __construct($name, callable $loader)
    {
        $this->name   = $name;
        $this->loader = $loader;
        $this->ready  = true;
    }

    function get_name()
    {
        return $this->name;
    }

    private function records()
    {
        if ($this->records === null) {
            $this->records = [];
            foreach (call_user_func($this->loader) as $c) {
                $this->records[] = ['ID' => md5($c[1]), 'name' => $c[0], 'email' => [$c[1]]];
            }
        }
        return $this->records;
    }

    // the filter is what search() matched: Roundcube keeps it in the session and lists pages of it
    function set_search_set($filter)
    {
        $this->filter = $filter;
    }

    function get_search_set()
    {
        return $this->filter;
    }

    function reset()
    {
        $this->result = null;
        $this->filter = null;
    }

    private function matching()
    {
        $records = $this->records();
        if (is_array($this->filter)) {
            $ids = array_flip($this->filter);
            $records = array_values(array_filter($records, function($r) use ($ids) { return isset($ids[$r['ID']]); }));
        }
        return $records;
    }

    function list_records($cols = null, $subset = 0, $nocount = false)
    {
        $all   = $this->matching();
        $first = ($this->list_page - 1) * $this->page_size;
        $this->result = new rcube_result_set(count($all), $first);
        foreach (array_slice($all, $first, $this->page_size) as $r) {
            $this->result->add($r);
        }
        return $this->result;
    }

    function search($fields, $value, $mode = 0, $select = true, $nocount = false, $required = [])
    {
        if ($fields == $this->primary_key) {
            $ids = array_flip((array) $value);
            $hit = function($r) use ($ids) { return isset($ids[$r['ID']]); };
        }
        else {
            $needle = mb_strtolower(trim(is_array($value) ? implode(' ', $value) : (string) $value));
            $hit = function($r) use ($needle) {
                return $needle === '' || strpos(mb_strtolower($r['name'] . ' ' . $r['email'][0]), $needle) !== false;
            };
        }

        $found = array_values(array_filter($this->records(), $hit));
        $this->filter = array_column($found, 'ID'); // the contacts page searches with $select = false, then lists the filter
        if ($select) {
            return $this->list_records();
        }

        $result = new rcube_result_set(count($found));
        foreach ($found as $r) {
            $result->add($r);
        }
        return $result;
    }

    function count()
    {
        return new rcube_result_set(count($this->matching()), ($this->list_page - 1) * $this->page_size);
    }

    function get_result()
    {
        return $this->result;
    }

    function get_record($id, $assoc = false)
    {
        foreach ($this->records() as $r) {
            if ($r['ID'] === $id) {
                if ($assoc) {
                    return $r;
                }
                $this->result = new rcube_result_set(1);
                $this->result->add($r);
                return $this->result;
            }
        }
        return $assoc ? false : new rcube_result_set(0);
    }

    function get_record_groups($id)
    {
        return [];
    }

    function set_group($gid)
    {
    }
}
