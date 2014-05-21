ALTER TABLE sc_users ADD bLastDelete timestamp with time zone NOT NULL;
UPDATE sc_version SET schema_version=7;
