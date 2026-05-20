/*
 * zt_policy.cpp — Zero Trust ABAC Policy Engine
 * ================================================
 * Attribute-Based Access Control (ABAC) engine implementing a deny-overrides
 * combining algorithm for Zero Trust environments.  Policies are loaded from
 * a plain-text .policy file; access requests are read from a .request file or
 * from stdin.  Every decision is written to an audit log.
 *
 * Policy file format (one block per policy, blank-line separated):
 *
 *   policy: <name>
 *   subject.role: <value>          # optional; omit = wildcard
 *   subject.clearance: <value>     # PUBLIC | CONFIDENTIAL | SECRET | TS
 *   subject.device_trust: <value>  # managed | unmanaged | unknown
 *   subject.location: <value>      # internal | external | remote
 *   resource.classification: <v>   # UNCLASSIFIED | CUI | SECRET | TS
 *   resource.owner: <value>
 *   resource.type: <value>         # file | api | database | service
 *   env.time_start: HH:MM          # optional window start (24h)
 *   env.time_end:   HH:MM          # optional window end   (24h)
 *   env.network_zone: <value>      # trusted | guest | dmz | untrusted
 *   action: <read|write|execute|admin|*>
 *   effect: ALLOW | DENY
 *
 * Request file format:
 *   subject.role: analyst
 *   subject.clearance: SECRET
 *   subject.device_trust: managed
 *   subject.location: internal
 *   resource.classification: CUI
 *   resource.owner: hq
 *   resource.type: file
 *   env.time: 14:30
 *   env.network_zone: trusted
 *   action: read
 *
 * Usage:
 *   zt-policy --policy <policy_file> --request <request_file>
 *   zt-policy --policy <policy_file>          (read request from stdin)
 *   zt-policy --builtin                       (load built-in policies only)
 *
 * Build:
 *   g++ -std=c++17 -O2 zt_policy.cpp -o zt-policy
 *
 * WASM:
 *   em++ -std=c++17 -O2 zt_policy.cpp -o zt_policy.js \
 *        -s EXPORTED_FUNCTIONS='["_main"]'
 *
 * Author: Jessica Rojas — Systems & Zero-Trust Portfolio
 * License: MIT
 */

#include <algorithm>
#include <cassert>
#include <chrono>
#include <ctime>
#include <fstream>
#include <iomanip>
#include <iostream>
#include <map>
#include <optional>
#include <sstream>
#include <stdexcept>
#include <string>
#include <string_view>
#include <vector>

// ── Types ─────────────────────────────────────────────────────────────────────

using Attrs = std::map<std::string, std::string>;

enum class Effect { ALLOW, DENY, NOT_APPLICABLE };

struct Policy {
    std::string name;
    Attrs       subject_match;   // key/value conditions; missing = wildcard
    Attrs       resource_match;
    Attrs       env_match;
    std::string action;          // "*" = any
    Effect      effect = Effect::NOT_APPLICABLE;
};

struct Request {
    Attrs       subject;
    Attrs       resource;
    Attrs       env;
    std::string action;
};

struct Decision {
    Effect      effect = Effect::DENY;
    std::string reason;
    std::string matched_policy;
};

// ── Clearance ordering ───────────────────────────────────────────────────────

static int clearance_level(std::string_view c)
{
    if (c == "PUBLIC")       return 0;
    if (c == "CONFIDENTIAL") return 1;
    if (c == "SECRET")       return 2;
    if (c == "TS")           return 3;
    return -1;
}

static int classification_level(std::string_view c)
{
    if (c == "UNCLASSIFIED") return 0;
    if (c == "CUI")          return 1;
    if (c == "SECRET")       return 2;
    if (c == "TS")           return 3;
    return -1;
}

// ── String helpers ────────────────────────────────────────────────────────────

static std::string trim(std::string s)
{
    size_t a = s.find_first_not_of(" \t\r\n");
    if (a == std::string::npos) return {};
    size_t b = s.find_last_not_of(" \t\r\n");
    return s.substr(a, b - a + 1);
}

static std::pair<std::string, std::string> split_kv(const std::string& line)
{
    auto pos = line.find(':');
    if (pos == std::string::npos) return {trim(line), {}};
    return {trim(line.substr(0, pos)), trim(line.substr(pos + 1))};
}

