-- SEC-002: Remove legacy unsalted MD5 password hashes from user meta.
-- Users with legacy-pw still set must use "Forgot Password" to regain access.
-- Their bcrypt password (if any) remains valid — this only removes the MD5 fallback.
DELETE FROM bs_users_meta WHERE `key` = 'legacy-pw';
