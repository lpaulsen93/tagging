<?php
/**
 * Tagging Plugin (hlper component)
 *
 * @license GPL 2
 */
class helper_plugin_tagging extends DokuWiki_Plugin {

    /**
     * Gives access to the database
     *
     * Initializes the SQLite helper and register the CLEANTAG function
     *
     * @return helper_plugin_sqlite|bool false if initialization fails
     */
    public function getDB() {
        static $db = null;
        if ($db !== null) {
            return $db;
        }

        /** @var helper_plugin_sqlite $db */
        $db = plugin_load('helper', 'sqlite');
        if ($db === null) {
            msg('The tagging plugin needs the sqlite plugin', -1);

            return false;
        }
        $db->init('tagging', __DIR__ . '/db/');
        $db->create_function('CLEANTAG', array($this, 'cleanTag'), 1);
        $db->create_function('GROUP_SORT',
            function ($group, $newDelimiter) {
                $ex = array_filter(explode(',', $group));
                sort($ex);

                return implode($newDelimiter, $ex);
            }, 2);
        $db->create_function('GET_NS', 'getNS', 1);

        return $db;
    }

    /**
     * Return the user to use for accessing tags
     *
     * Handles the singleuser mode by returning 'auto' as user. Returnes false when no user is logged in.
     *
     * @return bool|string
     */
    public function getUser() {
        if (!isset($_SERVER['REMOTE_USER'])) {
            return false;
        }
        if ($this->getConf('singleusermode')) {
            return 'auto';
        }

        return $_SERVER['REMOTE_USER'];
    }

    /**
     * Canonicalizes the tag to its lower case nospace form
     *
     * @param $tag
     *
     * @return string
     */
    public function cleanTag($tag) {
        $tag = str_replace(array(' ', '-', '_'), '', $tag);
        $tag = utf8_strtolower($tag);

        return $tag;
    }

    /**
     * Canonicalizes the namespace, remove the first colon and add glob
     *
     * @param $namespace
     *
     * @return string
     */
    public function globNamespace($namespace) {
        return cleanId($namespace) . '*';
    }

    /**
     * Create or Update tags of a page
     *
     * Uses the translation plugin to store the language of a page (if available)
     *
     * @param string $id The page ID
     * @param string $user
     * @param array  $tags
     *
     * @return bool|SQLiteResult
     */
    public function replaceTags($id, $user, $tags) {
        global $conf;
        /** @var helper_plugin_translation $trans */
        $trans = plugin_load('helper', 'translation');
        if ($trans) {
            $lang = $trans->realLC($trans->getLangPart($id));
        } else {
            $lang = $conf['lang'];
        }

        $db = $this->getDB();
        $db->query('BEGIN TRANSACTION');
        $queries = array(array('DELETE FROM taggings WHERE pid = ? AND tagger = ?', $id, $user));
        foreach ($tags as $tag) {
            $queries[] = array('INSERT INTO taggings (pid, tagger, tag, lang) VALUES(?, ?, ?, ?)', $id, $user, $tag, $lang);
        }

        foreach ($queries as $query) {
            if (!call_user_func_array(array($db, 'query'), $query)) {
                $db->query('ROLLBACK TRANSACTION');

                return false;
            }
        }

        return $db->query('COMMIT TRANSACTION');
    }

    /**
     * Get a list of Tags or Pages matching search criteria
     *
     * @param array  $filter What to search for array('field' => 'searchterm')
     * @param string $type   What field to return 'tag'|'pid'
     * @param int    $limit  Limit to this many results, 0 for all
     *
     * @return array associative array in form of value => count
     */
    public function findItems($filter, $type, $limit = 0) {

        global $INPUT;

        /** @var helper_plugin_tagging_querybuilder $queryBuilder */
        $queryBuilder = new \helper_plugin_tagging_querybuilder();

        $queryBuilder->setField($type);
        $queryBuilder->setLimit($limit);
        $queryBuilder->setTags($this->getTags($filter));
        if (isset($filter['ns'])) $queryBuilder->includeNS($filter['ns']);
        if (isset($filter['notns'])) $queryBuilder->excludeNS($filter['notns']);
        if (isset($filter['tagger'])) $queryBuilder->setTagger($filter['tagger']);
        if (isset($filter['pid'])) $queryBuilder->setPid($filter['pid']);

        return $this->queryDb($queryBuilder->getQuery());

    }