static std::string now_iso()
{
    auto t  = std::chrono::system_clock::to_time_t(std::chrono::system_clock::now());
    std::tm tm_buf{};
#ifdef _WIN32
    localtime_s(&tm_buf, &t);
#else
    localtime_r(&t, &tm_buf);
#endif
    std::ostringstream oss;
    oss << std::put_time(&tm_buf, "%Y-%m-%dT%H:%M:%S");
    return oss.str();
}

// HH:MM as minutes-since-midnight
static int hhmm_to_min(std::string_view s)
{
    if (s.size() != 5 || s[2] != ':') return -1;
    int h = std::stoi(std::string(s.substr(0, 2)));
    int m = std::stoi(std::string(s.substr(3, 2)));
    return h * 60 + m;
}

// ── Attribute matching ────────────────────────────────────────────────────────

// Returns true if the policy condition on `key` is satisfied by `attrs`.
static bool attr_matches(const Attrs& match_conditions,
                         const Attrs& attrs,
                         const std::string& key)
{
    auto it = match_conditions.find(key);
    if (it == match_conditions.end()) return true;   // wildcard
    auto jt = attrs.find(key);
    if (jt == attrs.end()) return false;
    return it->second == jt->second;
}

static bool time_in_window(const Policy& p, const Attrs& env)
{
    auto ts_it = p.env_match.find("time_start");
    auto te_it = p.env_match.find("time_end");
    if (ts_it == p.env_match.end() && te_it == p.env_match.end()) return true;

    auto cur_it = env.find("time");
    if (cur_it == env.end()) return false;

    int cur  = hhmm_to_min(cur_it->second);
    int tmin = (ts_it != p.env_match.end()) ? hhmm_to_min(ts_it->second) : 0;
    int tmax = (te_it != p.env_match.end()) ? hhmm_to_min(te_it->second) : 1439;

    return cur >= 0 && cur >= tmin && cur <= tmax;
}

// ── Policy matching ───────────────────────────────────────────────────────────

static bool policy_applies(const Policy& p, const Request& req)
{
    // Action check
    if (p.action != "*" && p.action != req.action) return false;

    // Subject attributes
    for (auto& [k, v] : p.subject_match) {
        auto it = req.subject.find(k);
        if (it == req.subject.end() || it->second != v) return false;
    }

    // Resource attributes (allow wildcard value "*")
    for (auto& [k, v] : p.resource_match) {
        if (v == "*") continue;
        auto it = req.resource.find(k);
        if (it == req.resource.end() || it->second != v) return false;
    }

    // Network zone
    if (!attr_matches(p.env_match, req.env, "network_zone")) return false;

    // Time window
    if (!time_in_window(p, req.env)) return false;

    return true;
}

// ── Evaluation (deny-overrides) ───────────────────────────────────────────────

static Decision evaluate(const std::vector<Policy>& policies, const Request& req)
{
    // Mandatory clearance/classification check (always enforced)
    {
        auto subj_it = req.subject.find("clearance");
        auto res_it  = req.resource.find("classification");
        if (subj_it != req.subject.end() && res_it != req.resource.end()) {
            int cl = clearance_level(subj_it->second);
            int rl = classification_level(res_it->second);
            if (cl < rl)
                return {Effect::DENY,
                        "Clearance " + subj_it->second +
                        " insufficient for classification " + res_it->second,
                        "<mandatory-access-control>"};
        }
    }

    Decision result{Effect::DENY,
                    "No matching ALLOW policy (default-deny)",
                    "<default>"};
    bool found_allow = false;

    for (const auto& p : policies) {
        if (!policy_applies(p, req)) continue;

        if (p.effect == Effect::DENY)
            return {Effect::DENY, "Explicit DENY by policy: " + p.name, p.name};

        if (p.effect == Effect::ALLOW) {
            found_allow = true;
            result = {Effect::ALLOW, "Allowed by policy: " + p.name, p.name};
        }
    }

    if (!found_allow) return result;
    return result;
}

// ── Built-in policies ─────────────────────────────────────────────────────────

