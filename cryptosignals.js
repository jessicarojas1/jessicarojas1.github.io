/* ═══════════════════════════════════════════════════════════════════
   Crypto Trading Signals — cryptosignals.js
   Data: CoinGecko (free tier) + alternative.me Fear & Greed
   Refresh: every 2 minutes (rate-limit safe)
═══════════════════════════════════════════════════════════════════ */

/* ── Coin list ── */
var COINS = [
  { id: 'bitcoin',       symbol: 'BTC',  name: 'Bitcoin' },
  { id: 'ethereum',      symbol: 'ETH',  name: 'Ethereum' },
  { id: 'binancecoin',   symbol: 'BNB',  name: 'BNB' },
  { id: 'solana',        symbol: 'SOL',  name: 'Solana' },
  { id: 'ripple',        symbol: 'XRP',  name: 'XRP' },
  { id: 'dogecoin',      symbol: 'DOGE', name: 'Dogecoin' },
  { id: 'cardano',       symbol: 'ADA',  name: 'Cardano' },
  { id: 'avalanche-2',   symbol: 'AVAX', name: 'Avalanche' }
];

/* ═══════════════════════════════════════════════════════════════════
   TECHNICAL ANALYSIS
═══════════════════════════════════════════════════════════════════ */

function calcRSI(prices, period) {
  period = period || 14;
  if (!prices || prices.length < period + 1) return 50;
  var gains = 0, losses = 0;
  for (var i = prices.length - period; i < prices.length; i++) {
    var diff = prices[i] - prices[i - 1];
    if (diff >= 0) gains += diff;
    else losses -= diff;
  }
  var avgGain = gains / period;
  var avgLoss = losses / period;
  if (avgLoss === 0) return 100;
  var rs = avgGain / avgLoss;
  return 100 - (100 / (1 + rs));
}

function calcEMA(prices, period) {
  if (!prices || prices.length < period) return prices ? prices[prices.length - 1] : 0;
  var k = 2 / (period + 1);
  var sum = 0;
  for (var i = 0; i < period; i++) sum += prices[i];
  var ema = sum / period;
  for (var j = period; j < prices.length; j++) {
    ema = prices[j] * k + ema * (1 - k);
  }
  return ema;
}

function calcSMA(prices, period) {
  if (!prices || prices.length < period) return prices ? prices[prices.length - 1] : 0;
  var slice = prices.slice(prices.length - period);
  return slice.reduce(function(a, b) { return a + b; }, 0) / period;
}

