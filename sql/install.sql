#IfNotTable openvisit_session
CREATE TABLE IF NOT EXISTS `openvisit_session` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pc_eid`      INT UNSIGNED    NOT NULL COMMENT 'openemr_postcalendar_events.pc_eid',
    `encounter`   BIGINT UNSIGNED NULL     COMMENT 'form_encounter.encounter, set when the provider joins',
    `room_name`   VARCHAR(64)     NOT NULL COMMENT 'random Jitsi room name',
    `provider_id` BIGINT UNSIGNED NOT NULL COMMENT 'users.id',
    `status`      ENUM('scheduled', 'active', 'ended', 'cancelled') NOT NULL DEFAULT 'scheduled',
    `started_at`  DATETIME        NULL,
    `ended_at`    DATETIME        NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_openvisit_session_pc_eid` (`pc_eid`),
    UNIQUE KEY `uk_openvisit_session_room` (`room_name`),
    KEY `ix_openvisit_session_status` (`status`)
) ENGINE=InnoDB COMMENT='OpenVisit: one video session per appointment';
#EndIf

#IfNotTable openvisit_token
CREATE TABLE IF NOT EXISTS `openvisit_token` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id`  BIGINT UNSIGNED NOT NULL COMMENT 'openvisit_session.id',
    `role`        ENUM('patient', 'guest') NOT NULL,
    `token_hash`  CHAR(64)        NOT NULL COMMENT 'sha256 of the join token, hex',
    `label`       VARCHAR(80)     NULL     COMMENT 'who the link is for, e.g. interpreter',
    `expires_at`  DATETIME        NOT NULL,
    `revoked_at`  DATETIME        NULL,
    `uses`        INT UNSIGNED    NOT NULL DEFAULT 0,
    `max_uses`    INT UNSIGNED    NOT NULL DEFAULT 10,
    `created_by`  BIGINT UNSIGNED NOT NULL COMMENT 'users.id',
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_openvisit_token_hash` (`token_hash`),
    KEY `ix_openvisit_token_session` (`session_id`),
    KEY `ix_openvisit_token_expires` (`expires_at`)
) ENGINE=InnoDB COMMENT='OpenVisit: join links for patients and guests';
#EndIf

#IfNotTable openvisit_participant
CREATE TABLE IF NOT EXISTS `openvisit_participant` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id`   BIGINT UNSIGNED NOT NULL COMMENT 'openvisit_session.id',
    `role`         ENUM('provider', 'patient', 'guest') NOT NULL,
    `display_name` VARCHAR(120)    NOT NULL,
    `joined_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `left_at`      DATETIME        NULL,
    `user_agent`   VARCHAR(255)    NULL,
    `token_id`     BIGINT UNSIGNED NULL     COMMENT 'openvisit_token.id when the participant came through a link',
    PRIMARY KEY (`id`),
    KEY `ix_openvisit_participant_session` (`session_id`)
) ENGINE=InnoDB COMMENT='OpenVisit: audit of who joined which session';
#EndIf

#IfNotRow openemr_postcalendar_categories pc_constant_id openvisit_telehealth
INSERT INTO `openemr_postcalendar_categories` (
    `pc_constant_id`, `pc_catname`, `pc_catcolor`, `pc_catdesc`,
    `pc_recurrtype`, `pc_enddate`, `pc_recurrspec`, `pc_recurrfreq`, `pc_duration`,
    `pc_end_date_flag`, `pc_end_date_type`, `pc_end_date_freq`, `pc_end_all_day`,
    `pc_dailylimit`, `pc_cattype`, `pc_active`, `pc_seq`, `aco_spec`
) VALUES (
    'openvisit_telehealth', 'Telehealth Visit', '#4fa3d1', 'Video visit through OpenVisit',
    '0', NULL, 'a:5:{s:17:"event_repeat_freq";s:1:"0";s:22:"event_repeat_freq_type";s:1:"0";s:19:"event_repeat_on_num";s:1:"1";s:19:"event_repeat_on_day";s:1:"0";s:20:"event_repeat_on_freq";s:1:"0";}', '0', '900',
    '0', NULL, '0', '0',
    '0', '0', '1', '11', 'encounters|notes'
);
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
