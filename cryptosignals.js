/* ═══════════════════════════════════════════════════════════════════
   Crypto Trading Signals v2 — cryptosignals.js
   Data:    CoinGecko free API + alternative.me Fear & Greed
   Refresh: 120 s auto-cycle (rate-limit safe on free tier)
═══════════════════════════════════════════════════════════════════ */

var COINS = [
  { id: 'bitcoin',      symbol: 'BTC',  name: 'Bitcoin' },
  { id: 'ethereum',     symbol: 'ETH',  name: 'Ethereum' },
  { id: 'binancecoin',  symbol: 'BNB',  name: 'BNB' },
  { id: 'solana',       symbol: 'SOL',  name: 'Solana' },
  { id: 'ripple',       symbol: 'XRP',  name: 'XRP' },
  { id: 'dogecoin',     symbol: 'DOGE', name: 'Dogecoin' },
  { id: 'cardano',      symbol: 'ADA',  name: 'Cardano' },
  { id: 'avalanche-2',  symbol: 'AVAX', name: 'Avalanche' }
];

var REFRESH_SECS = 120;
var _lastComputed = null; /* cached [{coin, computed}] for re-sort without refetch */
var _sortBy = 'signal';   /* 'signal' | '24h' | 'mcap' | '1h' */

/* ═══════════════════════════════════════════════════════════════════
   TECHNICAL ANALYSIS
═══════════════════════════════════════════════════════════════════ */

/* Wilder's RSI — matches TradingView / professional charting tools exactly.
   Simple-average seed, then exponential (Wilder) smoothing for all remaining
   periods. Standard period = 14. */
function calcRSI(prices, period) {
  period = period || 14;
  if (!prices || prices.length < period + 1) return 50;

  var changes = [];
  for (var i = 1; i < prices.length; i++) {
    changes.push(prices[i] - prices[i - 1]);
  }

  /* Seed: simple average of first 'period' changes */
  var avgGain = 0, avgLoss = 0;
  for (var i = 0; i < period; i++) {
    if (changes[i] > 0) avgGain += changes[i];
    else avgLoss -= changes[i];
  }
  avgGain /= period;
  avgLoss /= period;

  /* Wilder smoothing over remaining changes */
  for (var i = period; i < changes.length; i++) {
    var g = changes[i] > 0 ? changes[i] : 0;
    var l = changes[i] < 0 ? -changes[i] : 0;
    avgGain = (avgGain * (period - 1) + g) / period;
    avgLoss = (avgLoss * (period - 1) + l) / period;
  }

  if (avgLoss === 0) return 100;
  return 100 - (100 / (1 + avgGain / avgLoss));
}

/* Exponential Moving Average */
function calcEMA(prices, period) {
  if (!prices || prices.length < period) {
    return prices ? prices[prices.length - 1] : 0;
  }
  var k = 2 / (period + 1);
  var sum = 0;
  for (var i = 0; i < period; i++) sum += prices[i];
  var ema = sum / period;
  for (var j = period; j < prices.length; j++) {
    ema = prices[j] * k + ema * (1 - k);
  }
  return ema;
}

/* Bollinger Bands (20-period, 2 std dev).
   Returns { upper, middle, lower, pctB, std }
   pctB: 0 = at lower band, 1 = at upper band, <0 = below, >1 = above */
function calcBollinger(prices, period, mult) {
  period = period || 20;
  mult   = mult   || 2;
  if (!prices || prices.length < period) return null;
  var slice = prices.slice(-period);
  var mean  = slice.reduce(function(a, b) { return a + b; }, 0) / period;
  var variance = slice.reduce(function(a, b) {
    return a + (b - mean) * (b - mean);
  }, 0) / period;
  var std   = Math.sqrt(variance);
  var upper = mean + mult * std;
  var lower = mean - mult * std;
  var cur   = prices[prices.length - 1];
  var pctB  = std === 0 ? 0.5 : (cur - lower) / (upper - lower);
  return { upper: upper, middle: mean, lower: lower, pctB: pctB, std: std };
}