/* Score-based signal: returns { label, color, icon, action, score, reasons } */
function getSignal(rsi, change24h, change7d, sparkline) {
  var score = 0;
  var reasons = [];

  /* ── RSI (weight: primary) ── */
  if (rsi < 20) {
    score += 5; reasons.push('RSI extremely oversold (' + rsi.toFixed(0) + ') — rare buying zone');
  } else if (rsi < 30) {
    score += 3; reasons.push('RSI oversold (' + rsi.toFixed(0) + ') — potential reversal');
  } else if (rsi < 40) {
    score += 1; reasons.push('RSI below midpoint (' + rsi.toFixed(0) + ') — slight oversold bias');
  } else if (rsi > 80) {
    score -= 5; reasons.push('RSI extremely overbought (' + rsi.toFixed(0) + ') — high reversal risk');
  } else if (rsi > 70) {
    score -= 3; reasons.push('RSI overbought (' + rsi.toFixed(0) + ') — consider taking profit');
  } else if (rsi > 60) {
    score -= 1; reasons.push('RSI above midpoint (' + rsi.toFixed(0) + ') — slight overbought bias');
  } else {
    reasons.push('RSI neutral (' + rsi.toFixed(0) + ') — no clear momentum edge');
  }

  /* ── 24h momentum (contrarian: big drops = potential entry) ── */
  var c24 = change24h || 0;
  if (c24 < -10) {
    score += 3; reasons.push('Major 24h dip (' + c24.toFixed(1) + '%) — potential oversold bounce');
  } else if (c24 < -4) {
    score += 1; reasons.push('Pullback in 24h (' + c24.toFixed(1) + '%) — watch for support');
  } else if (c24 > 10) {
    score -= 3; reasons.push('Large 24h pump (+' + c24.toFixed(1) + '%) — risk of reversal');
  } else if (c24 > 4) {
    score -= 1; reasons.push('Strong 24h gain (+' + c24.toFixed(1) + '%) — overbought near-term');
  }

  /* ── 7d trend ── */
  var c7 = change7d || 0;
  if (c7 < -25) {
    score += 2; reasons.push('Significant 7d decline (' + c7.toFixed(1) + '%) — deep discount');
  } else if (c7 < -10) {
    score += 1; reasons.push('7d downtrend (' + c7.toFixed(1) + '%) — potential accumulation zone');
  } else if (c7 > 25) {
    score -= 2; reasons.push('7d surge (+' + c7.toFixed(1) + '%) — may be overextended');
  } else if (c7 > 10) {
    score -= 1; reasons.push('Strong 7d run (+' + c7.toFixed(1) + '%) — watch for exhaustion');
  }

  /* ── EMA crossover from sparkline (hourly data) ── */
  if (sparkline && sparkline.length >= 48) {
    var ema12 = calcEMA(sparkline, 12);
    var ema26 = calcEMA(sparkline, 26);
    var current = sparkline[sparkline.length - 1];
    if (ema12 > ema26 && current > ema12) {
      score -= 1; reasons.push('Price above EMA12 & EMA26 — uptrend intact');
    } else if (ema12 < ema26 && current < ema12) {
      score += 1; reasons.push('Price below EMA12 & EMA26 — downtrend, watch for reversal');
    } else if (ema12 > ema26 && current < ema12) {
      score += 1; reasons.push('EMA bullish cross but price pulled back — possible entry');
    }
  }

  /* ── Map score to signal ── */
  var label, color, icon, action;
  if (score >= 5) {
    label = 'STRONG BUY'; color = '#198754'; icon = '🚀';
    action = 'Multiple indicators point to oversold conditions. Consider a measured entry — start small and average in.';
  } else if (score >= 2) {
    label = 'BUY'; color = '#20c997'; icon = '📈';
    action = 'Indicators lean bullish. Watch for price stabilization before entering. Use a stop-loss below recent low.';
  } else if (score >= -1) {
    label = 'HOLD'; color = '#ffc107'; icon = '⏸️';
    action = 'No clear edge. If holding, maintain position. If waiting, let signals develop before committing.';
  } else if (score >= -4) {
    label = 'SELL'; color = '#fd7e14'; icon = '📉';
    action = 'Indicators lean bearish. Consider reducing exposure or tightening stop-losses to protect gains.';
  } else {
    label = 'STRONG SELL'; color = '#dc3545'; icon = '⚠️';
    action = 'Multiple overbought signals. Consider taking profit. High risk of near-term correction.';
  }

  return { label: label, color: color, icon: icon, action: action, score: score, reasons: reasons };
}

/* ═══════════════════════════════════════════════════════════════════
   FORMATTING HELPERS
═══════════════════════════════════════════════════════════════════ */

function formatPrice(p) {
  if (!p && p !== 0) return '—';
  if (p >= 10000) return '$' + p.toLocaleString('en-US', { maximumFractionDigits: 0 });
  if (p >= 1000)  return '$' + p.toLocaleString('en-US', { maximumFractionDigits: 2 });
  if (p >= 1)     return '$' + p.toFixed(4);
  if (p >= 0.01)  return '$' + p.toFixed(5);
  return '$' + p.toFixed(7);
}

function formatLarge(n) {
  if (!n) return '—';
  if (n >= 1e12) return '$' + (n / 1e12).toFixed(2) + 'T';
  if (n >= 1e9)  return '$' + (n / 1e9).toFixed(2) + 'B';
  if (n >= 1e6)  return '$' + (n / 1e6).toFixed(2) + 'M';
  return '$' + n.toLocaleString('en-US');
}

function changeBadge(pct) {
  if (pct === null || pct === undefined) return '<span class="text-secondary">—</span>';
  var abs = Math.abs(pct).toFixed(2);
  var cls = pct >= 0 ? 'text-success' : 'text-danger';
  var arrow = pct >= 0 ? '▲' : '▼';
  return '<span class="' + cls + '">' + arrow + ' ' + abs + '%</span>';
}

