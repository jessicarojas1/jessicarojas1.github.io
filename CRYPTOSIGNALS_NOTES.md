# Crypto Trading Signals — Technical Notes

**File:** `cryptosignals.html` + `cryptosignals.js`
**Type:** Static (GitHub Pages) — no backend, no server, no API keys required
**Data refresh:** Every 2 minutes automatically

---

## What This Tool Does

Pulls live cryptocurrency price data and runs technical analysis to generate
**Buy / Hold / Sell** signals for 8 major coins. Everything runs in the browser —
no account needed, no login, no data stored anywhere.

Coins tracked: **BTC, ETH, BNB, SOL, XRP, DOGE, ADA, AVAX**

---

## Data Connections

### 1. CoinGecko API (Primary price feed)
- **URL:** `https://api.coingecko.com/api/v3/coins/markets`
- **Auth:** None — free tier, no API key
- **What it returns:**
  - Current price (USD)
  - 24h high / low
  - 24h % change
  - 7d % change
  - Market cap
  - 24h trading volume
  - **Sparkline:** 168 hourly price points (7 days of history)
- **Rate limit:** ~10–30 calls/minute on free tier. The tool refreshes every
  120 seconds to stay safely within limits.
- **CORS:** Supported — browser can call directly with no proxy needed.
- **What happens if rate-limited:** The error bar appears on screen and the
  tool retries automatically on the next 2-minute cycle.

### 2. Alternative.me Fear & Greed Index
- **URL:** `https://api.alternative.me/fng/`
- **Auth:** None — completely free, no key
- **What it returns:** A 0–100 index score + label
  (Extreme Fear → Fear → Neutral → Greed → Extreme Greed)
- **How often it updates:** Once per day (the index is a daily composite)
- **Why it matters:** When the index is in Extreme Fear (0–25), historically
  the market is oversold and prices tend to recover. Extreme Greed (75–100)
  often precedes corrections. It provides market-wide context for individual
  coin signals.
- **CORS:** Supported — no proxy needed.

---

## How the Technical Analysis Works

### Step 1 — Data ingestion
CoinGecko returns a `sparkline_in_7d.price` array of 168 values — one per hour
over the last 7 days. This is the raw input for all indicators.

### Step 2 — RSI (Relative Strength Index, period = 14)

RSI measures the speed and size of recent price moves on a 0–100 scale.

**Formula:**
```
Average Gain = sum of gains over last 14 periods / 14
Average Loss = sum of losses over last 14 periods / 14
RS = Average Gain / Average Loss
RSI = 100 - (100 / (1 + RS))
```

**Interpretation used in this tool:**
| RSI Range | Zone | Signal Contribution |
|-----------|------|---------------------|
| Below 20 | Extremely oversold | +5 (strong buy) |
| 20 – 30 | Oversold | +3 |
| 30 – 40 | Below midpoint | +1 |
| 40 – 60 | Neutral | 0 |
| 60 – 70 | Above midpoint | -1 |
| 70 – 80 | Overbought | -3 |
| Above 80 | Extremely overbought | -5 (strong sell) |

RSI is the **primary driver** of the signal. All other indicators are
secondary confirmation.

### Step 3 — 24h Momentum (Contrarian)

Short-term price drops are treated as potential buying opportunities (mean
reversion logic), and large pumps are treated as caution signals.

| 24h Change | Signal Contribution |
|------------|---------------------|
| Below -10% | +3 |
| -4% to -10% | +1 |
| -4% to +4% | 0 |
| +4% to +10% | -1 |
| Above +10% | -3 |

### Step 4 — 7-Day Trend

| 7d Change | Signal Contribution |
|-----------|---------------------|
| Below -25% | +2 |
| -10% to -25% | +1 |
| -10% to +10% | 0 |
| +10% to +25% | -1 |
| Above +25% | -2 |

### Step 5 — EMA Crossover (12-period vs 26-period, hourly)

EMA = Exponential Moving Average. EMA12 reacts faster to price changes than
EMA26. When they cross, it signals a shift in momentum.

