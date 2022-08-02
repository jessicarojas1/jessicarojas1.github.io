/*! modernizr 3.5.0 (Custom Build) | MIT *
 * https://modernizr.com/download/?-flexbox-flexwrap-printshiv-setclasses !*/
! function (e, t, n) {
    function r(e, t) {
        return typeof e === t
    }

    function o() {
        var e, t, n, o, i, a, s;
        for (var l in S)
            if (S.hasOwnProperty(l)) {
                if (e = [], t = S[l], t.name && (e.push(t.name.toLowerCase()), t.options && t.options.aliases && t.options.aliases.length))
                    for (n = 0; n < t.options.aliases.length; n++) e.push(t.options.aliases[n].toLowerCase());
                for (o = r(t.fn, "function") ? t.fn() : t.fn, i = 0; i < e.length; i++) a = e[i], s = a.split("."), 1 === s.length ? Modernizr[s[0]] = o : (!Modernizr[s[0]] || Modernizr[s[0]] instanceof Boolean || (Modernizr[s[0]] = new Boolean(Modernizr[s[0]])), Modernizr[s[0]][s[1]] = o), E.push((o ? "" : "no-") + s.join("-"))
            }
    }

    function i(e) {
        var t = b.className,
            n = Modernizr._config.classPrefix || "";
        if (x && (t = t.baseVal), Modernizr._config.enableJSClass) {
            var r = new RegExp("(^|\\s)" + n + "no-js(\\s|$)");
            t = t.replace(r, "$1" + n + "js$2")
        }
        Modernizr._config.enableClasses && (t += " " + n + e.join(" " + n), x ? b.className.baseVal = t : b.className = t)
    }

    function a(e, t) {
        return !!~("" + e).indexOf(t)
    }

    function s() {
        return "function" != typeof t.createElement ? t.createElement(arguments[0]) : x ? t.createElementNS.call(t, "http://www.w3.org/2000/svg", arguments[0]) : t.createElement.apply(t, arguments)
    }

    function l(e) {
        return e.replace(/([a-z])-([a-z])/g, function (e, t, n) {
            return t + n.toUpperCase()
        }).replace(/^-/, "")
    }

    function u(e, t) {
        return function () {
            return e.apply(t, arguments)
        }
    }

    function c(e, t, n) {
        var o;
        for (var i in e)
            if (e[i] in t) return n === !1 ? e[i] : (o = t[e[i]], r(o, "function") ? u(o, n || t) : o);
        return !1
    }

    function f(e) {
        return e.replace(/([A-Z])/g, function (e, t) {
            return "-" + t.toLowerCase()
        }).replace(/^ms-/, "-ms-")
    }

    function d(t, n, r) {
        var o;
        if ("getComputedStyle" in e) {
            o = getComputedStyle.call(e, t, n);
            var i = e.console;
            if (null !== o) r && (o = o.getPropertyValue(r));
            else if (i) {
                var a = i.error ? "error" : "log";
                i[a].call(i, "getComputedStyle returning null, its possible modernizr test results are inaccurate")
            }
        } else o = !n && t.currentStyle && t.currentStyle[r];
        return o
    }

    function p() {
        var e = t.body;
        return e || (e = s(x ? "svg" : "body"), e.fake = !0), e
    }

    function m(e, n, r, o) {
        var i, a, l, u, c = "modernizr",
            f = s("div"),
            d = p();
        if (parseInt(r, 10))
            for (; r--;) l = s("div"), l.id = o ? o[r] : c + (r + 1), f.appendChild(l);
        return i = s("style"), i.type = "text/css", i.id = "s" + c, (d.fake ? d : f).appendChild(i), d.appendChild(f), i.styleSheet ? i.styleSheet.cssText = e : i.appendChild(t.createTextNode(e)), f.id = c, d.fake && (d.style.background = "", d.style.overflow = "hidden", u = b.style.overflow, b.style.overflow = "hidden", b.appendChild(d)), a = n(f, e), d.fake ? (d.parentNode.removeChild(d), b.style.overflow = u, b.offsetHeight) : f.parentNode.removeChild(f), !!a
    }

    function h(t, r) {
        var o = t.length;
        if ("CSS" in e && "supports" in e.CSS) {
            for (; o--;)
                if (e.CSS.supports(f(t[o]), r)) return !0;
            return !1
        }
        if ("CSSSupportsRule" in e) {
            for (var i = []; o--;) i.push("(" + f(t[o]) + ":" + r + ")");
            return i = i.join(" or "), m("@supports (" + i + ") { #modernizr { position: absolute; } }", function (e) {
                return "absolute" == d(e, null, "position")
            })
        }
        return n
    }

    function v(e, t, o, i) {
        function u() {
            f && (delete j.style, delete j.modElem)
        }
        if (i = r(i, "undefined") ? !1 : i, !r(o, "undefined")) {
            var c = h(e, o);
            if (!r(c, "undefined")) return c
        }
        for (var f, d, p, m, v, g = ["modernizr", "tspan", "samp"]; !j.style && g.length;) f = !0, j.modElem = s(g.shift()), j.style = j.modElem.style;
        for (p = e.length, d = 0; p > d; d++)
            if (m = e[d], v = j.style[m], a(m, "-") && (m = l(m)), j.style[m] !== n) {
                if (i || r(o, "undefined")) return u(), "pfx" == t ? m : !0;
                try {
                    j.style[m] = o
                } catch (y) {}
                if (j.style[m] != v) return u(), "pfx" == t ? m : !0
            } return u(), !1
    }

    function g(e, t, n, o, i) {
        var a = e.charAt(0).toUpperCase() + e.slice(1),
            s = (e + " " + N.join(a + " ") + a).split(" ");
        return r(t, "string") || r(t, "undefined") ? v(s, t, o, i) : (s = (e + " " + T.join(a + " ") + a).split(" "), c(s, t, n))
    }

    function y(e, t, r) {
        return g(e, n, n, t, r)
    }
    var E = [],
        S = [],
        C = {
            _version: "3.5.0",
            _config: {
                classPrefix: "",
                enableClasses: !0,
                enableJSClass: !0,
                usePrefixes: !0
            },
            _q: [],
            on: function (e, t) {
                var n = this;
                setTimeout(function () {
                    t(n[e])
                }, 0)
            },
            addTest: function (e, t, n) {
                S.push({
                    name: e,
                    fn: t,
                    options: n
                })
            },
            addAsyncTest: function (e) {
                S.push({
                    name: null,
                    fn: e
                })
            }
        },
        Modernizr = function () {};
    Modernizr.prototype = C, Modernizr = new Modernizr;
    var b = t.documentElement,
        x = "svg" === b.nodeName.toLowerCase(),
        w = "Moz O ms Webkit",
        N = C._config.usePrefixes ? w.split(" ") : [];
    C._cssomPrefixes = N;
    var T = C._config.usePrefixes ? w.toLowerCase().split(" ") : [];
    C._domPrefixes = T;
    var _ = {
        elem: s("modernizr")
    };
    Modernizr._q.push(function () {
        delete _.elem
    });
    var j = {
        style: _.elem.style
    };
    Modernizr._q.unshift(function () {
        delete j.style
    }), C.testAllProps = g, C.testAllProps = y, Modernizr.addTest("flexbox", y("flexBasis", "1px", !0)), Modernizr.addTest("flexwrap", y("flexWrap", "wrap", !0));
    x || ! function (e, t) {
        function n(e, t) {
            var n = e.createElement("p"),
                r = e.getElementsByTagName("head")[0] || e.documentElement;
            return n.innerHTML = "x<style>" + t + "</style>", r.insertBefore(n.lastChild, r.firstChild)
        }

        function r() {
            var e = w.elements;
            return "string" == typeof e ? e.split(" ") : e
        }

        function o(e, t) {
            var n = w.elements;
            "string" != typeof n && (n = n.join(" ")), "string" != typeof e && (e = e.join(" ")), w.elements = n + " " + e, u(t)
        }

        function i(e) {
            var t = x[e[C]];
            return t || (t = {}, b++, e[C] = b, x[b] = t), t
        }

        function a(e, n, r) {
            if (n || (n = t), v) return n.createElement(e);
            r || (r = i(n));
            var o;
            return o = r.cache[e] ? r.cache[e].cloneNode() : S.test(e) ? (r.cache[e] = r.createElem(e)).cloneNode() : r.createElem(e), !o.canHaveChildren || E.test(e) || o.tagUrn ? o : r.frag.appendChild(o)
        }

        function s(e, n) {
            if (e || (e = t), v) return e.createDocumentFragment();
            n = n || i(e);
            for (var o = n.frag.cloneNode(), a = 0, s = r(), l = s.length; l > a; a++) o.createElement(s[a]);
            return o
        }

        function l(e, t) {
            t.cache || (t.cache = {}, t.createElem = e.createElement, t.createFrag = e.createDocumentFragment, t.frag = t.createFrag()), e.createElement = function (n) {
                return w.shivMethods ? a(n, e, t) : t.createElem(n)
            }, e.createDocumentFragment = Function("h,f", "return function(){var n=f.cloneNode(),c=n.createElement;h.shivMethods&&(" + r().join().replace(/[\w\-:]+/g, function (e) {
                return t.createElem(e), t.frag.createElement(e), 'c("' + e + '")'
            }) + ");return n}")(w, t.frag)
        }

        function u(e) {
            e || (e = t);
            var r = i(e);
            return !w.shivCSS || h || r.hasCSS || (r.hasCSS = !!n(e, "article,aside,dialog,figcaption,figure,footer,header,hgroup,main,nav,section{display:block}mark{background:#FF0;color:#000}template{display:none}")), v || l(e, r), e
        }

        function c(e) {
            for (var t, n = e.getElementsByTagName("*"), o = n.length, i = RegExp("^(?:" + r().join("|") + ")$", "i"), a = []; o--;) t = n[o], i.test(t.nodeName) && a.push(t.applyElement(f(t)));
            return a
        }

        function f(e) {
            for (var t, n = e.attributes, r = n.length, o = e.ownerDocument.createElement(T + ":" + e.nodeName); r--;) t = n[r], t.specified && o.setAttribute(t.nodeName, t.nodeValue);
            return o.style.cssText = e.style.cssText, o
        }

        function d(e) {
            for (var t, n = e.split("{"), o = n.length, i = RegExp("(^|[\\s,>+~])(" + r().join("|") + ")(?=[[\\s,>+~#.:]|$)", "gi"), a = "$1" + T + "\\:$2"; o--;) t = n[o] = n[o].split("}"), t[t.length - 1] = t[t.length - 1].replace(i, a), n[o] = t.join("}");
            return n.join("{")
        }

        function p(e) {
            for (var t = e.length; t--;) e[t].removeNode()
        }

        function m(e) {
            function t() {
                clearTimeout(a._removeSheetTimer), r && r.removeNode(!0), r = null
            }
            var r, o, a = i(e),
                s = e.namespaces,
                l = e.parentWindow;
            return !_ || e.printShived ? e : ("undefined" == typeof s[T] && s.add(T), l.attachEvent("onbeforeprint", function () {
                t();
                for (var i, a, s, l = e.styleSheets, u = [], f = l.length, p = Array(f); f--;) p[f] = l[f];
                for (; s = p.pop();)
                    if (!s.disabled && N.test(s.media)) {
                        try {
                            i = s.imports, a = i.length
                        } catch (m) {
                            a = 0
                        }
                        for (f = 0; a > f; f++) p.push(i[f]);
                        try {
                            u.push(s.cssText)
                        } catch (m) {}
                    } u = d(u.reverse().join("")), o = c(e), r = n(e, u)
            }), l.attachEvent("onafterprint", function () {
                p(o), clearTimeout(a._removeSheetTimer), a._removeSheetTimer = setTimeout(t, 500)
            }), e.printShived = !0, e)
        }
        var h, v, g = "3.7.3",
            y = e.html5 || {},
            E = /^<|^(?:button|map|select|textarea|object|iframe|option|optgroup)$/i,
            S = /^(?:a|b|code|div|fieldset|h1|h2|h3|h4|h5|h6|i|label|li|ol|p|q|span|strong|style|table|tbody|td|th|tr|ul)$/i,
            C = "_html5shiv",
            b = 0,
            x = {};
        ! function () {
            try {
                var e = t.createElement("a");
                e.innerHTML = "<xyz></xyz>", h = "hidden" in e, v = 1 == e.childNodes.length || function () {
                    t.createElement("a");
                    var e = t.createDocumentFragment();
                    return "undefined" == typeof e.cloneNode || "undefined" == typeof e.createDocumentFragment || "undefined" == typeof e.createElement
                }()
            } catch (n) {
                h = !0, v = !0
            }
        }();
        var w = {
            elements: y.elements || "abbr article aside audio bdi canvas data datalist details dialog figcaption figure footer header hgroup main mark meter nav output picture progress section summary template time video",
            version: g,
            shivCSS: y.shivCSS !== !1,
            supportsUnknownElements: v,
            shivMethods: y.shivMethods !== !1,
            type: "default",
            shivDocument: u,
            createElement: a,
            createDocumentFragment: s,
            addElements: o
        };
        e.html5 = w, u(t);
        var N = /^$|\b(?:all|print)\b/,
            T = "html5shiv",
            _ = !v && function () {
                var n = t.documentElement;
                return !("undefined" == typeof t.namespaces || "undefined" == typeof t.parentWindow || "undefined" == typeof n.applyElement || "undefined" == typeof n.removeNode || "undefined" == typeof e.attachEvent)
            }();
        w.type += " print", w.shivPrint = m, m(t), "object" == typeof module && module.exports && (module.exports = w)
    }("undefined" != typeof e ? e : this, t), o(), i(E), delete C.addTest, delete C.addAsyncTest;
    for (var z = 0; z < Modernizr._q.length; z++) Modernizr._q[z]();
    e.Modernizr = Modernizr
}(window, document);

