ALTER TABLE `sc_users` ADD `bLastDelete` DATETIME NOT NULL;
UPDATE `sc_version` SET `schema_version`='7';
