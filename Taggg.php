<?php
/**
 * Taggg - simple multiuser meta tagging system.
 *
 * Allows storing meta tags about resources in a PDO database.
 * Any resource can be tagged. Tagging = creating a relation among
 * four resources: subject, predicate, object, creator. Every resource
 * is identified by internal id (no uri needed).
 * Tags are stored in two tables: res (resource) and rel (relation):
 * res = {id, uri, class, value, content}
 * rel = {subject, predicate, object, creator, created}
 *
 * Method summary:
 *
 * init() - creates (if not exist) db tables
 * destroy() - deletes (if exist) db tables and all data
 * write(subject,predicate,object,creator) - creates/updates tag
 * erase(subject,predicate,object,creator) - deletes tag
 *
 * @author JaZzy JunGgle, junggle@centrum.cz
 * @copyright (c)2008, JaZzy JunGgle. All rights reserved.
 * @license   http://www.opensource.org/licenses/bsd-license.php New BSD License
 */

class Taggg
{
    const RES_EMPTY = 0;    // empty resource id
    const RES_TAGGG = 1;    // root resource id
    const RES_CLASS = 2;    // class resource id
    
    /**
     * PDO database.
     * @var PDO
     */
    public $pdo;
    
    /**
     * Resource table name.
     * @var string
     */
    public $tblRes = 'res';

    /**
     * Relation table name.
     * @var string
     */
    public $tblRel = 'rel';
    
    /**
     * Limit for fetching records.
     * @var int
     */
    public $limit = 0;
    
    /**
     * Offset for fetching records.
     * @var int
     */
    public $offset = 0;
    
    /**
     * Filters for fetching records.
     * @var array
     */
    public $filters = array();
    
    /**
     * Orders for fetching records.
     * @var array
     */
    public $orders = array();
    



    /**
     * Constructor. Sets attributes from an array.
     *
     * @param array attributes (pdo, tblRes='res', tblRel='rel')
     * @throws Exception if no valid pdo object passed
     */
    public function __construct($attrs = array())
    {
        if ($attrs instanceof PDO)
        {
            $attrs = array('pdo' => $attrs);
        }

        foreach ($attrs as $k=>$v)
        {
            $this->$k = $v;
        }

        if (!($this->pdo instanceof PDO))
        {
            throw new Exception(__METHOD__ . ' error: no valid pdo object');
        }

        if (!preg_match('/^\w+$/', (string)$this->tblRes))
        {
            throw new Exception(__METHOD__ . ' error: invalid name for tblRes');
        }

        if (!preg_match('/^\w+$/', (string)$this->tblRel))
        {
            throw new Exception(__METHOD__ . ' error: invalid name for tblRel');
        }
    }




    /**
     * Parses various resource representations and eturns array of
     * resource attributes (id,uri,class,value,content).
     *
     * @param array|int|string attrs|id|"uri:<value>"|"<class>:<value>"
     * @return array(id,uri,class,value,content)|null (no data/not found)|false (PDO error)
     */
    protected function _parseResource($input)
    {
        $res = array('id'=>null, 'uri'=>null, 'class'=>null, 'value'=>null, 'content'=>null);
        if (is_array($input))
        {// array(uri,class,value,content)
            $res = array_merge($res, array_intersect_key($input, $res));
            $res['id'] = null;
        }
        elseif (is_int($input))
        {// id
            $res['id'] = $input;
        }
        elseif (is_string($input) && ('uri:' == substr($input, 0, 4)))
        {// uri
            $res['uri'] = (string)substr($input, 4);
        }
        elseif (is_string($input))
        {// [class:]value - parse the string to obtain class and value
            // split $input on first non-escaped colon:
            if (count($paramElements=preg_split('/(?<!\\\\)\:/',$input,2))==2)
            {
                // strip colon-escaping and assign resource attributes:
                $res['class'] = preg_replace('/\\\\:/',':',$paramElements[0]);
                $res['value'] = preg_replace('/\\\\:/',':',$paramElements[1]);
            }
            else
            {
                // strip colon-escaping and assign resource attributes:
                $res['value'] = preg_replace('/\\\\:/',':',$input);
            }
        }
        return $res;
    }




