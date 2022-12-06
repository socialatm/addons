
--
-- Table structure for table `rendezvous_groups`
--

CREATE TABLE IF NOT EXISTS `rendezvous_groups` (
	`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	`aid` int(10) unsigned NOT NULL DEFAULT '0',
	`uid` int(10) unsigned NOT NULL DEFAULT '0',
	`size` int(10) unsigned NOT NULL DEFAULT '0',
	`stale` int(10) unsigned NOT NULL DEFAULT '0',
	`deleted` int(1) unsigned NOT NULL DEFAULT '0',
	`guid` char(191) NOT NULL DEFAULT '',
	`name` char(191) NOT NULL DEFAULT '',
	`created` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	`edited` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	`expires` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	`allow_cid` mediumtext NOT NULL DEFAULT '',
	`allow_gid` mediumtext NOT NULL DEFAULT '',
	`deny_cid` mediumtext NOT NULL DEFAULT '',
	`deny_gid` mediumtext NOT NULL DEFAULT '',
	PRIMARY KEY (`id`),
	KEY `aid` (`aid`),
	KEY `uid` (`uid`),
	KEY `size` (`size`),
	KEY `stale` (`stale`),
	KEY `deleted` (`deleted`),
	KEY `guid` (`guid`),
	KEY `name` (`name`),
	KEY `created` (`created`),
	KEY `edited` (`edited`),
	KEY `expires` (`expires`),
	FULLTEXT KEY `allow_cid` (`allow_cid`),
	FULLTEXT KEY `allow_gid` (`allow_gid`),
	FULLTEXT KEY `deny_cid` (`deny_cid`),
	FULLTEXT KEY `deny_gid` (`deny_gid`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------


--
-- Table structure for table `rendezvous_members`
--

CREATE TABLE IF NOT EXISTS `rendezvous_members` (
	`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	`rid` char(191) NOT NULL DEFAULT '',
	`mid` char(191) NOT NULL DEFAULT '',
	`name` char(191) NOT NULL DEFAULT '',
	`secret` char(191) NOT NULL DEFAULT '',
	`deleted` int(1) unsigned NOT NULL DEFAULT '0',
	`lat` decimal(32,16),
	`lng` decimal(32,16),
	`updated` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	PRIMARY KEY (`id`),
	KEY `rid` (`rid`),
	KEY `mid` (`mid`),
	KEY `name` (`name`),
	KEY `secret` (`secret`),
	KEY `deleted` (`deleted`),
	KEY `lat` (`lat`),
	KEY `lng` (`lng`),
	KEY `updated` (`updated`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------


--
-- Table structure for table `rendezvous_markers`
--

CREATE TABLE IF NOT EXISTS `rendezvous_markers` (
	`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	`rid` char(191) NOT NULL DEFAULT '',
	`mid` char(191) NOT NULL DEFAULT '',
	`name` char(191) NOT NULL DEFAULT '',
	`description` mediumtext NOT NULL DEFAULT '',
	`deleted` int(1) unsigned NOT NULL DEFAULT '0',
	`lat` decimal(32,16),
	`lng` decimal(32,16),
	`created` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	`edited` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	PRIMARY KEY (`id`),
	KEY `rid` (`rid`),
	KEY `mid` (`mid`),
	KEY `name` (`name`),
	FULLTEXT KEY `description` (`description`),
	KEY `deleted` (`deleted`),
	KEY `lat` (`lat`),
	KEY `lng` (`lng`),
	KEY `created` (`created`),
	KEY `edited` (`edited`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