static std::vector<Policy> builtin_policies()
{
    std::vector<Policy> v;

    // P1 — CMMC L2: only cleared users on managed devices
    {
        Policy p;
        p.name = "CMMC-L2-managed-device-only";
        p.resource_match["classification"] = "CUI";
        p.subject_match["device_trust"]    = "unmanaged";
        p.action = "*";
        p.effect = Effect::DENY;
        v.push_back(p);
    }
    {
        Policy p;
        p.name = "CMMC-L2-managed-device-only-unknown";
        p.resource_match["classification"] = "CUI";
        p.subject_match["device_trust"]    = "unknown";
        p.action = "*";
        p.effect = Effect::DENY;
        v.push_back(p);
    }

    // P2 — CUI//SP-CTI requires SECRET or higher clearance
    {
        Policy p;
        p.name = "CUI-SP-CTI-requires-SECRET";
        p.resource_match["classification"] = "CUI";
        p.resource_match["type"]           = "file";
        p.subject_match["clearance"]       = "PUBLIC";
        p.action = "*";
        p.effect = Effect::DENY;
        v.push_back(p);
    }
    {
        Policy p;
        p.name = "CUI-SP-CTI-requires-SECRET-CONFIDENTIAL";
        p.resource_match["classification"] = "CUI";
        p.resource_match["type"]           = "file";
        p.subject_match["clearance"]       = "CONFIDENTIAL";
        p.action = "*";
        p.effect = Effect::DENY;
        v.push_back(p);
    }

    // P3 — After-hours restriction: no writes 22:00-06:00
    {
        Policy p;
        p.name = "after-hours-write-deny";
        p.env_match["time_start"] = "22:00";
        p.env_match["time_end"]   = "23:59";
        p.action = "write";
        p.effect = Effect::DENY;
        v.push_back(p);
    }
    {
        Policy p;
        p.name = "early-morning-write-deny";
        p.env_match["time_start"] = "00:00";
        p.env_match["time_end"]   = "06:00";
        p.action = "write";
        p.effect = Effect::DENY;
        v.push_back(p);
    }

    // P4 — Guest network: deny all
    {
        Policy p;
        p.name = "guest-network-deny-all";
        p.env_match["network_zone"] = "guest";
        p.action = "*";
        p.effect = Effect::DENY;
        v.push_back(p);
    }

    // P5 — Cleared internal managed user: allow read of CUI
    {
        Policy p;
        p.name = "cleared-internal-read-CUI";
        p.subject_match["clearance"]    = "SECRET";
        p.subject_match["device_trust"] = "managed";
        p.subject_match["location"]     = "internal";
        p.resource_match["classification"] = "CUI";
        p.env_match["network_zone"]     = "trusted";
        p.action = "read";
        p.effect = Effect::ALLOW;
        v.push_back(p);
    }
    {
        Policy p;
        p.name = "TS-cleared-internal-read-CUI";
        p.subject_match["clearance"]    = "TS";
        p.subject_match["device_trust"] = "managed";
        p.subject_match["location"]     = "internal";
        p.resource_match["classification"] = "CUI";
        p.env_match["network_zone"]     = "trusted";
        p.action = "read";
        p.effect = Effect::ALLOW;
        v.push_back(p);
    }

    // P6 — Analysts can read unclassified from trusted zone
    {
        Policy p;
        p.name = "analyst-read-unclassified";
        p.subject_match["role"]         = "analyst";
        p.resource_match["classification"] = "UNCLASSIFIED";
        p.env_match["network_zone"]     = "trusted";
        p.action = "read";
        p.effect = Effect::ALLOW;
        v.push_back(p);
    }

    return v;
}

// ── Parse policy file ─────────────────────────────────────────────────────────

static std::vector<Policy> load_policies(const std::string& path)
{
    std::ifstream f(path);
    if (!f) throw std::runtime_error("Cannot open policy file: " + path);

    std::vector<Policy> policies;
    Policy cur;
    bool   in_policy = false;

    auto finalize = [&]() {
        if (in_policy && !cur.name.empty()) {
            policies.push_back(cur);
            cur = {};
        }
        in_policy = false;
    };

    for (std::string line; std::getline(f, line); ) {
        line = trim(line);
        if (line.empty() || line[0] == '#') { finalize(); continue; }

        auto [k, val] = split_kv(line);

        if (k == "policy") {
            finalize();
            cur.name = val;
            in_policy = true;
        } else if (k.substr(0, 8) == "subject.") {
            cur.subject_match[k.substr(8)] = val;
        } else if (k.substr(0, 9) == "resource.") {
            cur.resource_match[k.substr(9)] = val;
        } else if (k.substr(0, 4) == "env.") {
            cur.env_match[k.substr(4)] = val;
        } else if (k == "action") {
            cur.action = val;
        } else if (k == "effect") {
            cur.effect = (val == "ALLOW") ? Effect::ALLOW : Effect::DENY;
        }
    }
    finalize();
    return policies;
}

