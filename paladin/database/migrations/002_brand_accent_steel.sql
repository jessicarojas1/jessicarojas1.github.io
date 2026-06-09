-- Switch the default brand accent from blue (#2563eb) to sky (#0ea5e9) for the
-- steel/command-console theme. Only updates instances still on the old default —
-- a custom accent set in Settings → Branding is preserved, and re-runs are no-ops.
UPDATE settings SET value = '#0ea5e9', updated_at = NOW()
 WHERE key = 'brand_accent' AND value = '#2563eb';