    /**
     * Fetches resource by attributes or id.
     *
     * @param array|int attrs or id
     * @return array(id,uri,class,value,content)|null (no data/not found)|false (PDO error)
     */
    protected function _fetchResource($attrs)
    {
        // init params:
        if (is_int($attrs))
        {
            $attrs = array('id'=>$attrs);
        }

        if (isset($attrs['id']) && (!is_int($attrs['id']) || ($attrs['id'] < 1)))
        {// invalid id
            return null;
        }
        
        // if class given by name, convert to id:
        if (isset($attrs['class']) && is_string($attrs['class']))
        {
            if ($classRes = $this->_fetchResource(array('class'=>self::RES_CLASS, 'value'=>$attrs['class'])))
            {// class found, use id
                $attrs['class'] = (int)$classRes['id'];
            }
            else
            {// class not found -> resource can't be fetched
                return null;
            }
        }
        elseif (isset($attrs['class']) && (!is_int($attrs['class']) || ($attrs['class'] < 1)))
        {// invalid class
            throw new Exception(__METHOD__ . " error: invalid class specified ({$attrs['class']})");
        }

        // return special resources without db query (to improve performance):
        if
        (
            isset($attrs['id'])    
            && !isset($attrs['uri'])
            && !isset($attrs['class'])
            && !isset($attrs['value'])
            && !isset($attrs['content'])
        )
        {
            switch ($attrs['id'])
            {
                case self::RES_TAGGG:
                    return array('id'=>$id, 'uri'=>null, 'class'=>self::RES_TAGGG, 'value'=>'taggg', 'content'=>null);
                    break;
                case self::RES_CLASS:
                    return array('id'=>$id, 'uri'=>null, 'class'=>self::RES_TAGGG, 'value'=>'class', 'content'=>null);
                    break;
                case self::RES_EMPTY:
                    return array('id'=>$id, 'uri'=>null, 'class'=>self::RES_TAGGG, 'value'=>'empty', 'content'=>null);
                    break;
                default:
                    // need to fetch from db
            }
        }

        // create sql:
        $sqlParts = array();
        foreach (array('id', 'uri', 'class', 'value', 'content') as $attr)
        {
            if (isset($attrs[$attr]))
            {
                $sqlParts[] = "`{$attr}`=" . $this->pdo->quote($attrs[$attr]);
            }
        }

        // execute sql:
        if ($sqlParts)
        {
            $sql = "SELECT * FROM `{$this->tblRes}` WHERE (" . join(') AND (', $sqlParts) . ')';
            if ($stmt = $this->pdo->query($sql))
            {
                if ($res = $stmt->fetch(PDO::FETCH_ASSOC))
                {// found, fix types:
                    $res['id'] = (int)$res['id'];
                    $res['class'] = (int)$res['class'];
                    return $res;
                }
                else
                {// not found
                    return null;
                }
            }
            else
            {// query error
                return $stmt;
            }
        }

        return null;
    }




    /**
     * Creates resource from attributes in db and returns it.
     *
     * Class resource is created if not exists
     *
     * @param array attrs (uri, class, value, content)
     * @return array attrs (id,uri,class,value,content)|0 (duplicate uri)|null (no data)|false (PDO error)
     */
    protected function _createResource($attrs)
    {
        // if class given by name, convert to id (creating new class if not exists):
        if (isset($attrs['class']) && is_string($attrs['class']))
        {
            if ($classRes = $this->_fetchResource(array('class'=>self::RES_CLASS, 'value'=>$attrs['class'])))
            {// class found, use id
                $attrs['class'] = (int)$classRes['id'];
            }
            elseif (!($attrs['class'] = $this->_createResource(array('class'=>self::RES_CLASS, 'value'=>$attrs['class']))))
            {// class not found and could not be created
                throw new Exception(__METHOD__ . ' error: class resource creation failed');
            }
        }
        elseif (isset($attrs['class']) && (!is_int($attrs['class']) || ($attrs['class'] < 1)))
        {// invalid class
            return null;
        }

        // create sql:
        $sqlParts = array();
        foreach (array('uri', 'class', 'value', 'content') as $attr)
        {
            if (isset($attrs[$attr]))
            {
                $sqlParts[] = "`{$attr}`=" . $this->pdo->quote((string)$attrs[$attr]);
            }
        }

        // execute sql:
        if ($sqlParts)
        {
            $sql = "INSERT IGNORE INTO `{$this->tblRes}` SET " . join(',', $sqlParts);
            if ($ret = $this->pdo->exec($sql))
            {
                $attrs['id'] = (int)$this->pdo->lastInsertId();
                return $attrs;
            }
        }

        return null;
    }