function sparklineSVG(prices, width, height) {
  width = width || 130; height = height || 44;
  if (!prices || prices.length < 2) return '<svg width="' + width + '" height="' + height + '"></svg>';
  var min = Math.min.apply(null, prices);
  var max = Math.max.apply(null, prices);
  var range = max - min || 1;
  var pad = 3;
  var pts = prices.map(function(p, i) {
    var x = ((i / (prices.length - 1)) * (width - pad * 2) + pad).toFixed(1);
    var y = (height - pad - ((p - min) / range) * (height - pad * 2)).toFixed(1);
    return x + ',' + y;
  }).join(' ');
  var last = prices[prices.length - 1];
  var first = prices[0];
  var isUp = last >= first;
  var stroke = isUp ? '#198754' : '#dc3545';
  /* filled area under line */
  var firstPt = (pad) + ',' + (height - pad - ((prices[0] - min) / range) * (height - pad * 2)).toFixed(1);
  var lastX = ((1) * (width - pad * 2) + pad).toFixed(1);
  var lastY = (height - pad - ((prices[prices.length - 1] - min) / range) * (height - pad * 2)).toFixed(1);
  var fillPath = 'M ' + pad + ',' + (height - pad) + ' L ' + firstPt + ' L ' + pts.split(' ').join(' L ') + ' L ' + lastX + ',' + (height - pad) + ' Z';
  return '<svg width="' + width + '" height="' + height + '" viewBox="0 0 ' + width + ' ' + height + '" xmlns="http://www.w3.org/2000/svg">' +
    '<path d="' + fillPath + '" fill="' + stroke + '" fill-opacity="0.12"/>' +
    '<polyline points="' + pts + '" fill="none" stroke="' + stroke + '" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"/>' +
    '</svg>';
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
    '&price_change_percentage=24h,7d';
  return fetch(url).then(function(res) {
    if (!res.ok) throw new Error('CoinGecko API error ' + res.status + ' — may be rate limited. Retrying in 2 min.');
    return res.json();
  });
}

function fetchFNG() {
  return fetch('https://api.alternative.me/fng/')
    .then(function(res) { return res.json(); })
    .then(function(data) {
      return {
        value: parseInt(data.data[0].value),
        label: data.data[0].value_classification
      };
    })
    .catch(function() { return null; });
}

/* ═══════════════════════════════════════════════════════════════════
   RENDER
═══════════════════════════════════════════════════════════════════ */

function renderFNG(data) {
  if (!data) return;
  var v = data.value;
  var color = v < 25 ? '#dc3545' : v < 45 ? '#fd7e14' : v < 55 ? '#ffc107' : v < 75 ? '#20c997' : '#198754';
  var el = document.getElementById('fng-value');
  var lbl = document.getElementById('fng-label');
  var bar = document.getElementById('fng-bar');
  if (el)  { el.textContent = v; el.style.color = color; }
  if (lbl) { lbl.textContent = data.label; }
  if (bar) { bar.style.width = v + '%'; bar.style.background = color; }
}

function renderSignalSummary(coins) {
  var buys = 0, holds = 0, sells = 0;
  coins.forEach(function(coin) {
    var spark = (coin.sparkline_in_7d || {}).price || [];
    var rsi = calcRSI(spark);
    var c24 = coin.price_change_percentage_24h_in_currency || coin.price_change_percentage_24h || 0;
    var c7  = coin.price_change_percentage_7d_in_currency || 0;
    var sig = getSignal(rsi, c24, c7, spark);
    if (sig.label.indexOf('BUY') !== -1) buys++;
    else if (sig.label.indexOf('SELL') !== -1) sells++;
    else holds++;
  });
  var el = function(id) { return document.getElementById(id); };
  if (el('count-buy'))  el('count-buy').textContent  = buys;
  if (el('count-hold')) el('count-hold').textContent = holds;
  if (el('count-sell')) el('count-sell').textContent = sells;
}