/* ── Score-based signal ──────────────────────────────────────────
   Returns { label, color, icon, action, score, reasons }
   Factors: Wilder RSI, Bollinger pctB, EMA crossover,
            24h momentum (contrarian), 7d trend, 1h near-term, Vol/MC
────────────────────────────────────────────────────────────────── */
function getSignal(rsi, c1h, c24h, c7d, spark, volMC) {
  var score = 0;
  var reasons = [];
  var bb = (spark && spark.length >= 20) ? calcBollinger(spark, 20, 2) : null;

  /* ── 1. Wilder RSI (primary driver) ── */
  if (rsi < 20) {
    score += 5;
    reasons.push('RSI extremely oversold (' + rsi.toFixed(0) + ') — rare buying zone');
  } else if (rsi < 30) {
    score += 3;
    reasons.push('RSI oversold (' + rsi.toFixed(0) + ') — potential reversal');
  } else if (rsi < 40) {
    score += 1;
    reasons.push('RSI below midpoint (' + rsi.toFixed(0) + ') — mild oversold bias');
  } else if (rsi > 80) {
    score -= 5;
    reasons.push('RSI extremely overbought (' + rsi.toFixed(0) + ') — high reversal risk');
  } else if (rsi > 70) {
    score -= 3;
    reasons.push('RSI overbought (' + rsi.toFixed(0) + ') — consider taking profit');
  } else if (rsi > 60) {
    score -= 1;
    reasons.push('RSI above midpoint (' + rsi.toFixed(0) + ') — mild overbought bias');
  } else {
    reasons.push('RSI neutral (' + rsi.toFixed(0) + ') — no momentum edge');
  }

  /* ── 2. Bollinger Bands ── */
  if (bb) {
    var pctLabel = (bb.pctB * 100).toFixed(0) + '%';
    if (bb.pctB < 0) {
      score += 2;
      reasons.push('Price below lower Bollinger Band (pctB=' + pctLabel + ') — extremely oversold');
    } else if (bb.pctB < 0.2) {
      score += 1;
      reasons.push('Price near lower Bollinger Band (pctB=' + pctLabel + ')');
    } else if (bb.pctB > 1) {
      score -= 2;
      reasons.push('Price above upper Bollinger Band (pctB=' + pctLabel + ') — extremely overbought');
    } else if (bb.pctB > 0.8) {
      score -= 1;
      reasons.push('Price near upper Bollinger Band (pctB=' + pctLabel + ')');
    } else {
      reasons.push('Price within Bollinger Bands (pctB=' + pctLabel + ')');
    }
  }

  /* ── 3. EMA 12 / 26 crossover (hourly) ── */
  if (spark && spark.length >= 48) {
    var ema12 = calcEMA(spark, 12);
    var ema26 = calcEMA(spark, 26);
    var cur   = spark[spark.length - 1];
    if (ema12 < ema26 && cur < ema12) {
      score += 1;
      reasons.push('Price below EMA12 & EMA26 — downtrend, reversal watch');
    } else if (ema12 > ema26 && cur < ema12) {
      score += 1;
      reasons.push('EMA bullish cross but price pulled back — potential entry');
    } else if (ema12 > ema26 && cur > ema12) {
      score -= 1;
      reasons.push('Price above EMA12 & EMA26 — uptrend intact');
    } else {
      reasons.push('EMA12 & EMA26 mixed — no clear cross');
    }
  }

  /* ── 4. 24h momentum (contrarian: big drops = buy opportunity) ── */
  var c24 = c24h || 0;
  if (c24 < -10) {
    score += 3;
    reasons.push('Major 24h dip (' + c24.toFixed(1) + '%) — potential oversold bounce');
  } else if (c24 < -4) {
    score += 1;
    reasons.push('24h pullback (' + c24.toFixed(1) + '%) — watch for support');
  } else if (c24 > 10) {
    score -= 3;
    reasons.push('Large 24h pump (+' + c24.toFixed(1) + '%) — reversal risk');
  } else if (c24 > 4) {
    score -= 1;
    reasons.push('Strong 24h gain (+' + c24.toFixed(1) + '%) — overbought near-term');
  }

  /* ── 5. 7d trend ── */
  var c7 = c7d || 0;
  if (c7 < -25) {
    score += 2;
    reasons.push('Major 7d decline (' + c7.toFixed(1) + '%) — deep discount zone');
  } else if (c7 < -10) {
    score += 1;
    reasons.push('7d downtrend (' + c7.toFixed(1) + '%) — potential accumulation');
  } else if (c7 > 25) {
    score -= 2;
    reasons.push('7d surge (+' + c7.toFixed(1) + '%) — may be overextended');
  } else if (c7 > 10) {
    score -= 1;
    reasons.push('Strong 7d run (+' + c7.toFixed(1) + '%) — watch for exhaustion');
  }

  /* ── 6. 1h near-term momentum ── */
  var c1 = c1h || 0;
  if (c1 < -3) {
    score += 1;
    reasons.push('Dropping in last 1h (' + c1.toFixed(1) + '%) — near-term weakness');
  } else if (c1 > 3) {
    score -= 1;
    reasons.push('Rising fast in last 1h (+' + c1.toFixed(1) + '%) — near-term heat');
  }

  /* ── 7. Volume / Market Cap ratio (signal amplifier) ── */
  if (volMC && volMC > 0.08) {
    var volPct = (volMC * 100).toFixed(0) + '% of MC';
    if (score > 0) {
      score += 1;
      reasons.push('High volume (' + volPct + ') confirms bullish signal');
    } else if (score < 0) {
      score -= 1;
      reasons.push('High volume (' + volPct + ') confirms bearish signal');
    } else {
      reasons.push('High volume day (' + volPct + ') — watch for direction break');
    }
  }

  /* ── Map score → signal ── */
  var label, color, icon, action;
  if (score >= 5) {
    label = 'STRONG BUY'; color = '#198754'; icon = '🚀';
    action = 'Multiple indicators confirm oversold. Enter in small tranches, not all at once. Set stop-loss below 24h low.';
  } else if (score >= 2) {
    label = 'BUY'; color = '#20c997'; icon = '📈';
    action = 'Bullish lean. Watch for price stabilization before entering full position. Stop-loss below recent support.';
  } else if (score >= -1) {
    label = 'HOLD'; color = '#ffc107'; icon = '⏸️';
    action = 'No clear edge. Sit on hands until a stronger signal develops. Preserving capital is a valid trade.';
  } else if (score >= -4) {
    label = 'SELL'; color = '#fd7e14'; icon = '📉';
    action = 'Bearish lean. Consider reducing exposure or tightening stop-losses to protect existing gains.';
  } else {
    label = 'STRONG SELL'; color = '#dc3545'; icon = '⚠️';
    action = 'Multiple overbought/bearish signals. Consider taking profit now. High near-term correction risk.';
  }

  return { label: label, color: color, icon: icon, action: action, score: score, reasons: reasons, bb: bb };
}

