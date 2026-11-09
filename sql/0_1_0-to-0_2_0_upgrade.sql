#IfMissingColumn openvisit_participant token_id
ALTER TABLE `openvisit_participant` ADD COLUMN `token_id` BIGINT UNSIGNED NULL COMMENT 'openvisit_token.id when the participant came through a link';
#EndIf

#IfNotTable openvisit_join_attempt
CREATE TABLE IF NOT EXISTS `openvisit_join_attempt` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_hash`  CHAR(64)        NOT NULL COMMENT 'sha256 of the client address',
    `attempted_at` DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_openvisit_attempt_client` (`client_hash`, `attempted_at`)
) ENGINE=InnoDB COMMENT='OpenVisit: join page attempts, for rate limiting';
#EndIf

#IfNotRow background_services name OpenVisitCleanup
INSERT INTO `background_services` (`name`, `title`, `active`, `running`, `next_run`, `execute_interval`, `function`, `require_once`, `sort_order`)
VALUES ('OpenVisitCleanup', 'OpenVisit: close abandoned visits and purge old links', '0', '0', CURRENT_TIMESTAMP, '60', 'openvisit_background_cleanup', '/interface/modules/custom_modules/oe-module-openvisit/background/cleanup.php', '100');
#EndIf
