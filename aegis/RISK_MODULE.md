# AEGIS — Risk Module

The risk register captures, scores, treats, and tracks risks across their
lifecycle, with inherent / residual / target scoring, appetite thresholds, KRIs,
treatments, acceptances, exceptions, scenarios, and BowTie analysis.

## Risk record

Key columns on `risks` (see `database/schema.sql`):

| Field | Meaning |
|-------|---------|
| `likelihood`, `impact` | Inherent axes (1–5). |
| `inherent_score` | `likelihood × impact` (1–25). |
| `residual_likelihood`, `residual_impact`, `residual_score` | Risk remaining after controls/treatment. |
| `target_likelihood`, `target_impact`, `target_score` | Desired post-treatment position. |
| `velocity` (1–5), `proximity` | How fast it materializes / when. |
| `confidence` (low/medium/high) | Confidence in the assessment. |
| `financial_min/likely/max`, `financial_currency` | Quantitative exposure. |
| `parent_risk_id` | Risk hierarchy / aggregation. |
| `assessment_status` | `draft` → `pending_review` → `approved`. |
| `owner_id`, `review_date` | Accountability and review cadence. |
| `treatment_strategies` (JSONB) | Multi-select accept/mitigate/transfer/avoid. |

## Scoring engine (`src/RiskScore.php`)

All scoring and level banding flow through one class so the heatmap, dashboard
counts, list filters, and badges never disagree. The bands match the historical
AEGIS 5×5 matrix exactly:

| Level | Score range |
|-------|-------------|
| Low | 1 – 4 |
| Medium | 5 – 9 |
| High | 10 – 14 |
| Critical | 15 – 25 |

API:

```php
RiskScore::score($likelihood, $impact);   // clamps axes to 1..5, returns product
RiskScore::level($score);                 // 'low' | 'medium' | 'high' | 'critical'
RiskScore::label($level);                 // 'Low' …
RiskScore::colorVar($level);              // CSS custom property, e.g. 'var(--danger)'
RiskScore::inherent($risk);               // from a risk row (array)
RiskScore::residual($risk);               // null if residual axes unset
RiskScore::target($risk);                 // null if target axes unset
RiskScore::exceedsAppetite($score, $max); // appetite breach test
RiskScore::sqlCondition($level, $column); // safe SQL predicate for a band
RiskScore::levels();                      // ['low','medium','high','critical']
```

`sqlCondition()` returns the same predicates the controller used inline
(`inherent_score > 14`, `BETWEEN 10 AND 14`, …) and validates the column
identifier shape so it can never become an injection vector — the level and
column are always code-supplied, never request input.

> **Colors** are returned as CSS custom-property tokens (`var(--success)`,
> `var(--warning)`, `var(--danger)`), never hard-coded hex, so risk badges stay
> correct in dark mode.

Covered by `tests/test_riskscore.php` (11 cases). Run `php tests/run.php`.

## Dashboards & filters

`RiskController` uses the engine for the summary band counts and the list-level
filter. The score columns are indexed (`idx_risks_inherent_score`,
`idx_risks_residual_score`) for the dashboard aggregations and
`ORDER BY inherent_score DESC` list views.

## Appetite

`risk_appetite` defines a `max_score` per category; the dashboard surfaces risks
where `inherent_score > max_score`. Use `RiskScore::exceedsAppetite()` for the
same test in PHP.

## Related records

`risk_treatments`, `risk_acceptances` (with expiry + approval), `risk_exceptions`,
`risk_reviews`, `risk_scenarios`, `risk_bowtie` / `risk_bowtie_events`,
`risk_score_history` (trend), and KRIs (`kris`). These hang off `risks` and are
managed by their respective controllers.

## Extending the bands

To change thresholds (e.g. move to a 4×4 or weighted model), edit
`RiskScore::BANDS` and `score()` in one place; the SQL predicates, dashboard,
filters, and badges follow automatically. Update `tests/test_riskscore.php` to
match.