/* Compute all indicators for one coin. Returns everything needed for render + sort. */
function computeCoin(coin) {
  var spark = (coin.sparkline_in_7d || {}).price || [];
  var rsi   = calcRSI(spark);
  var c1    = coin.price_change_percentage_1h_in_currency  || 0;
  var c24   = coin.price_change_percentage_24h_in_currency || coin.price_change_percentage_24h || 0;
  var c7    = coin.price_change_percentage_7d_in_currency  || 0;
  var volMC = (coin.total_volume && coin.market_cap) ? coin.total_volume / coin.market_cap : 0;
  var sig   = getSignal(rsi, c1, c24, c7, spark, volMC);
  return { rsi: rsi, c1: c1, c24: c24, c7: c7, volMC: volMC, spark: spark, signal: sig };
}

/* ═══════════════════════════════════════════════════════════════════
   FORMATTING HELPERS
═══════════════════════════════════════════════════════════════════ */

function formatPrice(p) {
  if (!p && p !== 0) return '—';
  if (p >= 1000)  return '$' + p.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  if (p >= 1)     return '$' + p.toFixed(4);
  if (p >= 0.001) return '$' + p.toFixed(5);
  return '$' + p.toFixed(8);
}

function formatLarge(n) {
  if (!n) return '—';
  if (n >= 1e12) return '$' + (n / 1e12).toFixed(2) + 'T';
  if (n >= 1e9)  return '$' + (n / 1e9).toFixed(2)  + 'B';
  if (n >= 1e6)  return '$' + (n / 1e6).toFixed(2)  + 'M';
  return '$' + n.toLocaleString('en-US');
}

function changeBadge(pct, showSign) {
  if (pct === null || pct === undefined) return '<span class="text-secondary">—</span>';
  var abs   = Math.abs(pct).toFixed(2);
  var cls   = pct >= 0 ? 'text-success' : 'text-danger';
  var arrow = pct >= 0 ? '▲' : '▼';
  return '<span class="' + cls + ' fw-semibold">' + arrow + ' ' + abs + '%</span>';
}

/* Clean SVG sparkline using polygon fill + polyline stroke.
   Shows full 7-day history (all 168 hourly points). */
