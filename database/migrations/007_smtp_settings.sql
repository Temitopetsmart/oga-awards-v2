CREATE TABLE smtp_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    smtp_host VARCHAR(190) NOT NULL DEFAULT '',
    smtp_port SMALLINT UNSIGNED NOT NULL DEFAULT 587,
    smtp_username VARCHAR(190) NOT NULL DEFAULT '',
    encryption ENUM('none', 'tls', 'ssl') NOT NULL DEFAULT 'tls',
    password_ciphertext TEXT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    CHECK (id = 1),
    CHECK (smtp_port BETWEEN 1 AND 65535)
) ENGINE=InnoDB;

-- Carry over existing SMTP host, port and username from communications.
INSERT INTO smtp_settings (
    id, smtp_host, smtp_port, smtp_username, encryption
)
SELECT
    1,
    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(value, '$.smtpHost')), ''),
    CAST(
        COALESCE(
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT(value, '$.smtpPort')), ''),
            '587'
        ) AS UNSIGNED
    ),
    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(value, '$.smtpUsername')), ''),
    'tls'
FROM site_settings
WHERE setting_key = 'communications';