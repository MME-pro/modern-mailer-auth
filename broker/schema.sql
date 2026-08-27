-- Two tables, both of things that expire within the hour.
--
-- There is deliberately no table of grants. The site keeps its refresh token
-- and sends it back when it needs a new access token, so this service holds a
-- credential only during the few minutes between an administrator approving at
-- the provider and the site claiming it. A breach here is "some people sign in
-- again", not "every customer's mailbox".

CREATE TABLE IF NOT EXISTS flows (
	id          CHAR(64)     NOT NULL PRIMARY KEY,
	family      VARCHAR(16)  NOT NULL,
	site_id     VARCHAR(64)  NOT NULL,
	site_url    VARCHAR(255) NOT NULL,
	callback    TEXT         NOT NULL,
	site_state  VARCHAR(128) NOT NULL,
	expires_at  DATETIME     NOT NULL,
	KEY site_recent (site_id, expires_at),
	KEY expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS handoffs (
	code_hash   CHAR(64)     NOT NULL PRIMARY KEY,
	family      VARCHAR(16)  NOT NULL,
	site_id     VARCHAR(64)  NOT NULL,
	payload     TEXT         NOT NULL,
	expires_at  DATETIME     NOT NULL,
	KEY expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