    /**
     * Updates resource by id.
     *
     * Class resource is created if not exists
     *
     * @param int id
     * @param array attrs (uri, class, value, content)
     * @return int (num rows affected)|null (no data)|false (PDO error)
     */
    protected function _updateResource($id, $attrs)
    {
        // if class given by name, convert to id (creating new class if not exists):
        if (isset($attrs['class']) && is_string($attrs['class']))
        {
            if ($classRes = $this->_fetchResource(array('class'=>self::RES_CLASS, 'value'=>$attrs['class'])))
            {// class found, use id
                $attrs['class'] = (int)$classRes['id'];
            }
            elseif (!($attrs['class'] = $this->_createResource(array('class'=>self::RES_CLASS, 'value'=>$attrs['class']))))
            {// class not found and could not be created
                throw new Exception(__METHOD__ . ' error: class resource creation failed');
            }
        }
        elseif (isset($attrs['class']) && (!is_int($attrs['class']) || ($attrs['class'] < 1)))
        {// invalid class
            return null;
        }

        // create sql:
        $sqlParts = array();
        foreach (array('uri', 'class', 'value', 'content') as $attr)
        {
            if (isset($attrs[$attr]))
            {
                $sqlParts[] = "`{$attr}`=" . $this->pdo->quote((string)$attrs[$attr]);
            }
        }

        // execute sql:
        if ($sqlParts)
        {
            $sql = "UPDATE `{$this->tblRes}` SET " . join(',', $sqlParts) . " WHERE `id`=" . (int)$id;
            return $this->pdo->exec($sql);
        }

        return null;
    }




    /**
     * Deletes resource by id.
     *
     * @param int id
     * @return int (num rows affected)|false (PDO error)
     */
    protected function _deleteResource($id)
    {
        $sql = "DELETE FROM `{$this->tblRes}` WHERE `id`=" . (int)$id;
        return $this->pdo->exec($sql);
    }




    /**
     * Returns relation specified by (subject,predicate,object,creator).
     *
     * @param id|null $subject
     * @param id|null $predicate
     * @param id|null $object
     * @param id|null $creator
     * @return array(subject,predicate,object,creator)|null (not found)|false (PDO error)
     * @throws Exception on invalid id
     */
     protected function _fetchRelation($subject=null, $predicate=null, $object=null, $creator=null)
     {
        // create sql:
        $sqlParts = array();
        foreach (array('subject', 'predicate', 'object', 'creator') as $attr)
        {
            if (is_int($$attr) && ($$attr > 0))
            {
                $sqlParts[] =  "`{$attr}`=" . $$attr;
            }
            elseif (is_null($$attr))
            {
                $sqlParts[] = "`{$attr}`=" . self::RES_EMPTY;
            }
            else
            {// invalid id
                throw new Exception(__METHOD__ . " error: unsigned int or null required for $attr id");
            }
        }

        // execute sql:
        $sql = "SELECT * FROM `{$this->tblRel}` WHERE (" . join(') AND (', $sqlParts) . ')';
        if ($stmt = $this->pdo->query($sql))
        {
            if ($rel = $stmt->fetch(PDO::FETCH_ASSOC))
            {// found, fix types:
                $rel['subject'] = (int)$rel['subject'];
                $rel['predicate'] = (int)$rel['predicate'];
                $rel['object'] = (int)$rel['object'];
                $rel['creator'] = (int)$rel['creator'];
                return $rel;
            }
            else
            {// not found
                return null;
            }
        }
        else
        {// query error
            return $stmt;
        }
     }
     
 


    /**
     * Creates relation.
     *
     * @param int|null $subject
     * @param int|null $predicate
     * @param int|null $object
     * @param int|null $creator
     * @return int (num rows affected)|false (PDO error)
     * @throws Exception on invalid id
     */
    protected function _createRelation($subject=null, $predicate=null, $object=null, $creator=null)
    {
        // create sql:
        $sqlParts = array();
        foreach (array('subject', 'predicate', 'object', 'creator') as $attr)
        {
            if (is_int($$attr) && ($$attr > 0))
            {
                $sqlParts[] =  "`{$attr}`=" . $$attr;
            }
            elseif (is_null($$attr))
            {
                $sqlParts[] = "`{$attr}`=" . self::RES_EMPTY;
            }
            else
            {// invalid id
                throw new Exception(__METHOD__ . " error: unsigned int or null required for $attr id");
            }
        }

        // execute sql:
        $sql = "INSERT IGNORE INTO `{$this->tblRel}` SET " . join(',', $sqlParts);
        return $this->pdo->exec($sql);
    }