    /**
     * Constructs the URL to search for a tag
     *
     * @param string $tag
     * @param string $ns
     *
     * @return string
     */
    public function getTagSearchURL($tag, $ns = '') {
        // wrap tag in quotes if non clean
        $ctag = utf8_stripspecials($this->cleanTag($tag));
        if ($ctag != utf8_strtolower($tag)) {
            $tag = '"' . $tag . '"';
        }

        $ret = '?do=search&sf=1&id=' . rawurlencode($tag);
        if ($ns) {
            $ret .= rawurlencode(' @' . $ns);
        }

        return $ret;
    }

    /**
     * Calculates the size levels for the given list of clouds
     *
     * Automatically determines sensible tresholds
     *
     * @param array $tags list of tags => count
     * @param int   $levels
     *
     * @return mixed
     */
    public function cloudData($tags, $levels = 10) {
        $min = min($tags);
        $max = max($tags);

        // calculate tresholds
        $tresholds = array();
        for ($i = 0; $i <= $levels; $i++) {
            $tresholds[$i] = pow($max - $min + 1, $i / $levels) + $min - 1;
        }

        // assign weights
        foreach ($tags as $tag => $cnt) {
            foreach ($tresholds as $tresh => $val) {
                if ($cnt <= $val) {
                    $tags[$tag] = $tresh;
                    break;
                }
                $tags[$tag] = $levels;
            }
        }

        return $tags;
    }

    /**
     * Display a tag cloud
     *
     * @param array    $tags   list of tags => count
     * @param string   $type   'tag'
     * @param Callable $func   The function to print the link (gets tag and ns)
     * @param bool     $wrap   wrap cloud in UL tags?
     * @param bool     $return returnn HTML instead of printing?
     * @param string   $ns     Add this namespace to search links
     *
     * @return string
     */
    public function html_cloud($tags, $type, $func, $wrap = true, $return = false, $ns = '') {
        global $INFO;

        $hidden_str = $this->getConf('hiddenprefix');
        $hidden_len = strlen($hidden_str);

        $ret = '';
        if ($wrap) {
            $ret .= '<ul class="tagging_cloud clearfix">';
        }
        if (count($tags) === 0) {
            // Produce valid XHTML (ul needs a child)
            $this->setupLocale();
            $ret .= '<li><div class="li">' . $this->lang['js']['no' . $type . 's'] . '</div></li>';
        } else {
            $tags = $this->cloudData($tags);
            foreach ($tags as $val => $size) {
                // skip hidden tags for users that can't edit
                if ($type === 'tag' and
                    $hidden_len and
                    substr($val, 0, $hidden_len) == $hidden_str and
                    !($this->getUser() && $INFO['writable'])
                ) {
                    continue;
                }

                $ret .= '<li class="t' . $size . '"><div class="li">';
                $ret .= call_user_func($func, $val, $ns);
                $ret .= '</div></li>';
            }
        }
        if ($wrap) {
            $ret .= '</ul>';
        }
        if ($return) {
            return $ret;
        }
        echo $ret;

        return '';
    }

    /**
     * Get the link to a search for the given tag
     *
     * @param string $tag search for this tag
     * @param string $ns  limit search to this namespace
     *
     * @return string
     */
    protected function linkToSearch($tag, $ns = '') {
        return '<a href="' . hsc($this->getTagSearchURL($tag, $ns)) . '">' . $tag . '</a>';
    }

