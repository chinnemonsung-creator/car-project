-- Migration Rollback: drop table verify_sessions
-- Run only if you want to rollback the creation of verify_sessions

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `verify_sessions`;

SET FOREIGN_KEY_CHECKS = 1;