    /**
     * Deletes relation.
     *
     * @param id|null $subject
     * @param id|null $predicate
     * @param id|null $object
     * @param id|null $creator
     * @return int (num rows affected)|false (PDO error)
     * @throws Exception on invalid id
     */
    protected function _deleteRelation($subject=null, $predicate=null, $object=null, $creator=null)
    {
        // create sql:
        $sqlParts = array();
        foreach (array('subject', 'predicate', 'object', 'creator') as $attr)
        {
            if (is_int($$attr) && ($$attr > 0))
            {
                $sqlParts[] =  "`{$attr}`=" . $$attr;
            }
            elseif (is_null($$attr))
            {
                $sqlParts[] = "`{$attr}`=" . self::RES_EMPTY;
            }
            else
            {// invalid id
                throw new Exception(__METHOD__ . " error: unsigned int or null required for $attr id");
            }
        }

        // execute sql:
        $sql = "DELETE FROM `{$this->tblRel}` WHERE (" . join(') AND (', $sqlParts) . ')';
        return $this->pdo->exec($sql);
    }




    /**
     * Creates (if not exist) db tables.
     *
     * @return true (success)|false (PDO error)
     */
    public function init()
    {
        return
        (
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS `{$this->tblRes}` ("
                . '`id` int(10) unsigned NOT NULL auto_increment,'
                . '`uri` varchar(255) collate latin1_general_ci default NULL,'
                . '`class` int(10) unsigned default NULL,'
                . '`value` varchar(255) default NULL,'
                . '`content` text default NULL,'
                . 'PRIMARY KEY  (`id`),'
                . 'UNIQUE KEY `uri` (`uri`),'
                . 'KEY `class` (`class`),'
                . 'KEY `value` (`value`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=utf8') !== false
        )
        &&
        (
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS `{$this->tblRel}` ("
                . '`subject` int(10) unsigned NOT NULL default ' . self::RES_EMPTY . ','
                . '`predicate` int(10) unsigned NOT NULL default ' . self::RES_EMPTY . ','
                . '`object` int(10) unsigned NOT NULL default ' . self::RES_EMPTY . ','
                . '`creator` int(10) unsigned NOT NULL default ' . self::RES_EMPTY. ','
                . '`created` timestamp NOT NULL default CURRENT_TIMESTAMP,'
                . 'PRIMARY KEY  (`subject`,`predicate`,`object`,`creator`),'
                . 'KEY `created` (`created`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=utf8') !== false
        )
        &&
        (
            $this->pdo->exec("INSERT IGNORE INTO `{$this->tblRes}` (`id`, `class`, `value`)"
                . ' VALUES (' . self::RES_TAGGG . ', ' . self::RES_TAGGG . ", 'taggg')"
                . ', (' . self::RES_CLASS . ', ' . self::RES_TAGGG . ", 'class')"
                . ', (' . self::RES_EMPTY . ', ' . self::RES_TAGGG . ", 'empty')") !== false
        );
    }




    /**
     * Deletes (if exist) db tables (and all data!!!!).
     *
     * @return true (success)|false (PDO error)
     */
    public function destroy()
    {
        return $this->pdo->exec("DROP TABLE IF EXISTS `{$this->tblRes}`,`{$this->tblRel}`");
    }