    /**
     * Display the Tags for the current page and prepare the tag editing form
     *
     * @param bool $print Should the HTML be printed or returned?
     *
     * @return string
     */
    public function tpl_tags($print = true) {
        global $INFO;
        global $lang;

        $filter = array('pid' => $INFO['id']);
        if ($this->getConf('singleusermode')) {
            $filter['tagger'] = 'auto';
        }

        $tags = $this->findItems($filter, 'tag');

        $ret = '';

        $ret .= '<div class="plugin_tagging_edit">';
        $ret .= $this->html_cloud($tags, 'tag', array($this, 'linkToSearch'), true, true);

        if ($this->getUser() && $INFO['writable']) {
            $lang['btn_tagging_edit'] = $lang['btn_secedit'];
            $ret .= '<div id="tagging__edit_buttons_group">';
            $ret .= html_btn('tagging_edit', $INFO['id'], '', array());
            if (auth_isadmin()) {
                $ret .= '<label>' . $this->getLang('toggle admin mode') . '<input type="checkbox" id="tagging__edit_toggle_admin" /></label>';
            }
            $ret .= '</div>';
            $form = new dokuwiki\Form\Form();
            $form->id('tagging__edit');
            $form->setHiddenField('tagging[id]', $INFO['id']);
            $form->setHiddenField('call', 'plugin_tagging_save');
            $tags = $this->findItems(array(
                'pid'    => $INFO['id'],
                'tagger' => $this->getUser(),
            ), 'tag');
            $form->addTextarea('tagging[tags]')->val(implode(', ', array_keys($tags)))->addClass('edit')->attr('rows', 4);
            $form->addButton('', $lang['btn_save'])->id('tagging__edit_save');
            $form->addButton('', $lang['btn_cancel'])->id('tagging__edit_cancel');
            $ret .= $form->toHTML();
        }
        $ret .= '</div>';

        if ($print) {
            echo $ret;
        }

        return $ret;
    }

