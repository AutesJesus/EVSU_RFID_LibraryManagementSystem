-- Add otp_enabled column to users table
ALTER TABLE users ADD COLUMN otp_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER status;

-- Update existing users to have 2FA enabled by default
UPDATE users SET otp_enabled = 1 WHERE otp_enabled IS NULL;
