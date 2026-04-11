# Business Insight Dashboard

Upload a CSV → instant KPIs, charts, and plain-English business insights.
No code required. Runs locally in 60 seconds.

---

## Quick Start

```bash
# 1. Clone or copy the folder
cd business-insight-dashboard

# 2. Create virtual environment
python3 -m venv .venv
source .venv/bin/activate        # Windows: .venv\Scripts\activate

# 3. Install dependencies
pip install -r requirements.txt

# 4. Run
streamlit run app.py
# → Opens at http://localhost:8501
```

Upload `sample_data/sample_business.csv` to see a full demo.

---

## Folder Structure

```
business-insight-dashboard/
├── app.py                    # Main Streamlit app — UI + orchestration
├── requirements.txt
├── sample_data/
│   └── sample_business.csv   # 82-row demo dataset
└── modules/
    ├── __init__.py
    ├── loader.py             # CSV ingestion + smart column detection
    ├── kpis.py               # KPI calculations + aggregations
    ├── charts.py             # Plotly figure builders
    ├── insights.py           # Rule-based insight engine
    └── styles.py             # Custom CSS + HTML card helpers
```

---

## Supported CSV Columns

Column names are detected automatically — common variations are recognised.

| Canonical | Variations recognised | Required |
|-----------|----------------------|----------|
| `date` | day, period, month, order_date, transaction_date… | Recommended |
| `revenue` | sales, amount, total, income, deal_value… | Recommended |
| `leads` | lead_count, prospects, inquiries, inbound… | Optional |
| `conversions` | converted, closed, won, orders, sign_ups… | Optional |
| `product` | product_name, item, sku, plan, tier… | Optional |
| `service` | service_type, category, line_of_business… | Optional |
| `source` | channel, utm_source, marketing_channel… | Optional |
| `customer` | customer_name, client, account, company… | Optional |

---

## Insights Generated

- Revenue trend direction and monthly growth rate
- Best and worst performing periods
- Top products and services by revenue (with concentration risk)
- Conversion rate and efficiency rating
- Top acquisition channel (with diversification score)
- Anomaly detection — revenue spikes and drops (z-score method)
- Lead volume trend (growing vs shrinking pipeline)
- Customer concentration risk (Pareto analysis)

---

## Customising for a Client

1. **Branding**: edit `PRIMARY`, `SECONDARY` color variables in `modules/styles.py`
2. **Company name**: update the sidebar header in `app.py`
3. **Column aliases**: add client-specific field names to `ALIASES` in `modules/loader.py`
4. **Insight thresholds**: adjust the severity thresholds in `modules/insights.py`
5. **Logo**: add `st.image("logo.png")` at the top of the sidebar block in `app.py`

---

## Future Enhancements

| Priority | Feature |
|----------|---------|
| High | Export PDF / PNG report button |
| High | Multi-file comparison (this month vs last month) |
| High | Goal/target line on trend charts |
| Medium | Cohort analysis — repeat customer revenue |
| Medium | Forecasting — 3-month revenue projection with confidence band |
| Medium | Segmented dashboards (filter by product, source, date range) |
| Medium | Alert thresholds — email/Slack when metric drops below target |
| Low | AI narrative generation (OpenAI / Claude API) |
| Low | White-label subdomain deployment per client |
| Low | Multi-user login with saved views |

---

## Turning This into a Paid Client Service

### Delivery Model
1. **Discovery call** — learn their CSV format and key metrics (30 min)
2. **Setup** — clone repo, add column aliases, swap branding (1-2 hours)
3. **Demo** — share a live Streamlit Cloud link seeded with their data
4. **Handoff** — deploy to their machine or cloud; provide 1-page usage guide

### Pricing Packages

| Package | What's included | Price |
|---------|----------------|-------|
| **Starter** | Dashboard setup, 2 revisions, 30-day support | **$500** |
| **Professional** | Starter + custom branding, 3 insight categories, PDF export, 90-day support | **$1,200** |
| **Growth** | Pro + forecasting module, goal tracking, monthly data refresh call, 6-month support | **$2,500 / quarter** |

### Upsells

1. **Monthly Data Refresh** — client sends updated CSV; you upload and deliver a new
   insight report. $150–$300/month depending on volume.
2. **Custom Insight Rules** — add industry-specific logic (e.g., churn risk for SaaS,
   inventory turns for retail). $300–$500 one-time per rule set.
3. **Live Data Integration** — connect to Google Sheets, Salesforce export, or Stripe
   via API instead of manual CSV. $800–$1,500 setup + $100/month maintenance.

---

## Niche Versions

| Niche | Customisation | Target buyer |
|-------|--------------|-------------|
| **E-commerce** | Add AOV, cart abandonment, SKU velocity, refund rate | Shopify / WooCommerce store owners |
| **Agency / Freelance** | Replace revenue with billable hours, add utilisation rate, project margin | Creative agencies, consulting firms |
| **Real Estate** | Replace product with property type, add avg days-on-market, price-per-sqft | Real estate brokers, property managers |

---

## Value Proposition (copy-paste ready)

> **Business Insight Dashboard gives small business owners the analytics
> clarity of an enterprise BI team — without the six-figure software
> contract.** Upload your existing sales CSV and in under a minute you'll
> see exactly where your revenue is coming from, which products are
> carrying the business, and where growth is leaking — all in plain
> English. No data science degree required. No vendor lock-in.
> Just clear answers, fast.

---

## Turning It Into a SaaS

1. **Auth layer**: Add Supabase Auth or Clerk for user accounts
2. **File storage**: Store uploaded CSVs in Supabase Storage or S3 (per user)
3. **Persistent state**: Save dashboards and insight history to Postgres
4. **Billing**: Integrate Stripe — $29/mo Starter, $79/mo Pro, $199/mo Team
5. **Deployment**: Deploy on Railway, Render, or Fly.io (Streamlit supports multi-user)
6. **White-label tier**: Let agencies re-sell under their own domain for $299/mo

MVP SaaS timeline estimate: 4-6 weeks solo, 2-3 weeks with a contractor.
