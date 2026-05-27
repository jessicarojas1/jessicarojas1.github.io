-- Add per-cell configuration to risk_matrix_config.
-- Each cell stores its own treatment label, description, and color.

ALTER TABLE aegis.risk_matrix_config ADD COLUMN IF NOT EXISTS cells JSONB NOT NULL DEFAULT '{}';
ALTER TABLE aegis.risk_matrix_config ADD COLUMN IF NOT EXISTS description TEXT;

-- Update default labels to match standard ERM terminology
UPDATE aegis.risk_matrix_config
SET row_labels = '["Never","Unexpected","Anticipated","Foreseeable","Expected"]'
WHERE row_labels = '["Rare","Unlikely","Possible","Likely","Almost Certain"]';

UPDATE aegis.risk_matrix_config
SET col_labels = '["Acceptable","Tolerable","Unacceptable","Critical","Catastrophic"]'
WHERE col_labels = '["Negligible","Minor","Moderate","Major","Critical"]';

-- Populate default per-cell treatment and color data (5x5, rows 1-5, cols 1-5)
-- Row 5 = Expected [4], Row 4 = Foreseeable [3], ..., Row 1 = Never [0]
UPDATE aegis.risk_matrix_config SET cells = '{
  "5_1":{"title":"Avoid / Mitigate / Transfer","desc":"Avoid / Mitigate / Transfer","color":"#22c55e"},
  "5_2":{"title":"Avoid / Mitigate / Transfer","desc":"Avoid / Mitigate / Transfer","color":"#f59e0b"},
  "5_3":{"title":"Mitigate","desc":"Mitigate","color":"#ef4444"},
  "5_4":{"title":"Mitigate","desc":"Mitigate","color":"#ef4444"},
  "5_5":{"title":"Mitigate","desc":"Mitigate","color":"#ef4444"},
  "4_1":{"title":"Accept / Monitor / Transfer","desc":"Accept Monitor Transfer","color":"#22c55e"},
  "4_2":{"title":"Avoid / Mitigate / Transfer","desc":"Avoid / Mitigate / Transfer","color":"#f59e0b"},
  "4_3":{"title":"Avoid / Mitigate / Transfer","desc":"Accept Mitigate Transfer","color":"#ef4444"},
  "4_4":{"title":"Mitigate","desc":"Mitigate","color":"#ef4444"},
  "4_5":{"title":"Mitigate","desc":"Mitigate","color":"#ef4444"},
  "3_1":{"title":"Accept / Monitor / Transfer","desc":"Accept / Monitor / Transfer","color":"#22c55e"},
  "3_2":{"title":"Accept / Monitor / Transfer","desc":"Accept / Monitor / Transfer","color":"#22c55e"},
  "3_3":{"title":"Avoid / Mitigate / Transfer","desc":"Avoid / Mitigate / Transfer","color":"#f59e0b"},
  "3_4":{"title":"Avoid / Mitigate / Transfer","desc":"Avoid / Mitigate / Transfer","color":"#f59e0b"},
  "3_5":{"title":"Avoid / Mitigate / Transfer","desc":"Avoid / Mitigate / Transfer","color":"#f59e0b"},
  "2_1":{"title":"Accept / Monitor / Transfer","desc":"Accept Monitor Transfer","color":"#22c55e"},
  "2_2":{"title":"Accept / Monitor / Transfer","desc":"Accept / Monitor / Transfer","color":"#22c55e"},
  "2_3":{"title":"Accept / Monitor / Transfer","desc":"Accept Monitor Transfer","color":"#22c55e"},
  "2_4":{"title":"Avoid / Mitigate / Transfer","desc":"Avoid / Mitigate / Transfer","color":"#f59e0b"},
  "2_5":{"title":"Avoid / Mitigate / Transfer","desc":"Avoid Mitigate Transfer","color":"#f59e0b"},
  "1_1":{"title":"Accept","desc":"Accept","color":"#22c55e"},
  "1_2":{"title":"Accept","desc":"Accept","color":"#22c55e"},
  "1_3":{"title":"Accept","desc":"Accept","color":"#22c55e"},
  "1_4":{"title":"Accept","desc":"Accept","color":"#22c55e"},
  "1_5":{"title":"Accept","desc":"Accept","color":"#22c55e"}
}' WHERE cells = '{}';
