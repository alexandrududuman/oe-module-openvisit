#IfNotTable openvisit_recording
CREATE TABLE IF NOT EXISTS `openvisit_recording` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id`       BIGINT UNSIGNED NOT NULL COMMENT 'openvisit_session.id',
    `status`           ENUM('starting', 'recording', 'processing', 'ready', 'failed') NOT NULL DEFAULT 'starting',
    `started_by`       BIGINT UNSIGNED NOT NULL COMMENT 'users.id who confirmed consent and started it',
    `started_at`       DATETIME        NULL,
    `stopped_at`       DATETIME        NULL,
    `file_path`        VARCHAR(255)    NULL     COMMENT 'relative to the recordings storage folder',
    `file_size`        BIGINT UNSIGNED NULL,
    `duration_seconds` INT UNSIGNED    NULL,
    `created_at`       DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_openvisit_recording_session` (`session_id`),
    KEY `ix_openvisit_recording_status` (`status`)
) ENGINE=InnoDB COMMENT='OpenVisit: call recordings made by Jibri';
#EndIf