function sparklineSVG(prices, width, height) {
  width  = width  || 130;
  height = height || 46;
  if (!prices || prices.length < 2) {
    return '<svg width="' + width + '" height="' + height + '"></svg>';
  }
  var min  = Math.min.apply(null, prices);
  var max  = Math.max.apply(null, prices);
  var rng  = max - min || 1;
  var pad  = 2;
  var W    = width  - pad * 2;
  var H    = height - pad * 2;
  var n    = prices.length - 1;

  var ptArr = prices.map(function(p, i) {
    return [
      pad + (i / n) * W,
      pad + H - ((p - min) / rng) * H
    ];
  });

  var polyline = ptArr.map(function(p) {
    return p[0].toFixed(1) + ',' + p[1].toFixed(1);
  }).join(' ');

  var bottom   = (pad + H).toFixed(1);
  var firstX   = ptArr[0][0].toFixed(1);
  var lastX    = ptArr[ptArr.length - 1][0].toFixed(1);
  var polygon  = firstX + ',' + bottom + ' ' + polyline + ' ' + lastX + ',' + bottom;

  var isUp   = prices[prices.length - 1] >= prices[0];
  var stroke = isUp ? '#198754' : '#dc3545';

  return '<svg width="' + width + '" height="' + height +
    '" viewBox="0 0 ' + width + ' ' + height + '">' +
    '<polygon points="' + polygon + '" fill="' + stroke + '" fill-opacity="0.12"/>' +
    '<polyline points="' + polyline + '" fill="none" stroke="' + stroke +
    '" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"/>' +
    '</svg>';
}

/* ═══════════════════════════════════════════════════════════════════
   PRICE ALERTS  (localStorage — no backend needed)
   Format stored: { 'BTC': { price: 45000, dir: 'above'|'below', set: timestamp } }
═══════════════════════════════════════════════════════════════════ */

var _alerts = {};

function loadAlerts() {
  try { _alerts = JSON.parse(localStorage.getItem('csAlerts') || '{}'); }
  catch(e) { _alerts = {}; }
}

function saveAlerts() {
  try { localStorage.setItem('csAlerts', JSON.stringify(_alerts)); }
  catch(e) {}
}

function setAlert(symbol, price, dir) {
  _alerts[symbol.toUpperCase()] = { price: price, dir: dir, set: Date.now() };
  saveAlerts();
}

function removeAlert(symbol) {
  delete _alerts[symbol.toUpperCase()];
  saveAlerts();
}

function hasAlert(symbol) {
  return !!_alerts[symbol.toUpperCase()];
}

/* Returns array of triggered alerts (each = {symbol, name, current, target, dir}).
   Fired alerts are removed from storage automatically. */
function checkAlerts(coinDataArr) {
  var triggered = [];
  coinDataArr.forEach(function(item) {
    var sym   = item.coin.symbol.toUpperCase();
    var alert = _alerts[sym];
    if (!alert) return;
    var cur = item.coin.current_price;
    if ((alert.dir === 'above' && cur >= alert.price) ||
        (alert.dir === 'below' && cur <= alert.price)) {
      triggered.push({
        symbol:  sym,
        name:    item.coin.name,
        current: cur,
        target:  alert.price,
        dir:     alert.dir
      });
      removeAlert(sym);
    }
  });
  return triggered;
}

/* ═══════════════════════════════════════════════════════════════════
   API FETCH
═══════════════════════════════════════════════════════════════════ */

function fetchCoins() {
  var ids = COINS.map(function(c) { return c.id; }).join(',');
  var url = 'https://api.coingecko.com/api/v3/coins/markets' +
    '?vs_currency=usd' +
    '&ids=' + ids +
    '&order=market_cap_desc' +
    '&per_page=10&page=1' +
    '&sparkline=true' +
    '&price_change_percentage=1h,24h,7d';
  return fetch(url).then(function(res) {
    if (!res.ok) {
      throw new Error('CoinGecko ' + res.status + ' — rate limited. Auto-retry in 2 min.');
    }
    return res.json();
  });
}

function fetchFNG() {
  return fetch('https://api.alternative.me/fng/')
    .then(function(r) { return r.json(); })
    .then(function(d) {
      return { value: parseInt(d.data[0].value), label: d.data[0].value_classification };
    })
    .catch(function() { return null; });
}

/* ═══════════════════════════════════════════════════════════════════
   SORT
═══════════════════════════════════════════════════════════════════ */

var SIGNAL_ORDER = {
  'STRONG BUY': 0, 'BUY': 1, 'HOLD': 2, 'SELL': 3, 'STRONG SELL': 4
};

function sortData(arr, by) {
  var copy = arr.slice();
  if (by === 'signal') {
    copy.sort(function(a, b) {
      var ao = SIGNAL_ORDER[a.computed.signal.label] || 2;
      var bo = SIGNAL_ORDER[b.computed.signal.label] || 2;
      if (ao !== bo) return ao - bo;
      return b.computed.signal.score - a.computed.signal.score;
    });
  } else if (by === '24h') {
    copy.sort(function(a, b) { return b.computed.c24 - a.computed.c24; });
  } else if (by === '1h') {
    copy.sort(function(a, b) { return b.computed.c1 - a.computed.c1; });
  } else if (by === 'mcap') {
    copy.sort(function(a, b) { return (b.coin.market_cap || 0) - (a.coin.market_cap || 0); });
  }
  return copy;
}

