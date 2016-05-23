CREATE TABLE cf_azurestorage (
  id int(11) unsigned NOT NULL auto_increment,
  identifier varchar(250) DEFAULT '' NOT NULL,
  expires int(11) unsigned NOT NULL DEFAULT '0',
  content mediumblob,
  PRIMARY KEY (id),
  KEY cache_id (identifier,expires)
) ENGINE=InnoDB;


CREATE TABLE cf_azurestorage_tags (
  id int(11) unsigned NOT NULL auto_increment,
  identifier varchar(250) DEFAULT '' NOT NULL,
  tag varchar(250) DEFAULT '' NOT NULL,
  PRIMARY KEY (id),
  KEY cache_id (identifier),
  KEY cache_tag (tag)
) ENGINE=InnoDB;