/*! Respond.js v1.4.2: min/max-width media query polyfill
 * Copyright 2014 Scott Jehl
 * Licensed under MIT
 * https://j.mp/respondjs */

! function (a) {
    "use strict";
    a.matchMedia = a.matchMedia || function (a) {
        var b, c = a.documentElement,
            d = c.firstElementChild || c.firstChild,
            e = a.createElement("body"),
            f = a.createElement("div");
        return f.id = "mq-test-1", f.style.cssText = "position:absolute;top:-100em", e.style.background = "none", e.appendChild(f),
            function (a) {
                return f.innerHTML = '&shy;<style media="' + a + '"> #mq-test-1 { width: 42px; }</style>', c.insertBefore(e, d), b = 42 === f.offsetWidth, c.removeChild(e), {
                    matches: b,
                    media: a
                }
            }
    }(a.document)
}(this),
function (a) {
    "use strict";

    function b() {
        v(!0)
    }
    var c = {};
    a.respond = c, c.update = function () {};
    var d = [],
        e = function () {
            var b = !1;
            try {
                b = new a.XMLHttpRequest
            } catch (c) {
                b = new a.ActiveXObject("Microsoft.XMLHTTP")
            }
            return function () {
                return b
            }
        }(),
        f = function (a, b) {
            var c = e();
            c && (c.open("GET", a, !0), c.onreadystatechange = function () {
                4 !== c.readyState || 200 !== c.status && 304 !== c.status || b(c.responseText)
            }, 4 !== c.readyState && c.send(null))
        },
        g = function (a) {
            return a.replace(c.regex.minmaxwh, "").match(c.regex.other)
        };
    if (c.ajax = f, c.queue = d, c.unsupportedmq = g, c.regex = {
            media: /@media[^\{]+\{([^\{\}]*\{[^\}\{]*\})+/gi,
            keyframes: /@(?:\-(?:o|moz|webkit)\-)?keyframes[^\{]+\{(?:[^\{\}]*\{[^\}\{]*\})+[^\}]*\}/gi,
            comments: /\/\*[^*]*\*+([^/][^*]*\*+)*\//gi,
            urls: /(url\()['"]?([^\/\)'"][^:\)'"]+)['"]?(\))/g,
            findStyles: /@media *([^\{]+)\{([\S\s]+?)$/,
            only: /(only\s+)?([a-zA-Z]+)\s?/,
            minw: /\(\s*min\-width\s*:\s*(\s*[0-9\.]+)(px|em)\s*\)/,
            maxw: /\(\s*max\-width\s*:\s*(\s*[0-9\.]+)(px|em)\s*\)/,
            minmaxwh: /\(\s*m(in|ax)\-(height|width)\s*:\s*(\s*[0-9\.]+)(px|em)\s*\)/gi,
            other: /\([^\)]*\)/g
        }, c.mediaQueriesSupported = a.matchMedia && null !== a.matchMedia("only all") && a.matchMedia("only all").matches, !c.mediaQueriesSupported) {
        var h, i, j, k = a.document,
            l = k.documentElement,
            m = [],
            n = [],
            o = [],
            p = {},
            q = 30,
            r = k.getElementsByTagName("head")[0] || l,
            s = k.getElementsByTagName("base")[0],
            t = r.getElementsByTagName("link"),
            u = function () {
                var a, b = k.createElement("div"),
                    c = k.body,
                    d = l.style.fontSize,
                    e = c && c.style.fontSize,
                    f = !1;
                return b.style.cssText = "position:absolute;font-size:1em;width:1em", c || (c = f = k.createElement("body"), c.style.background = "none"), l.style.fontSize = "100%", c.style.fontSize = "100%", c.appendChild(b), f && l.insertBefore(c, l.firstChild), a = b.offsetWidth, f ? l.removeChild(c) : c.removeChild(b), l.style.fontSize = d, e && (c.style.fontSize = e), a = j = parseFloat(a)
            },
            v = function (b) {
                var c = "clientWidth",
                    d = l[c],
                    e = "CSS1Compat" === k.compatMode && d || k.body[c] || d,
                    f = {},
                    g = t[t.length - 1],
                    p = (new Date).getTime();
                if (b && h && q > p - h) return a.clearTimeout(i), i = a.setTimeout(v, q), void 0;
                h = p;
                for (var s in m)
                    if (m.hasOwnProperty(s)) {
                        var w = m[s],
                            x = w.minw,
                            y = w.maxw,
                            z = null === x,
                            A = null === y,
                            B = "em";
                        x && (x = parseFloat(x) * (x.indexOf(B) > -1 ? j || u() : 1)), y && (y = parseFloat(y) * (y.indexOf(B) > -1 ? j || u() : 1)), w.hasquery && (z && A || !(z || e >= x) || !(A || y >= e)) || (f[w.media] || (f[w.media] = []), f[w.media].push(n[w.rules]))
                    } for (var C in o) o.hasOwnProperty(C) && o[C] && o[C].parentNode === r && r.removeChild(o[C]);
                o.length = 0;
                for (var D in f)
                    if (f.hasOwnProperty(D)) {
                        var E = k.createElement("style"),
                            F = f[D].join("\n");
                        E.type = "text/css", E.media = D, r.insertBefore(E, g.nextSibling), E.styleSheet ? E.styleSheet.cssText = F : E.appendChild(k.createTextNode(F)), o.push(E)
                    }
            },
            w = function (a, b, d) {
                var e = a.replace(c.regex.comments, "").replace(c.regex.keyframes, "").match(c.regex.media),
                    f = e && e.length || 0;
                b = b.substring(0, b.lastIndexOf("/"));
                var h = function (a) {
                        return a.replace(c.regex.urls, "$1" + b + "$2$3")
                    },
                    i = !f && d;
                b.length && (b += "/"), i && (f = 1);
                for (var j = 0; f > j; j++) {
                    var k, l, o, p;
                    i ? (k = d, n.push(h(a))) : (k = e[j].match(c.regex.findStyles) && RegExp.$1, n.push(RegExp.$2 && h(RegExp.$2))), o = k.split(","), p = o.length;
                    for (var q = 0; p > q; q++) l = o[q], g(l) || m.push({
                        media: l.split("(")[0].match(c.regex.only) && RegExp.$2 || "all",
                        rules: n.length - 1,
                        hasquery: l.indexOf("(") > -1,
                        minw: l.match(c.regex.minw) && parseFloat(RegExp.$1) + (RegExp.$2 || ""),
                        maxw: l.match(c.regex.maxw) && parseFloat(RegExp.$1) + (RegExp.$2 || "")
                    })
                }
                v()
            },
            x = function () {
                if (d.length) {
                    var b = d.shift();
                    f(b.href, function (c) {
                        w(c, b.href, b.media), p[b.href] = !0, a.setTimeout(function () {
                            x()
                        }, 0)
                    })
                }
            },
            y = function () {
                for (var b = 0; b < t.length; b++) {
                    var c = t[b],
                        e = c.href,
                        f = c.media,
                        g = c.rel && "stylesheet" === c.rel.toLowerCase();
                    e && g && !p[e] && (c.styleSheet && c.styleSheet.rawCssText ? (w(c.styleSheet.rawCssText, e, f), p[e] = !0) : (!/^([a-zA-Z:]*\/\/)/.test(e) && !s || e.replace(RegExp.$1, "").split("/")[0] === a.location.host) && ("//" === e.substring(0, 2) && (e = a.location.protocol + e), d.push({
                        href: e,
                        media: f
                    })))
                }
                x()
            };
        y(), c.update = y, c.getEmValue = u, a.addEventListener ? a.addEventListener("resize", b, !1) : a.attachEvent && a.attachEvent("onresize", b)
    }
}(this);