/* ═══════════════════════════════════════════════════════════════════
   RENDER — Fear & Greed
═══════════════════════════════════════════════════════════════════ */

function renderFNG(data) {
  if (!data) return;
  var v     = data.value;
  var color = v < 25 ? '#dc3545' : v < 45 ? '#fd7e14' : v < 55 ? '#ffc107' : v < 75 ? '#20c997' : '#198754';
  var el    = document.getElementById('fng-value');
  var lbl   = document.getElementById('fng-label');
  var bar   = document.getElementById('fng-bar');
  if (el)  { el.textContent = v; el.style.color = color; }
  if (lbl) { lbl.textContent = data.label; lbl.style.color = color; }
  if (bar) { bar.style.width = v + '%'; bar.style.background = color; }
}

/* ═══════════════════════════════════════════════════════════════════
   RENDER — Signal summary bar
═══════════════════════════════════════════════════════════════════ */

function renderSummary(arr) {
  var buys = 0, holds = 0, sells = 0;
  arr.forEach(function(item) {
    var lbl = item.computed.signal.label;
    if (lbl.indexOf('BUY') !== -1)  buys++;
    else if (lbl.indexOf('SELL') !== -1) sells++;
    else holds++;
  });
  var g = function(id) { return document.getElementById(id); };
  if (g('count-buy'))  g('count-buy').textContent  = buys;
  if (g('count-hold')) g('count-hold').textContent = holds;
  if (g('count-sell')) g('count-sell').textContent = sells;
}

/* ═══════════════════════════════════════════════════════════════════
   RENDER — Alert banner for triggered alerts
═══════════════════════════════════════════════════════════════════ */

function renderAlertBanner(triggered) {
  var wrap = document.getElementById('alert-banner');
  if (!wrap) return;
  if (!triggered || triggered.length === 0) { wrap.innerHTML = ''; return; }
  var html = triggered.map(function(t) {
    var dir  = t.dir === 'above' ? '▲ crossed above' : '▼ dropped below';
    var cls  = t.dir === 'above' ? 'alert-success' : 'alert-danger';
    return '<div class="alert ' + cls + ' alert-dismissible fade show d-flex align-items-center gap-2 py-2 mb-2" role="alert">' +
      '<span class="fw-bold">🔔 ' + t.symbol + ' Alert!</span>' +
      '<span>' + t.name + ' ' + dir + ' your target of <strong>' + formatPrice(t.target) + '</strong>. ' +
      'Current: <strong>' + formatPrice(t.current) + '</strong></span>' +
      '<button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>' +
      '</div>';
  }).join('');
  wrap.innerHTML = html;
}

/* ═══════════════════════════════════════════════════════════════════
   RENDER — Coin cards
═══════════════════════════════════════════════════════════════════ */