// ── Parse request ─────────────────────────────────────────────────────────────

static Request parse_request(std::istream& in)
{
    Request req;
    for (std::string line; std::getline(in, line); ) {
        line = trim(line);
        if (line.empty() || line[0] == '#') continue;
        auto [k, val] = split_kv(line);
        if (k.substr(0, 8) == "subject.") {
            req.subject[k.substr(8)] = val;
        } else if (k.substr(0, 9) == "resource.") {
            req.resource[k.substr(9)] = val;
        } else if (k.substr(0, 4) == "env.") {
            req.env[k.substr(4)] = val;
        } else if (k == "action") {
            req.action = val;
        }
    }
    return req;
}

// ── Audit log ─────────────────────────────────────────────────────────────────

static void audit(const Request& req, const Decision& d)
{
    std::string subj_role  = req.subject.count("role")      ? req.subject.at("role")      : "-";
    std::string subj_clear = req.subject.count("clearance") ? req.subject.at("clearance") : "-";
    std::string res_cls    = req.resource.count("classification")
                             ? req.resource.at("classification") : "-";
    std::string res_type   = req.resource.count("type") ? req.resource.at("type") : "-";
    std::string decision   = (d.effect == Effect::ALLOW) ? "ALLOW" : "DENY";

    std::cerr << "[AUDIT] "
              << now_iso()              << " | "
              << "subject.role="        << subj_role  << " "
              << "subject.clearance="   << subj_clear << " | "
              << "resource.class="      << res_cls    << " "
              << "resource.type="       << res_type   << " | "
              << "action="              << req.action  << " | "
              << decision               << " | "
              << "policy="              << d.matched_policy << " | "
              << d.reason               << "\n";
}

// ── main ──────────────────────────────────────────────────────────────────────

static void usage(const char* prog)
{
    std::cerr << "Usage:\n"
              << "  " << prog << " --policy <file> [--request <file>]\n"
              << "  " << prog << " --builtin [--request <file>]\n\n"
              << "Options:\n"
              << "  --policy  <file>   Load policies from file\n"
              << "  --builtin          Use built-in Zero Trust policies\n"
              << "  --request <file>   Evaluate request from file (default: stdin)\n";
}

int main(int argc, char* argv[])
{
    std::string policy_file;
    std::string request_file;
    bool        use_builtin = false;

    for (int i = 1; i < argc; ++i) {
        std::string arg = argv[i];
        if (arg == "--policy"  && i + 1 < argc) policy_file  = argv[++i];
        else if (arg == "--request" && i + 1 < argc) request_file = argv[++i];
        else if (arg == "--builtin") use_builtin = true;
        else { usage(argv[0]); return 1; }
    }

    if (!use_builtin && policy_file.empty()) { usage(argv[0]); return 1; }

    try {
        std::vector<Policy> policies;
        if (use_builtin)         policies = builtin_policies();
        if (!policy_file.empty()) {
            auto loaded = load_policies(policy_file);
            policies.insert(policies.end(), loaded.begin(), loaded.end());
        }

        std::cout << "Loaded " << policies.size() << " polic"
                  << (policies.size() == 1 ? "y" : "ies") << ".\n";

        // Evaluate request
        Request req;
        if (!request_file.empty()) {
            std::ifstream rf(request_file);
            if (!rf) throw std::runtime_error("Cannot open request file: " + request_file);
            req = parse_request(rf);
        } else {
            std::cout << "Enter request (key: value lines, blank line to evaluate):\n";
            req = parse_request(std::cin);
        }

        auto decision = evaluate(policies, req);
        audit(req, decision);

        std::string outcome = (decision.effect == Effect::ALLOW) ? "ALLOW" : "DENY";
        std::cout << "Decision : " << outcome          << "\n"
                  << "Reason   : " << decision.reason  << "\n"
                  << "Policy   : " << decision.matched_policy << "\n";

        return (decision.effect == Effect::ALLOW) ? 0 : 1;

    } catch (const std::exception& ex) {
        std::cerr << "Error: " << ex.what() << "\n";
        return 2;
    }
}