    /**
     * @param string $namespace empty for entire wiki
     *
     * @param string $order_by
     * @param bool $desc
     * @param array $filters
     * @return array
     */
    public function getAllTags($namespace = '', $order_by = 'tag', $desc = false, $filters = []) {
        $order_fields = array('pid', 'tid', 'orig', 'taggers', 'ns', 'count');
        if (!in_array($order_by, $order_fields)) {
            msg('cannot sort by ' . $order_by . ' field does not exists', -1);
            $order_by = 'tag';
        }

        list($having, $params) = $this->getFilterSql($filters);

        $db = $this->getDb();

        $query = 'SELECT    "pid",
                            CLEANTAG("tag") AS "tid",
                            GROUP_SORT(GROUP_CONCAT("tag"), \', \') AS "orig",
                            GROUP_SORT(GROUP_CONCAT("tagger"), \', \') AS "taggers",
                            GROUP_SORT(GROUP_CONCAT(GET_NS("pid")), \', \') AS "ns",
                            GROUP_SORT(GROUP_CONCAT("pid"), \', \') AS "pids",
                            COUNT(*) AS "count"
                        FROM "taggings"
                        WHERE "pid" GLOB ? AND GETACCESSLEVEL(pid) >= ' . AUTH_READ
                        . ' GROUP BY "tid"';
        $query .= $having;
        $query .=      'ORDER BY ' . $order_by;
        if ($desc) {
            $query .= ' DESC';
        }

        array_unshift($params, $this->globNamespace($namespace));
        $res = $db->query($query, $params);

        return $db->res2arr($res);
    }

    /**
     * Get all pages with tags and their tags
     *
     * @return array ['pid' => ['tag1','tag2','tag3']]
     */
    public function getAllTagsByPage() {
        $query = '
        SELECT pid, GROUP_CONCAT(tag) AS tags
        FROM taggings
        GROUP BY pid
        ';
        $db = $this->getDb();
        $res = $db->query($query);
        return array_map(
            function ($i) {
                return explode(',', $i);
            },
            array_column($db->res2arr($res), 'tags', 'pid')
        );
    }

    /**
     * Renames a tag
     *
     * @param string $formerTagName
     * @param string $newTagNames
     */
    public function renameTag($formerTagName, $newTagNames) {

        if (empty($formerTagName) || empty($newTagNames)) {
            msg($this->getLang("admin enter tag names"), -1);

            return;
        }

        // enable splitting tags on rename
        $newTagNames = explode(',', $newTagNames);

        $db = $this->getDB();

        $res = $db->query('SELECT pid FROM taggings WHERE CLEANTAG(tag) = ?', $this->cleanTag($formerTagName));
        $check = $db->res2arr($res);

        if (empty($check)) {
            msg($this->getLang("admin tag does not exists"), -1);

            return;
        }

        // non-admins can rename only their own tags
        if (!auth_isadmin()) {
            $queryTagger =' AND tagger = ?';
            $tagger = $this->getUser();
        } else {
            $queryTagger = '';
            $tagger = '';
        }

        // TODO insert-and-delete transaction instead of update
        foreach ($newTagNames as $tag) {
            $query = "UPDATE taggings SET tag = ? WHERE CLEANTAG(tag) = ? AND GETACCESSLEVEL(pid) >= " . AUTH_EDIT;
            $query .= $queryTagger;
            $params = [$this->cleanTag($tag), $this->cleanTag($formerTagName)];
            if ($tagger) array_push($params, $tagger);
            $res = $db->query($query, $params);
            $db->res2arr($res);
        }

        msg($this->getLang("admin renamed"), 1);

        return;
    }

    /**
     * Rename or delete a tag for all users
     *
     * @param string $pid
     * @param string $formerTagName
     * @param string $newTagName
     *
     * @return array
     */
    public function modifyPageTag($pid, $formerTagName, $newTagName) {

        $db = $this->getDb();

        $res = $db->query('SELECT pid FROM taggings WHERE CLEANTAG(tag) = ? AND pid = ?', $this->cleanTag($formerTagName), $pid);
        $check = $db->res2arr($res);

        if (empty($check)) {
            return array(true, $this->getLang('admin tag does not exists'));
        }

        if (empty($newTagName)) {
            $res = $db->query('DELETE FROM taggings WHERE pid = ? AND CLEANTAG(tag) = ?', $pid, $this->cleanTag($formerTagName));
        } else {
            $res = $db->query('UPDATE taggings SET tag = ? WHERE pid = ? AND CLEANTAG(tag) = ?', $newTagName, $pid, $this->cleanTag($formerTagName));
        }
        $db->res2arr($res);

        return array(false, $this->getLang('admin renamed'));
    }

    /**
     * Deletes a tag
     *
     * @param array  $tags
     * @param string $namespace current namespace context as in getAllTags()
     */
    public function deleteTags($tags, $namespace = '') {
        if (empty($tags)) {
            return;
        }

        $namespace = cleanId($namespace);

        $db = $this->getDB();

        $queryBody = 'FROM taggings WHERE pid GLOB ? AND (' .
            implode(' OR ', array_fill(0, count($tags), 'CLEANTAG(tag) = ?')) . ')';
        $args = array_map(array($this, 'cleanTag'), $tags);
        array_unshift($args, $this->globNamespace($namespace));

        // non-admins can delete only their own tags
        if (!auth_isadmin()) {
            $queryBody .= ' AND tagger = ?';
            array_push($args, $this->getUser());
        }

        $affectedPagesQuery= 'SELECT DISTINCT pid ' . $queryBody;
        $resAffectedPages = $db->query($affectedPagesQuery, $args);
        $numAffectedPages = count($resAffectedPages->fetchAll());

        $deleteQuery = 'DELETE ' . $queryBody;
        $db->query($deleteQuery, $args);

        msg(sprintf($this->getLang("admin deleted"), count($tags), $numAffectedPages), 1);
    }

    /**
     * Updates tags with a new page name
     *
     * @param string $oldName
     * @param string $newName
     */
    public function renamePage($oldName, $newName) {
        $db = $this->getDb();
        $db->query('UPDATE taggings SET pid = ? WHERE pid = ?', $newName, $oldName);
    }

    /**
     * Extracts tags from search query
     *
     * @param array $parsedQuery
     * @return array
     */
    public function getTags($parsedQuery)
    {
        $tags = [];
        if (isset($parsedQuery['phrases'][0])) {
            $tags = $parsedQuery['phrases'];
        } elseif (isset($parsedQuery['and'][0])) {
            $tags = $parsedQuery['and'];
        } elseif (isset($parsedQuery['tag'])) {
            // handle autocomplete call
            $tags[] = $parsedQuery['tag'];
        }
        return $tags;
    }

    /**
     * Search for tagged pages
     *
     * @return array
     */
    public function searchPages()
    {
        global $INPUT;
        global $QUERY;
        $parsedQuery = ft_queryParser(new Doku_Indexer(), $QUERY);

        /** @var helper_plugin_tagging_querybuilder $queryBuilder */
        $queryBuilder = new \helper_plugin_tagging_querybuilder();

        $queryBuilder->setField('pid');
        $queryBuilder->setTags($this->getTags($parsedQuery));
        $queryBuilder->setLogicalAnd($INPUT->str('taggings') === 'and');
        if (isset($parsedQuery['ns'])) $queryBuilder->includeNS($parsedQuery['ns']);
        if (isset($parsedQuery['notns'])) $queryBuilder->excludeNS($parsedQuery['notns']);
        if (isset($parsedQuery['tagger'])) $queryBuilder->setTagger($parsedQuery['tagger']);
        if (isset($parsedQuery['pid'])) $queryBuilder->setPid($parsedQuery['pid']);

        return $this->queryDb($queryBuilder->getPages());
    }

    /**
     * Syntax to allow users to manage tags on regular pages, respects ACLs
     * @param string $ns
     * @return string
     */
    public function manageTags($ns)
    {
        global $INPUT;

        //by default sort by tag name
        if (!$INPUT->has('sort')) {
            $INPUT->set('sort', 'tid');
        }

        // initially set namespace filter to what is defined in syntax
        if ($ns && !$INPUT->has('tagging__filters')) {
            $INPUT->set('tagging__filters', ['ns' => $ns]);
        }

        return $this->html_table();
    }

    /**
     * Display tag management table
     */
    public function html_table() {
        global $ID, $INPUT;

        $headers = array(
            array('value' => $this->getLang('admin tag'), 'sort_by' => 'tid'),
            array('value' => $this->getLang('admin occurrence'), 'sort_by' => 'count'),
            array('value' => $this->getLang('admin writtenas'), 'sort_by' => 'orig'),
            array('value' => $this->getLang('admin namespaces'), 'sort_by' => 'ns'),
            array('value' => $this->getLang('admin taggers'), 'sort_by' => 'taggers'),
            array('value' => $this->getLang('admin actions'), 'sort_by' => false),
        );

        $sort = explode(',', $INPUT->str('sort'));
        $order_by = $sort[0];
        $desc = false;
        if (isset($sort[1]) && $sort[1] === 'desc') {
            $desc = true;
        }
        $filters = $INPUT->arr('tagging__filters');

        $tags = $this->getAllTags($INPUT->str('filter'), $order_by, $desc, $filters);

        $form = new dokuwiki\Form\Form();
        $form->setHiddenField('page', 'tagging');
        $form->setHiddenField('id', $ID);
        $form->setHiddenField('sort', $INPUT->str('sort'));

        /**
         * Actions dialog
         */
        $form->addTagOpen('div')->id('tagging__action-dialog')->attr('style', "display:none;");
        $form->addTagClose('div');

        /**
         * Tag pages dialog
         */
        $form->addTagOpen('div')->id('tagging__taggedpages-dialog')->attr('style', "display:none;");
        $form->addTagClose('div');

        /**
         * Tag management table
         */
        $form->addTagOpen('table')->addClass('inline plugin_tagging');

        /**
         * Table headers
         */
        $form->addTagOpen('tr');
        foreach ($headers as $header) {
            $form->addTagOpen('th');
            if ($header['sort_by'] !== false) {
                $param = $header['sort_by'];
                $icon = 'arrow-both';
                $title = $this->getLang('admin sort ascending');
                if ($header['sort_by'] === $order_by) {
                    if ($desc === false) {
                        $icon = 'arrow-up';
                        $title = $this->getLang('admin sort descending');
                        $param .= ',desc';
                    } else {
                        $icon = 'arrow-down';
                    }
                }
                $form->addButtonHTML("fn[sort][$param]", $header['value'] . ' ' . inlineSVG(dirname(__FILE__) . "/images/$icon.svg"))
                    ->addClass('plugin_tagging sort_button')
                    ->attr('title', $title);
            } else {
                $form->addHTML($header['value']);
            }
            $form->addTagClose('th');
        }
        $form->addTagClose('tr');

        /**
         * Table filters for all sortable columns
         */
        $form->addTagOpen('tr');
        foreach ($headers as $header) {
            $form->addTagOpen('th');
            if ($header['sort_by'] !== false) {
                $field = $header['sort_by'];
                $form->addTextInput("tagging__filters[$field]");
            }
            $form->addTagClose('th');
        }
        $form->addTagClose('tr');


        foreach ($tags as $taginfo) {
            $tagname = $taginfo['tid'];
            $taggers = $taginfo['taggers'];
            $written = $taginfo['orig'];
            $ns = $taginfo['ns'];
            $pids = explode(',',$taginfo['pids']);

            $form->addTagOpen('tr');
            $form->addHTML('<td><a class="tagslist" href="#" data-pids="' . $taginfo['pids'] . '">' . hsc($tagname) . '</a></td>');
            $form->addHTML('<td>' . $taginfo['count'] . '</td>');
            $form->addHTML('<td>' . hsc($written) . '</td>');
            $form->addHTML('<td>' . hsc($ns) . '</td>');
            $form->addHTML('<td>' . hsc($taggers) . '</td>');

            /**
             * action buttons
             */
            $form->addHTML('<td>');

            // check ACLs
            $userEdit = false;
            /** @var \helper_plugin_sqlite $sqliteHelper */
            $sqliteHelper = plugin_load('helper', 'sqlite');
            foreach ($pids as $pid) {
                if ($sqliteHelper->_getAccessLevel($pid) >= AUTH_EDIT) {
                    $userEdit = true;
                    continue;
                }
            }

            if ($userEdit) {
                $form->addButtonHTML('fn[actions][rename][' . $taginfo['tid'] . ']', inlineSVG(dirname(__FILE__) . '/images/edit.svg'))
                    ->addClass('plugin_tagging action_button')->attr('data-action', 'rename')->attr('data-tid', $taginfo['tid']);
                $form->addButtonHTML('fn[actions][delete][' . $taginfo['tid'] . ']', inlineSVG(dirname(__FILE__) . '/images/delete.svg'))
                    ->addClass('plugin_tagging action_button')->attr('data-action', 'delete')->attr('data-tid', $taginfo['tid']);
            }

            $form->addHTML('</td>');
            $form->addTagClose('tr');
        }

        $form->addTagClose('table');
        return $form->toHTML();
    }

    /**
     * Executes the query and returns the results as array
     *
     * @param array $query
     * @return array
     */
    protected function queryDb($query)
    {
        $db = $this->getDB();
        if (!$db) {
            return [];
        }

        $res = $db->query($query[0], $query[1]);
        $res = $db->res2arr($res);

        $ret = [];
        foreach ($res as $row) {
            $ret[$row['item']] = $row['cnt'];
        }
        return $ret;
    }

    /**
     * Construct the HAVING part of the search query
     *
     * @param array $filters
     * @return array
     */
    protected function getFilterSql($filters)
    {
        $having = '';
        $parts = [];
        $params = [];
        $filters = array_filter($filters);
        if (!empty($filters)) {
            $having = ' HAVING ';
            foreach ($filters as $filter => $value) {
                $parts[] = " $filter LIKE ? ";
                $params[] = "%$value%";
            }
            $having .= implode(' AND ', $parts);
        }
        return [$having, $params];
    }
}