function renderCoins(arr) {
  var grid = document.getElementById('coin-grid');
  if (!grid) return;
  grid.innerHTML = '';

  arr.forEach(function(item) {
    var coin = item.coin;
    var cx   = item.computed;
    var sig  = cx.signal;
    var rsi  = cx.rsi;
    var bb   = sig.bb;

    /* RSI color: gradient from green (oversold) → yellow (neutral) → red (overbought) */
    var rsiColor = rsi < 30 ? '#198754' :
                   rsi < 40 ? '#20c997' :
                   rsi < 60 ? '#ffc107' :
                   rsi < 70 ? '#fd7e14' : '#dc3545';
    var rsiZone = rsi < 30 ? 'Oversold' : rsi > 70 ? 'Overbought' : 'Neutral';

    /* EMA display */
    var ema12Val = cx.spark.length >= 12 ? calcEMA(cx.spark, 12) : null;
    var ema26Val = cx.spark.length >= 26 ? calcEMA(cx.spark, 26) : null;
    var emaTrend = ema12Val && ema26Val
      ? (ema12Val > ema26Val
          ? '<span class="text-success fw-semibold">▲ Bullish</span>'
          : '<span class="text-danger fw-semibold">▼ Bearish</span>')
      : '—';

    /* Bollinger display */
    var bbDisplay = bb
      ? '<span style="color:' + (bb.pctB < 0.2 ? '#20c997' : bb.pctB > 0.8 ? '#fd7e14' : 'var(--bs-secondary-color)') + '">' +
          (bb.pctB < 0 ? '↙ Below LB' : bb.pctB > 1 ? '↗ Above UB' : 'Within bands') +
          ' (' + (bb.pctB * 100).toFixed(0) + '%)' +
        '</span>'
      : '—';

    /* Alert button state */
    var sym       = coin.symbol.toUpperCase();
    var alertData = _alerts[sym];
    var alertBtn  = alertData
      ? '<button class="btn btn-xs alert-coin-remove" data-sym="' + sym + '" ' +
          'style="font-size:.65rem;padding:2px 7px;border-radius:4px;border:1px solid #dc3545;color:#dc3545;background:transparent;cursor:pointer" ' +
          'title="Remove alert for ' + sym + '">' +
          '🔔 Alert: ' + formatPrice(alertData.price) + ' ' + (alertData.dir === 'above' ? '▲' : '▼') +
        '</button>'
      : '<button class="btn btn-xs alert-coin-set" data-sym="' + sym + '" data-price="' + coin.current_price + '" data-name="' + coin.name + '" ' +
          'style="font-size:.65rem;padding:2px 7px;border-radius:4px;border:1px solid rgba(128,128,128,.4);color:var(--bs-secondary-color);background:transparent;cursor:pointer" ' +
          'title="Set price alert for ' + sym + '">' +
          '🔔 Set Alert' +
        '</button>';

    /* Binance trade link */
    var tradeLink = '<a href="https://www.binance.com/en/trade/' + sym + '_USDT" target="_blank" rel="noopener" ' +
      'class="btn btn-xs" style="font-size:.65rem;padding:2px 7px;border-radius:4px;border:1px solid rgba(240,185,11,.5);color:#F0B90B;background:transparent;text-decoration:none">' +
      '⚡ Trade</a>';

    var col = document.createElement('div');
    col.className = 'col-12 col-md-6 col-lg-4 col-xl-3';
    col.innerHTML =
      '<div class="card h-100 coin-card" style="border-left:3px solid ' + sig.color + '">' +

        /* header */
        '<div class="card-header d-flex align-items-center gap-2 py-2">' +
          '<img src="' + coin.image + '" alt="' + sym + '" width="22" height="22" ' +
          'style="border-radius:50%;flex-shrink:0" onerror="this.style.display=\'none\'">' +
          '<span class="fw-bold">' + sym + '</span>' +
          '<span class="text-secondary small flex-grow-1">' + coin.name + '</span>' +
          '<span class="signal-badge" ' +
          'style="background:' + sig.color + '18;color:' + sig.color + ';border:1px solid ' + sig.color + '35">' +
            sig.icon + ' ' + sig.label +
          '</span>' +
        '</div>' +

        /* body */
        '<div class="card-body py-2 px-3">' +

          /* price row */
          '<div class="d-flex justify-content-between align-items-start mb-2">' +
            '<div>' +
              '<div class="fw-bold fs-5 lh-1">' + formatPrice(coin.current_price) + '</div>' +
              '<div class="small mt-1 d-flex gap-2 flex-wrap">' +
                changeBadge(cx.c1)  + ' <span class="text-secondary">1h</span>' +
                ' ' + changeBadge(cx.c24) + ' <span class="text-secondary">24h</span>' +
                ' ' + changeBadge(cx.c7)  + ' <span class="text-secondary">7d</span>' +
              '</div>' +
            '</div>' +
            /* full 7-day sparkline */
            '<div class="sparkline-wrap ms-1">' + sparklineSVG(cx.spark, 130, 46) + '</div>' +
          '</div>' +

          /* RSI bar */
          '<div class="mb-2">' +
            '<div class="d-flex justify-content-between align-items-center mb-1">' +
              '<span class="small text-secondary">RSI(14)</span>' +
              '<span class="small fw-semibold" style="color:' + rsiColor + '">' +
                rsi.toFixed(1) + ' — ' + rsiZone +
              '</span>' +
            '</div>' +
            '<div class="position-relative" style="height:6px;background:rgba(128,128,128,.15);border-radius:4px">' +
              '<div style="position:absolute;left:30%;top:-2px;width:1px;height:10px;background:rgba(128,128,128,.35)"></div>' +
              '<div style="position:absolute;left:70%;top:-2px;width:1px;height:10px;background:rgba(128,128,128,.35)"></div>' +
              '<div style="width:' + Math.min(rsi, 100).toFixed(1) + '%;height:100%;background:' + rsiColor +
              ';border-radius:4px;transition:width .5s ease"></div>' +
            '</div>' +
            '<div class="d-flex justify-content-between mt-1" style="font-size:.6rem;color:var(--bs-secondary-color)">' +
              '<span>Oversold ←30</span><span>70→ Overbought</span>' +
            '</div>' +
          '</div>' +

          /* indicator row */
          '<div class="d-flex gap-3 mb-2" style="font-size:.75rem">' +
            '<div><span class="text-secondary">EMA:</span> ' + emaTrend + '</div>' +
            '<div><span class="text-secondary">BB:</span> ' + bbDisplay + '</div>' +
          '</div>' +

          /* stats grid */
          '<div class="row g-0 mb-2" style="font-size:.75rem">' +
            '<div class="col-6 text-secondary py-1 border-bottom" style="border-color:rgba(128,128,128,.1)!important">Mkt Cap</div>' +
            '<div class="col-6 text-end fw-semibold py-1 border-bottom" style="border-color:rgba(128,128,128,.1)!important">' + formatLarge(coin.market_cap) + '</div>' +
            '<div class="col-6 text-secondary py-1 border-bottom" style="border-color:rgba(128,128,128,.1)!important">24h Volume</div>' +
            '<div class="col-6 text-end fw-semibold py-1 border-bottom" style="border-color:rgba(128,128,128,.1)!important">' + formatLarge(coin.total_volume) + '</div>' +
            '<div class="col-6 text-secondary py-1">24h High / Low</div>' +
            '<div class="col-6 text-end py-1">' +
              '<span class="text-success">' + formatPrice(coin.high_24h) + '</span>' +
              ' <span class="text-secondary">/</span> ' +
              '<span class="text-danger">' + formatPrice(coin.low_24h) + '</span>' +
            '</div>' +
          '</div>' +

          /* action hint */
          '<div class="small p-2 rounded mb-2" ' +
          'style="background:' + sig.color + '0d;border:1px solid ' + sig.color + '25;color:' + sig.color + ';line-height:1.4">' +
            sig.action +
          '</div>' +

          /* expandable reasons */
          '<details class="mb-2">' +
            '<summary class="small text-secondary" style="cursor:pointer;user-select:none">' +
              '🔍 Why this signal? (' + sig.reasons.length + ' factors)' +
            '</summary>' +
            '<ul class="small mt-1 mb-0 ps-3 text-secondary" style="line-height:1.8">' +
              sig.reasons.map(function(r) { return '<li>' + r + '</li>'; }).join('') +
            '</ul>' +
          '</details>' +

          /* alert + trade buttons */
          '<div class="d-flex gap-2 flex-wrap">' +
            alertBtn + ' ' + tradeLink +
          '</div>' +

        '</div>' + /* /card-body */
      '</div>';   /* /card */

    grid.appendChild(col);
  });

  /* Wire up alert buttons after DOM insert */
  grid.querySelectorAll('.alert-coin-set').forEach(function(btn) {
    btn.addEventListener('click', function() {
      openAlertModal(btn.dataset.sym, parseFloat(btn.dataset.price), btn.dataset.name);
    });
  });
  grid.querySelectorAll('.alert-coin-remove').forEach(function(btn) {
    btn.addEventListener('click', function() {
      removeAlert(btn.dataset.sym);
      if (_lastComputed) renderCoins(sortData(_lastComputed, _sortBy));
    });
  });
}