- **EMA12 > EMA26 AND price > EMA12** → uptrend fully intact → -1 (don't chase)
- **EMA12 < EMA26 AND price < EMA12** → downtrend → +1 (potential reversal zone)
- **EMA12 > EMA26 AND price < EMA12** → bullish cross but pullback → +1 (entry opportunity)

### Step 6 — Signal Scoring

All factor scores are summed. Final signal:

| Total Score | Signal |
|-------------|--------|
| ≥ 5 | STRONG BUY |
| 2 – 4 | BUY |
| -1 – 1 | HOLD |
| -2 – -4 | SELL |
| ≤ -5 | STRONG SELL |

---

## Sparkline Charts

Each coin card shows an SVG sparkline of the **last 48 hourly prices** (2 days).

- Green line + shaded fill = price is higher now than 48 hours ago
- Red line + shaded fill = price is lower now than 48 hours ago
- RSI is calculated on the full 168-point (7-day) dataset, not just the 2-day display

---

## Signal Reliability — What You Need to Know

**RSI works best when:**
- The market is ranging (not in a sustained trend)
- Used on longer timeframes (daily > hourly > minute)
- Confirmed by multiple indicators pointing the same direction

**RSI is unreliable when:**
- A coin is in a strong sustained trend (RSI can stay overbought/oversold for weeks)
- Volume is very low (thin markets distort the signal)
- Major news events override technicals entirely

**General trading rules to apply alongside these signals:**
1. Never trade a STRONG BUY signal with your full capital — enter in 2–3 tranches
2. Always set a stop-loss before entering — typically 5–8% below entry
3. A BUY signal in Extreme Fear is historically stronger than a BUY in Greed
4. HOLD means wait — do not force trades when there is no edge
5. Signals reset every 2 minutes — check before trading, not 10 minutes before

---

## Current Limitations (Static Site)

Because this runs entirely in the browser with no backend:

| Limitation | Reason | Fix if upgrading to dynamic |
|------------|--------|-----------------------------|
| 2-minute refresh | CoinGecko free rate limit | Use WebSocket feed (Binance/Coinbase) |
| No price alerts | No server to run 24/7 | Node.js backend with email/SMS |
| No trade execution | No exchange API keys | Connect Binance/Coinbase API server-side |
| 7 days of history | CoinGecko sparkline limit | Use OHLCV endpoint for full history |
| No custom timeframe | Fixed to hourly data | Dynamic backend with selectable intervals |
| 8 coins only | Avoid hitting rate limits | Backend can pull unlimited coins |

---

## How to Add More Coins

Open `cryptosignals.js` and add entries to the `COINS` array at the top:

```javascript
var COINS = [
  { id: 'bitcoin',     symbol: 'BTC',  name: 'Bitcoin' },
  { id: 'ethereum',    symbol: 'ETH',  name: 'Ethereum' },
  // Add more below — use the CoinGecko coin ID:
  { id: 'chainlink',   symbol: 'LINK', name: 'Chainlink' },
  { id: 'polkadot',    symbol: 'DOT',  name: 'Polkadot' },
  { id: 'shiba-inu',   symbol: 'SHIB', name: 'Shiba Inu' },
];
```

CoinGecko coin IDs: search at `https://www.coingecko.com` — the ID is in the
URL of the coin's page (e.g., `coingecko.com/en/coins/chainlink` → ID = `chainlink`).

**Note:** Adding more than ~15 coins may trigger CoinGecko rate limiting on the
free tier. 8–10 is the safe maximum for a 2-minute refresh cycle.

---

## What a Dynamic (Backend) Version Could Add

If moved to a Node.js or Python backend (e.g., Railway, Render — both have free
tiers), the tool could gain:

- **Real-time WebSocket prices** — Binance and Coinbase both offer free WebSocket
  streams with millisecond updates. No rate limits.
- **Price alerts** — "Notify me when BTC RSI drops below 28"
- **Automated trade execution** — Connect Binance or Coinbase Pro API server-side
  (keys are never exposed to the browser)
- **MACD on full historical data** — MACD needs 26+ days of daily data, which
  requires additional API calls not practical on the static free tier
- **Backtesting** — test how well these signals performed over the past year
- **Portfolio tracker** — enter your holdings and see P&L alongside signals
- **Push notifications / SMS** — via Twilio or Pushover

---

## File Structure

```
jessicarojas1.github.io/
├── cryptosignals.html     ← UI: layout, cards, Fear & Greed, controls
├── cryptosignals.js       ← Logic: RSI, EMA, fetch, render, scoring
└── CRYPTOSIGNALS_NOTES.md ← This file
```

---

## Quick Reference — Signal Decision Tree

```
Is RSI below 30?
  YES → Is Fear & Greed below 30 (Extreme Fear)?
          YES → STRONG BUY candidate — confirm with 7d trend, then enter small
          NO  → BUY candidate — watch for 24h stabilization before entering
  NO  → Is RSI above 70?
          YES → Is Fear & Greed above 70 (Extreme Greed)?
                  YES → STRONG SELL candidate — consider taking profit
                  NO  → SELL candidate — tighten stop-loss
          NO  → RSI neutral → check EMA trend
                  EMA bullish (12 > 26)? → lean HOLD / weak BUY
                  EMA bearish (12 < 26)? → lean HOLD / weak SELL
```

---

*Data sources: CoinGecko (prices, sparklines), alternative.me (Fear & Greed Index).
Neither this tool nor its signals constitute financial advice.*