function renderCoins(coins) {
  var grid = document.getElementById('coin-grid');
  if (!grid) return;
  grid.innerHTML = '';

  coins.forEach(function(coin) {
    var spark = (coin.sparkline_in_7d || {}).price || [];
    /* use last 48 hourly prices (2 days) for display, full set for RSI */
    var rsi    = calcRSI(spark);
    var c24    = coin.price_change_percentage_24h_in_currency || coin.price_change_percentage_24h || 0;
    var c7     = coin.price_change_percentage_7d_in_currency || 0;
    var signal = getSignal(rsi, c24, c7, spark);

    var rsiColor = rsi < 30 ? '#198754' : rsi > 70 ? '#dc3545' : rsi < 45 ? '#20c997' : rsi > 55 ? '#fd7e14' : '#ffc107';
    var rsiZone  = rsi < 30 ? 'Oversold' : rsi > 70 ? 'Overbought' : 'Neutral';

    /* EMA info for display */
    var ema12 = spark.length >= 12 ? calcEMA(spark, 12).toFixed(2) : null;
    var ema26 = spark.length >= 26 ? calcEMA(spark, 26).toFixed(2) : null;
    var emaTrend = '';
    if (ema12 && ema26) {
      emaTrend = parseFloat(ema12) > parseFloat(ema26)
        ? '<span class="text-success">▲ Bullish cross</span>'
        : '<span class="text-danger">▼ Bearish cross</span>';
    }

    var sparkDisplay = spark.slice(-48); /* last 2 days hourly */

    var col = document.createElement('div');
    col.className = 'col-12 col-md-6 col-lg-4 col-xl-3';
    col.innerHTML =
      '<div class="card h-100 coin-card" style="border-left:3px solid ' + signal.color + '">' +

        /* ── card header ── */
        '<div class="card-header d-flex align-items-center gap-2 py-2">' +
          '<img src="' + coin.image + '" alt="' + coin.symbol + '" width="22" height="22" style="border-radius:50%;flex-shrink:0"' +
          ' onerror="this.style.display=\'none\'">' +
          '<span class="fw-bold">' + coin.symbol.toUpperCase() + '</span>' +
          '<span class="text-secondary small flex-grow-1">' + coin.name + '</span>' +
          '<span class="signal-badge" style="background:' + signal.color + '18;color:' + signal.color + ';border:1px solid ' + signal.color + '35">' +
            signal.icon + ' ' + signal.label +
          '</span>' +
        '</div>' +

        /* ── card body ── */
        '<div class="card-body py-2 px-3">' +

          /* price + sparkline row */
          '<div class="d-flex justify-content-between align-items-start mb-2">' +
            '<div>' +
              '<div class="fw-bold fs-5 lh-1">' + formatPrice(coin.current_price) + '</div>' +
              '<div class="small mt-1">' +
                changeBadge(c24) + ' <span class="text-secondary">24h</span>' +
                ' &nbsp; ' +
                changeBadge(c7)  + ' <span class="text-secondary">7d</span>' +
              '</div>' +
            '</div>' +
            '<div class="sparkline-wrap">' + sparklineSVG(sparkDisplay) + '</div>' +
          '</div>' +

          /* RSI bar */
          '<div class="mb-2">' +
            '<div class="d-flex justify-content-between align-items-center mb-1">' +
              '<span class="small text-secondary">RSI(14)</span>' +
              '<span class="small fw-semibold" style="color:' + rsiColor + '">' + rsi.toFixed(1) + ' — ' + rsiZone + '</span>' +
            '</div>' +
            '<div class="position-relative" style="height:6px;background:rgba(128,128,128,.18);border-radius:4px;overflow:visible">' +
              /* oversold/overbought zone markers */
              '<div style="position:absolute;left:30%;top:-3px;width:1px;height:12px;background:rgba(128,128,128,.3)"></div>' +
              '<div style="position:absolute;left:70%;top:-3px;width:1px;height:12px;background:rgba(128,128,128,.3)"></div>' +
              '<div style="width:' + Math.min(rsi, 100) + '%;height:100%;background:' + rsiColor + ';border-radius:4px;transition:width .6s"></div>' +
            '</div>' +
            '<div class="d-flex justify-content-between mt-1" style="font-size:.62rem;color:var(--bs-secondary-color)">' +
              '<span>Oversold 30</span><span>70 Overbought</span>' +
            '</div>' +
          '</div>' +

          /* indicator row */
          '<div class="indicator-row d-flex gap-3 mb-2 text-secondary">' +
            '<span>EMA: ' + (emaTrend || '—') + '</span>' +
          '</div>' +

          /* stats grid */
          '<div class="row g-1 mb-2" style="font-size:.78rem">' +
            '<div class="col-6 text-secondary">Mkt Cap</div>' +
            '<div class="col-6 text-end fw-semibold">' + formatLarge(coin.market_cap) + '</div>' +
            '<div class="col-6 text-secondary">24h Vol</div>' +
            '<div class="col-6 text-end fw-semibold">' + formatLarge(coin.total_volume) + '</div>' +
            '<div class="col-6 text-secondary">24h High</div>' +
            '<div class="col-6 text-end text-success">' + formatPrice(coin.high_24h) + '</div>' +
            '<div class="col-6 text-secondary">24h Low</div>' +
            '<div class="col-6 text-end text-danger">' + formatPrice(coin.low_24h) + '</div>' +
          '</div>' +

          /* action hint */
          '<div class="small p-2 rounded mb-2" style="background:' + signal.color + '0e;border:1px solid ' + signal.color + '28;color:' + signal.color + '">' +
            signal.action +
          '</div>' +

          /* expandable reasons */
          '<details>' +
            '<summary class="small text-secondary" style="cursor:pointer;user-select:none">&#128270; Why this signal? (' + signal.reasons.length + ' factors)</summary>' +
            '<ul class="small mt-1 mb-0 ps-3 text-secondary" style="line-height:1.7">' +
              signal.reasons.map(function(r) { return '<li>' + r + '</li>'; }).join('') +
            '</ul>' +
          '</details>' +

        '</div>' + /* end card-body */
      '</div>'; /* end card */

    grid.appendChild(col);
  });
}

