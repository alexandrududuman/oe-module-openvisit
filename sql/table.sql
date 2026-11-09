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
    PRIMARY KEY (`id`),
    KEY `ix_openvisit_participant_session` (`session_id`)
) ENGINE=InnoDB COMMENT='OpenVisit: audit of who joined which session';
#EndIf
