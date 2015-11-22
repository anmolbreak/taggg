**Taggg** lets users apply **tags** (simple text labels or semantically-rich data) to **subjects** (article, video, comment, user or anything that can be identified by uri, id, class or name).

Taggg also allows simple reading of all or subset of tags from database, depending on **filters** you specify (e.g. all tags for **subject with id=347**...).

The class is **easy to use**, single file, no installation, no setup, just download and `include` the file.

What makes Taggg special and extremely flexible is that it allows you to store **additional semantics** in the tags. Each tag is stored as a quadruple (subject, predicate, object, creator), where predicate can be property name, object property value and creator the user who created the tag.

# colaborators/testers wanted #
If you want to join the project, please drop me a line (jun66le-at-gmail-dot-com).

# features #
  * **powerful** yet **simple** - single class, simple interface, no installation
  * **flexible** and **loosely-coupled** - independent on where and how is data stored, uses any **PDO compatible** database to store data, anything can be tagged, independent on how is data presented, tags can be enriched with **predicate** (`color:blue`) and **creator** (a tag is stored as **quadruple** (subject, predicate, object, creator))
  * **extensible** - just add new methods for additional functionality

# usage #
```
<?php
$pdo = ...create your PDO object...
$taggg = new Taggg($pdo);
$taggg->write('uri:http://google.com/', null, 'popular');
$taggg->write('uri:http://google.com/', null, 'search engine');
$taggg->write('uri:http://google.com/', 'headquarters', 'California');
$taggg->write('snowman', 'color', 'white');
$googleTags = $taggg->read('uri:http://google.com/');
...
?>
```

# methods #
init
destroy
read
write
exists
erase

# tips #
  * call init() once to create database tables (alternatively you can create the tables manually)
  * you can specify 'class' attribute for any resource (class can be a user, article, comment, web address, audio, video...)

---

**IMPORTANT NOTE: the project is in development stage and some methods are not yet implemented (e.g. read) !!!!!!!**