/* ═══════════════════════════════════════════════════════════════════
   ALERT MODAL
═══════════════════════════════════════════════════════════════════ */

var _alertTarget = { sym: null, price: 0, name: '' };

function openAlertModal(sym, price, name) {
  _alertTarget = { sym: sym, price: price, name: name };
  var el = document.getElementById('alertModalTitle');
  var pr = document.getElementById('alertCurrentPrice');
  var inp = document.getElementById('alertPrice');
  if (el)  el.textContent = '🔔 Set Alert — ' + sym;
  if (pr)  pr.textContent = formatPrice(price);
  if (inp) { inp.value = price.toFixed(price >= 1 ? 2 : 6); inp.focus(); }
  var modal = document.getElementById('alertModal');
  if (modal && window.bootstrap) {
    bootstrap.Modal.getOrCreateInstance(modal).show();
  }
}

function wireAlertModal() {
  var aboveBtn = document.getElementById('alertAboveBtn');
  var belowBtn = document.getElementById('alertBelowBtn');
  var modal    = document.getElementById('alertModal');

  function saveAndClose(dir) {
    var inp = document.getElementById('alertPrice');
    var val = parseFloat(inp ? inp.value : '');
    if (!val || val <= 0) { if (inp) inp.classList.add('is-invalid'); return; }
    if (inp) inp.classList.remove('is-invalid');
    setAlert(_alertTarget.sym, val, dir);
    if (modal && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modal).hide();
    if (_lastComputed) renderCoins(sortData(_lastComputed, _sortBy));
  }

  if (aboveBtn) aboveBtn.addEventListener('click', function() { saveAndClose('above'); });
  if (belowBtn) belowBtn.addEventListener('click', function() { saveAndClose('below'); });
}