/* ═══════════════════════════════════════════════════════════════════
   REFRESH ORCHESTRATION
═══════════════════════════════════════════════════════════════════ */

var _interval = null;
var _spinning = false;

function setStatus(msg) {
  var el = document.getElementById('last-updated');
  if (el) el.textContent = msg;
}

function setError(msg) {
  var bar = document.getElementById('error-bar');
  var txt = document.getElementById('error-msg');
  if (!bar) return;
  if (msg) {
    bar.classList.remove('d-none');
    if (txt) txt.textContent = msg;
  } else {
    bar.classList.add('d-none');
  }
}

function startSpin() {
  var btn = document.getElementById('btn-refresh');
  if (btn) btn.innerHTML = '<span class="refresh-spin">&#8635;</span> Fetching…';
  btn && (btn.disabled = true);
}

function stopSpin() {
  var btn = document.getElementById('btn-refresh');
  if (btn) { btn.innerHTML = '&#8635; Refresh'; btn.disabled = false; }
}

function refresh() {
  if (_spinning) return;
  _spinning = true;
  startSpin();
  setStatus('Fetching live data…');

  Promise.all([fetchCoins(), fetchFNG()])
    .then(function(results) {
      var coins = results[0];
      var fng   = results[1];
      renderCoins(coins);
      renderSignalSummary(coins);
      renderFNG(fng);
      setError(null);
      setStatus('Updated ' + new Date().toLocaleTimeString());
    })
    .catch(function(err) {
      setError(err.message || 'API error — retrying in 2 min.');
      setStatus('Last attempt failed');
    })
    .finally(function() {
      _spinning = false;
      stopSpin();
    });
}

function startAutoRefresh() {
  if (_interval) clearInterval(_interval);
  _interval = setInterval(refresh, 120000); /* 2 minutes */
}

/* ═══════════════════════════════════════════════════════════════════
   INIT
═══════════════════════════════════════════════════════════════════ */

document.addEventListener('DOMContentLoaded', function() {
  refresh();
  startAutoRefresh();

  var btn = document.getElementById('btn-refresh');
  if (btn) {
    btn.addEventListener('click', function() {
      startAutoRefresh(); /* reset the 2-min timer */
      refresh();
    });
  }
});