    /**
     * Creates/updates a tag.
     *
     * Tag is defined by 4 resources: subject, predicate, object and creator.
     *
     * Each resource can be given as:
     *     array(uri, class, value, content) - array of resource properties
     *     int - id (if invalid, exception is thrown!!!)
     *     string prefixed 'uri:' - uri
     *     string '[<class>:]<value>'- value (optionally prefixed with class)
     *         (to make colon part of the class/value, escape it with '\')
     *     null (default) - empty resource
     *
     * If suitable resource found in database, it will be reused.
     * If suitable resource not found, it will be created (unless given by id).
     *
     * @example tag(2, 3, 4, 5) - creates tag among subject,predicate,object,creator given by ids
     * @example tag(2, null, 4, null) - same as above, but empty predicate and creator
     * @example tag('uri:http://google.com/', 'dc:description', 'web search engine', 34)
     *     - subject given by uri, predicate by class:value pair, object by value, creator by id
     *
     * @param mixed $subject
     * @param mixed $predicate
     * @param mixed $object
     * @param mixed $creator
     * @return Taggg
     * @throws Exception on failure
     */
    public function write($subject=null, $predicate=null, $object=null, $creator=null)
    {
        // convert each param to valid resource id (creating new resource if necessary):
        foreach (array('subject', 'predicate', 'object', 'creator') as $param)
        {
            // init resource attrs:
            $res = $this->_parseResource($$param);

            // obtain resource id:
            if (isset($res['id']))
            {// id supplied, check if valid
                if (!$this->_fetchResource($res['id']))
                {
                    throw new Exception(__METHOD__ . " error: invalid $param id ({$res['id']})");
                }
            }
            elseif (isset($res['uri']) || isset($res['class']) || isset($res['value']) || isset($res['content']))
            {// uri|class|value|content supplied, reuse or create the resource
                if
                (
                    !($tmp = $this->_fetchResource($res))
                    && !($tmp = $this->_createResource($res))
                )
                {
                    throw new Exception(__METHOD__ . " error: $param resource creation failed");
                }
                $res = $tmp;
            }
            $$param = isset($res['id']) ? $res['id'] : self::RES_EMPTY;
        }

        // create record in relation table:
        // (only if subject and at least one of predicate|object not empty)
        if
        (
            ($subject != self::RES_EMPTY)
            &&
            (
                ($predicate != self::RES_EMPTY)
                || ($object != self::RES_EMPTY)
            )
        )
        {
            $this->_createRelation($subject, $predicate, $object, $creator);
        }

        return $this;
    }




    /**
     * Removes a tag.
     *
     * Arguments are the same as in write method.
     *
     * @param mixed $subject
     * @param mixed $predicate
     * @param mixed $object
     * @param mixed $creator
     * @return Taggg
     * @throws Exception on failure
     */
    public function erase($subject=null, $predicate=null, $object=null, $creator=null)
    {
        // convert each param to a resource id:
        foreach (array('subject', 'predicate', 'object', 'creator') as $param)
        {
            // init resource attrs:
            $res = $this->_parseResource($$param);

            // obtain resource id:
            if (isset($res['uri']) || isset($res['class']) || isset($res['value']) || isset($res['content']))
            {// uri|class|value|content supplied, lookup resource id
                if (!($res = $this->_fetchResource($res)))
                {// not found -> ignore
                    return $this;
                }
            }
            $$param = isset($res['id']) ? $res['id'] : self::RES_EMPTY;
        }

        // delete record in relation table:
        $this->_deleteRelation($subject, $predicate, $object, $creator);

        return $this;
    }




    /**
     * Checks if a tag exists.
     *
     * Arguments are the same as in write method.
     *
     * @param mixed $subject
     * @param mixed $predicate
     * @param mixed $object
     * @param mixed $creator
     * @return boolean
     * @throws Exception on failure
     */
    public function exists($subject=null, $predicate=null, $object=null, $creator=null)
    {
        // convert each param to a resource id:
        foreach (array('subject', 'predicate', 'object', 'creator') as $param)
        {
            // init resource attrs:
            $res = $this->_parseResource($$param);

            // obtain resource id:
            if (isset($res['uri']) || isset($res['class']) || isset($res['value']) || isset($res['content']))
            {// uri|class|value|content supplied, lookup resource id
                if (!($res = $this->_fetchResource($res)))
                {// resource not found
                    return false;
                }
            }
            $$param = isset($res['id']) ? $res['id'] : self::RES_EMPTY;
        }

        return (boolean) $this->_fetchRelation($subject, $predicate, $object, $creator);
    }




    /**
     * Fetches tags from db.
     *
     * @return array of tags
     * @throws Exception on failure
     */
    public function fetch()
    {
        // @todo
        $sql = "SELECT * FROM `{$this->tblRes}` WHERE (" . join(') AND (', $sqlParts) . ')';
        if ($stmt = $this->pdo->query($sql))
        {
            if ($res = $stmt->fetch(PDO::FETCH_ASSOC))
            {// found, fix types:
                $res['id'] = (int)$res['id'];
                $res['class'] = (int)$res['class'];
                return $res;
            }
            else
            {// not found
                return null;
            }
        }
        else
        {// query error
            return $stmt;
        }
    }




}