/* ═══════════════════════════════════════════════════════════════════
   COUNTDOWN TIMER
═══════════════════════════════════════════════════════════════════ */

var _countdownTimer = null;
var _countdownSecs  = REFRESH_SECS;

function startCountdown() {
  if (_countdownTimer) clearInterval(_countdownTimer);
  _countdownSecs = REFRESH_SECS;
  _updateCountdown();
  _countdownTimer = setInterval(function() {
    _countdownSecs--;
    if (_countdownSecs < 0) _countdownSecs = REFRESH_SECS;
    _updateCountdown();
  }, 1000);
}

function _updateCountdown() {
  var el = document.getElementById('countdown');
  if (!el) return;
  var m = Math.floor(_countdownSecs / 60);
  var s = _countdownSecs % 60;
  el.textContent = m + ':' + (s < 10 ? '0' : '') + s;
}

/* ═══════════════════════════════════════════════════════════════════
   SORT CONTROLS
═══════════════════════════════════════════════════════════════════ */

function setSortActive(by) {
  _sortBy = by;
  ['sort-signal','sort-24h','sort-1h','sort-mcap'].forEach(function(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('active', 'btn-secondary');
    el.classList.add('btn-outline-secondary');
  });
  var active = document.getElementById('sort-' + by);
  if (active) {
    active.classList.remove('btn-outline-secondary');
    active.classList.add('btn-secondary', 'active');
  }
}

function wireSortButtons() {
  ['signal','24h','1h','mcap'].forEach(function(by) {
    var btn = document.getElementById('sort-' + by);
    if (!btn) return;
    btn.addEventListener('click', function() {
      setSortActive(by);
      if (_lastComputed) renderCoins(sortData(_lastComputed, by));
    });
  });
}

/* ═══════════════════════════════════════════════════════════════════
   STATUS HELPERS
═══════════════════════════════════════════════════════════════════ */

function setStatus(msg) {
  var el = document.getElementById('last-updated');
  if (el) el.textContent = msg;
}

function setError(msg) {
  var bar = document.getElementById('error-bar');
  var txt = document.getElementById('error-msg');
  if (!bar) return;
  if (msg) { bar.classList.remove('d-none'); if (txt) txt.textContent = msg; }
  else       bar.classList.add('d-none');
}

function startSpin() {
  var btn = document.getElementById('btn-refresh');
  if (btn) { btn.innerHTML = '<span class="refresh-spin">⟳</span> Fetching…'; btn.disabled = true; }
}

function stopSpin() {
  var btn = document.getElementById('btn-refresh');
  if (btn) { btn.innerHTML = '⟳ Refresh Now'; btn.disabled = false; }
}

/* ═══════════════════════════════════════════════════════════════════
   REFRESH ORCHESTRATION
═══════════════════════════════════════════════════════════════════ */

var _autoInterval = null;
var _spinning     = false;

function refresh() {
  if (_spinning) return;
  _spinning = true;
  startSpin();
  setStatus('Fetching live data…');

  Promise.all([fetchCoins(), fetchFNG()])
    .then(function(res) {
      var coins = res[0];
      var fng   = res[1];

      /* Build computed array once — used by render, summary, and sort */
      var computed = coins.map(function(coin) {
        return { coin: coin, computed: computeCoin(coin) };
      });
      _lastComputed = computed;

      /* Check price alerts before render */
      var triggered = checkAlerts(computed);
      renderAlertBanner(triggered);

      /* Render in current sort order */
      renderCoins(sortData(computed, _sortBy));
      renderSummary(computed);
      renderFNG(fng);
      setError(null);
      setStatus('Updated ' + new Date().toLocaleTimeString());
    })
    .catch(function(err) {
      setError(err.message || 'API error — retrying automatically.');
      setStatus('Last fetch failed');
    })
    .finally(function() {
      _spinning = false;
      stopSpin();
    });
}

function startAutoRefresh() {
  if (_autoInterval) clearInterval(_autoInterval);
  _autoInterval = setInterval(refresh, REFRESH_SECS * 1000);
}

/* ═══════════════════════════════════════════════════════════════════
   INIT
═══════════════════════════════════════════════════════════════════ */

document.addEventListener('DOMContentLoaded', function() {
  loadAlerts();
  wireSortButtons();
  wireAlertModal();
  setSortActive('signal');

  refresh();
  startAutoRefresh();
  startCountdown();

  document.getElementById('btn-refresh') &&
    document.getElementById('btn-refresh').addEventListener('click', function() {
      startAutoRefresh();
      startCountdown();
      refresh();
    });
});
