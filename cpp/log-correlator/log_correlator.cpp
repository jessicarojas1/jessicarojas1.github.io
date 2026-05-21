/*
 * log_correlator.cpp — High-Performance Multi-Threaded Syslog Correlator
 * ========================================================================
 * Parses syslog (RFC 3164 / RFC 5424) and Windows Event Log CSV files,
 * then correlates events across a sliding time window to detect adversarial
 * patterns mapped to MITRE ATT&CK.
 *
 * Detection rules:
 *   T1110  Brute Force       — >5 failed logins from same IP in window
 *   T1078  Valid Accounts    — failed login followed by successful login (same IP)
 *   T1021  Remote Services   — same user authenticated to >3 distinct hosts in window
 *   T1074  Data Staged       — large file write to /tmp|/var/tmp then outbound conn
 *
 * Supported formats:
 *   Syslog RFC 3164  <PRI>MMM DD HH:MM:SS host proc[pid]: msg
 *   Syslog RFC 5424  <PRI>VERSION TIMESTAMP HOSTNAME APP-NAME PROCID MSGID SD MSG
 *   Windows CSV      TimeCreated,Id,LevelDisplayName,Message,MachineName,...
 *
 * Usage:
 *   log-correlator [--threads N] [--window SECONDS] <logfile> [<logfile>...]
 *   log-correlator --help
 *
 * Build:
 *   g++ -std=c++17 -O2 -pthread log_correlator.cpp -o log-correlator
 *
 * Author: Jessica Rojas — Systems & Zero-Trust Portfolio
 * License: MIT
 */

#include <algorithm>
#include <atomic>
#include <chrono>
#include <condition_variable>
#include <ctime>
#include <fstream>
#include <functional>
#include <iomanip>
#include <iostream>
#include <map>
#include <mutex>
#include <optional>
#include <queue>
#include <regex>
#include <set>
#include <sstream>
#include <stdexcept>
#include <string>
#include <thread>
#include <vector>

using SteadyClock = std::chrono::steady_clock;
using TimePoint   = std::chrono::system_clock::time_point;
using Seconds     = std::chrono::seconds;

// ── Event model ───────────────────────────────────────────────────────────────

enum class EventType {
    UNKNOWN,
    LOGIN_FAIL,
    LOGIN_SUCCESS,
    FILE_WRITE_LARGE,
    NETWORK_CONNECT,
    PROCESS_EXEC,
    CREDENTIAL_ACCESS, // T1003: shadow/lsass/hashdump/mimikatz indicators
    POWERSHELL_EXEC,   // T1059.001: obfuscated/suspicious PowerShell
    ACCOUNT_CREATE,    // T1136: new OS account creation
};

struct LogEvent {
    TimePoint   timestamp;
    std::string source_file;
    std::string host;
    std::string ip;
    std::string user;
    std::string dest_host;
    std::string process;
    std::string message;
    EventType   type = EventType::UNKNOWN;
    long long   file_size = 0;   // bytes; used for FILE_WRITE_LARGE
};

struct Alert {
    std::string          timestamp;
    std::string          attack_id;
    std::string          technique;
    int                  severity;  // 1=low … 4=critical
    std::string          description;
    std::vector<std::string> evidence;  // relevant event messages
};

// ── Globals protected by mutex ────────────────────────────────────────────────

static std::mutex               g_events_mutex;
static std::vector<LogEvent>    g_events;

static std::mutex               g_alerts_mutex;
static std::vector<Alert>       g_alerts;

// ── Thread pool ───────────────────────────────────────────────────────────────

class ThreadPool {
public:
    explicit ThreadPool(size_t n)
    {
        for (size_t i = 0; i < n; ++i)
            workers_.emplace_back([this]{ worker(); });
    }
    ~ThreadPool()
    {
        {
            std::unique_lock<std::mutex> lk(mtx_);
            stop_ = true;
        }
        cv_.notify_all();
        for (auto& t : workers_) t.join();
    }

    void enqueue(std::function<void()> task)
    {
        {
            std::unique_lock<std::mutex> lk(mtx_);
            queue_.push(std::move(task));
        }
        cv_.notify_one();
    }

    void wait_all()
    {
        // Spin until the queue is drained and all workers are idle
        while (true) {
            {
                std::unique_lock<std::mutex> lk(mtx_);
                if (queue_.empty() && active_ == 0) return;
            }
            std::this_thread::sleep_for(std::chrono::milliseconds(10));
        }
    }

private:
    void worker()
    {
        while (true) {
            std::function<void()> task;
            {
                std::unique_lock<std::mutex> lk(mtx_);
                cv_.wait(lk, [this]{ return stop_ || !queue_.empty(); });
                if (stop_ && queue_.empty()) return;
                task = std::move(queue_.front());
                queue_.pop();
                ++active_;
            }
            task();
            {
                std::unique_lock<std::mutex> lk(mtx_);
                --active_;
            }
        }
    }

    std::vector<std::thread>          workers_;
    std::queue<std::function<void()>> queue_;
    std::mutex                        mtx_;
    std::condition_variable           cv_;
    std::atomic<int>                  active_{0};
    bool                              stop_ = false;
};

// ── Timestamp parsing ─────────────────────────────────────────────────────────

// Parse RFC 3164 date "MMM DD HH:MM:SS" relative to current year
static std::optional<TimePoint> parse_rfc3164_ts(const std::string& s)
{
    static const char* months[] = {
        "Jan","Feb","Mar","Apr","May","Jun",
        "Jul","Aug","Sep","Oct","Nov","Dec"
    };
    std::istringstream iss(s);
    std::string mon; int day, h, m, sec;
    char colon;
    if (!(iss >> mon >> day >> h >> colon >> m >> colon >> sec)) return {};

    int mo = -1;
    for (int i = 0; i < 12; ++i)
        if (mon == months[i]) { mo = i; break; }
    if (mo < 0) return {};

    std::time_t now_t = std::chrono::system_clock::to_time_t(
        std::chrono::system_clock::now());
    std::tm tm_now{};
#ifdef _WIN32
    localtime_s(&tm_now, &now_t);
#else
    localtime_r(&now_t, &tm_now);
#endif
    std::tm tm{};
    tm.tm_year  = tm_now.tm_year;
    tm.tm_mon   = mo;
    tm.tm_mday  = day;
    tm.tm_hour  = h;
    tm.tm_min   = m;
    tm.tm_sec   = sec;
    tm.tm_isdst = -1;
    std::time_t t = std::mktime(&tm);
    if (t == -1) return {};
    return std::chrono::system_clock::from_time_t(t);
}

// Parse RFC 5424 timestamp "2024-03-14T12:34:56Z" or "...+HH:MM"
static std::optional<TimePoint> parse_rfc5424_ts(const std::string& s)
{
    if (s.size() < 19) return {};
    std::tm tm{};
    std::istringstream iss(s);
    iss >> std::get_time(&tm, "%Y-%m-%dT%H:%M:%S");
    if (iss.fail()) return {};
    tm.tm_isdst = -1;
    std::time_t t = std::mktime(&tm);
    if (t == -1) return {};
    return std::chrono::system_clock::from_time_t(t);
}

// Parse Windows CSV timestamp "2024-03-14 12:34:56"
static std::optional<TimePoint> parse_win_ts(const std::string& s)
{
    std::tm tm{};
    std::istringstream iss(s);
    iss >> std::get_time(&tm, "%Y-%m-%d %H:%M:%S");
    if (iss.fail()) return {};
    tm.tm_isdst = -1;
    std::time_t t = std::mktime(&tm);
    if (t == -1) return {};
    return std::chrono::system_clock::from_time_t(t);
}

static std::string tp_to_string(const TimePoint& tp)
{
    std::time_t t = std::chrono::system_clock::to_time_t(tp);
    std::tm tm{};
#ifdef _WIN32
    localtime_s(&tm, &t);
#else
    localtime_r(&t, &tm);
#endif
    std::ostringstream oss;
    oss << std::put_time(&tm, "%Y-%m-%dT%H:%M:%S");
    return oss.str();
}

// ── Event classification ──────────────────────────────────────────────────────

static void classify_event(LogEvent& ev)
{
    const std::string& msg = ev.message;
    auto ci = [](const std::string& haystack, const std::string& needle) {
        std::string h = haystack, n = needle;
        std::transform(h.begin(), h.end(), h.begin(), ::tolower);
        std::transform(n.begin(), n.end(), n.begin(), ::tolower);
        return h.find(n) != std::string::npos;
    };

    if (ci(msg, "failed password") || ci(msg, "authentication failure") ||
        ci(msg, "invalid user")    || ci(msg, "failed login")           ||
        ci(msg, "logon failure")   || ci(msg, "audit failure"))
    {
        ev.type = EventType::LOGIN_FAIL;

        // Extract IP if present
        static const std::regex ip_re(R"(\b(\d{1,3}(?:\.\d{1,3}){3})\b)");
        std::smatch m;
        if (std::regex_search(msg, m, ip_re)) ev.ip = m[1];

        // Extract username
        static const std::regex user_re(
            R"((?:user|for|invalid user)\s+(\S+))",
            std::regex_constants::icase);
        if (std::regex_search(msg, m, user_re)) ev.user = m[1];

    } else if (ci(msg, "accepted password") || ci(msg, "session opened") ||
               ci(msg, "successful logon")  || ci(msg, "logon type"))
    {
        ev.type = EventType::LOGIN_SUCCESS;

        static const std::regex ip_re(R"(\b(\d{1,3}(?:\.\d{1,3}){3})\b)");
        std::smatch m;
        if (std::regex_search(msg, m, ip_re)) ev.ip = m[1];

        static const std::regex user_re(
            R"((?:for|user)\s+(\S+))",
            std::regex_constants::icase);
        if (std::regex_search(msg, m, user_re)) ev.user = m[1];

    } else if (ci(msg, "write") && (ci(msg, "/tmp/") || ci(msg, "/var/tmp/"))) {
        ev.type = EventType::FILE_WRITE_LARGE;

        static const std::regex sz_re(R"((\d+)\s*bytes?)");
        std::smatch m;
        if (std::regex_search(msg, m, sz_re))
            ev.file_size = std::stoll(m[1].str());

    } else if (ci(msg, "connect") || ci(msg, "outbound") || ci(msg, "established")) {
        ev.type = EventType::NETWORK_CONNECT;

        static const std::regex ip_re(R"(\b(\d{1,3}(?:\.\d{1,3}){3})\b)");
        std::smatch m;
        if (std::regex_search(msg, m, ip_re)) ev.ip = m[1];

    } else if (ci(msg, "/etc/shadow") || ci(msg, "/etc/passwd") ||
               ci(msg, "lsass")       || ci(msg, "hashdump")   ||
               ci(msg, "secretsdump") || ci(msg, "sekurlsa")   ||
               ci(msg, "mimikatz")    || ci(msg, "ntds.dit")   ||
               ci(msg, "sam database")) {
        ev.type = EventType::CREDENTIAL_ACCESS;

        static const std::regex user_re(R"((?:user|for)\s+(\S+))",
                                        std::regex_constants::icase);
        std::smatch m;
        if (std::regex_search(msg, m, user_re)) ev.user = m[1];

    } else if (ci(msg, "powershell") && (
               ci(msg, "-encodedcommand") || ci(msg, "invoke-expression") ||
               ci(msg, "downloadstring")  || ci(msg, "downloadfile")      ||
               ci(msg, "-noprofile")      || ci(msg, "bypass")            ||
               ci(msg, "iex ("))) {
        ev.type = EventType::POWERSHELL_EXEC;

    } else if (ci(msg, "useradd")          || ci(msg, "adduser")          ||
               ci(msg, "new user account") || ci(msg, "account was created") ||
               (ci(msg, "net user") && ci(msg, "/add"))) {
        ev.type = EventType::ACCOUNT_CREATE;

        static const std::regex user_re(R"((?:user|account|username)\s+['\"]?(\S+?)['\"]?(?:\s|$))",
                                        std::regex_constants::icase);
        std::smatch m;
        if (std::regex_search(msg, m, user_re)) ev.user = m[1];
    }
}

// ── Log parsing ───────────────────────────────────────────────────────────────

static std::vector<LogEvent> parse_syslog(const std::string& path)
{
    std::ifstream f(path);
    if (!f) { std::cerr << "Warning: cannot open " << path << "\n"; return {}; }

    std::vector<LogEvent> events;

    // RFC 5424 pattern: <PRI>VER TIMESTAMP HOSTNAME APP PROCID MSGID SD MSG
    static const std::regex re5424(
        R"(<\d+>\d\s+(\S+)\s+(\S+)\s+(\S+)\s+\S+\s+\S+\s+\S+\s+(.*))");
    // RFC 3164 pattern: <PRI>MMM DD HH:MM:SS HOST PROC[PID]: MSG
    static const std::regex re3164(
        R"(<\d+>(\w{3}\s+\d+\s+\d+:\d+:\d+)\s+(\S+)\s+\S+(?:\[\d+\])?:\s*(.*))");
    // Bare syslog (no PRI): MMM DD HH:MM:SS HOST PROC[PID]: MSG
    static const std::regex reBare(
        R"((\w{3}\s+\d+\s+\d+:\d+:\d+)\s+(\S+)\s+\S+(?:\[\d+\])?:\s*(.*))");

    for (std::string line; std::getline(f, line); ) {
        if (line.empty()) continue;
        LogEvent ev;
        ev.source_file = path;
        std::smatch m;

        if (std::regex_match(line, m, re5424)) {
            auto ts = parse_rfc5424_ts(m[1]);
            if (!ts) continue;
            ev.timestamp = *ts;
            ev.host      = m[2];
            ev.process   = m[3];
            ev.message   = m[4];
        } else if (std::regex_match(line, m, re3164)) {
            auto ts = parse_rfc3164_ts(m[1]);
            if (!ts) continue;
            ev.timestamp = *ts;
            ev.host      = m[2];
            ev.message   = m[3];
        } else if (std::regex_match(line, m, reBare)) {
            auto ts = parse_rfc3164_ts(m[1]);
            if (!ts) continue;
            ev.timestamp = *ts;
            ev.host      = m[2];
            ev.message   = m[3];
        } else {
            continue;  // Unrecognised line
        }

        classify_event(ev);
        events.push_back(std::move(ev));
    }
    return events;
}

static std::vector<LogEvent> parse_windows_csv(const std::string& path)
{
    std::ifstream f(path);
    if (!f) { std::cerr << "Warning: cannot open " << path << "\n"; return {}; }

    std::vector<LogEvent> events;
    std::string header_line;
    std::getline(f, header_line);  // skip header

    for (std::string line; std::getline(f, line); ) {
        // Minimal CSV: TimeCreated,Id,Level,Message,MachineName
        std::istringstream ss(line);
        std::string ts_str, id, level, message, machine;
        std::getline(ss, ts_str, ',');
        std::getline(ss, id,      ',');
        std::getline(ss, level,   ',');
        std::getline(ss, message, ',');
        std::getline(ss, machine, ',');

        auto ts = parse_win_ts(ts_str);
        if (!ts) continue;

        LogEvent ev;
        ev.source_file = path;
        ev.timestamp   = *ts;
        ev.host        = machine;
        ev.message     = message;

        classify_event(ev);
        events.push_back(std::move(ev));
    }
    return events;
}

static std::vector<LogEvent> parse_file(const std::string& path)
{
    // Detect format by extension or sniffing first line
    if (path.size() >= 4 &&
        path.substr(path.size() - 4) == ".csv")
        return parse_windows_csv(path);
    return parse_syslog(path);
}

// ── Correlation ───────────────────────────────────────────────────────────────

static void emit_alert(Alert a)
{
    std::unique_lock<std::mutex> lk(g_alerts_mutex);
    g_alerts.push_back(std::move(a));
}

static void correlate(const std::vector<LogEvent>& events, long long window_secs)
{
    using namespace std::chrono;

    // Sort by timestamp
    std::vector<const LogEvent*> sorted;
    sorted.reserve(events.size());
    for (auto& e : events) sorted.push_back(&e);
    std::sort(sorted.begin(), sorted.end(),
              [](const LogEvent* a, const LogEvent* b){
                  return a->timestamp < b->timestamp;
              });

    // Sliding window helpers
    auto in_window = [&](const TimePoint& anchor, const TimePoint& candidate) {
        auto delta = duration_cast<seconds>(candidate - anchor).count();
        return delta >= 0 && delta <= window_secs;
    };

    // T1110 — Brute Force: >5 LOGIN_FAIL from same IP in window
    {
        std::map<std::string, std::vector<const LogEvent*>> by_ip;
        for (auto* ev : sorted)
            if (ev->type == EventType::LOGIN_FAIL && !ev->ip.empty())
                by_ip[ev->ip].push_back(ev);

        for (auto& [ip, fails] : by_ip) {
            for (size_t i = 0; i < fails.size(); ) {
                size_t j = i;
                while (j < fails.size() &&
                       in_window(fails[i]->timestamp, fails[j]->timestamp))
                    ++j;
                if (j - i > 5) {
                    Alert a;
                    a.timestamp  = tp_to_string(fails[i]->timestamp);
                    a.attack_id  = "T1110";
                    a.technique  = "Brute Force";
                    a.severity   = 3;
                    a.description = "IP " + ip + " produced " +
                                    std::to_string(j - i) +
                                    " failed logins in " +
                                    std::to_string(window_secs) + "s window.";
                    for (size_t k = i; k < j && k < i + 3; ++k)
                        a.evidence.push_back(fails[k]->message.substr(0, 120));
                    emit_alert(std::move(a));
                }
                ++i;
            }
        }
    }

    // T1078 — Valid Accounts: LOGIN_FAIL followed by LOGIN_SUCCESS same IP
    {
        std::map<std::string, std::vector<const LogEvent*>> fails_by_ip;
        std::map<std::string, std::vector<const LogEvent*>> success_by_ip;

        for (auto* ev : sorted) {
            if (!ev->ip.empty()) {
                if (ev->type == EventType::LOGIN_FAIL)
                    fails_by_ip[ev->ip].push_back(ev);
                else if (ev->type == EventType::LOGIN_SUCCESS)
                    success_by_ip[ev->ip].push_back(ev);
            }
        }

        for (auto& [ip, succ_list] : success_by_ip) {
            auto it = fails_by_ip.find(ip);
            if (it == fails_by_ip.end()) continue;
            for (auto* s : succ_list) {
                for (auto* f : it->second) {
                    if (f->timestamp < s->timestamp &&
                        in_window(f->timestamp, s->timestamp))
                    {
                        Alert a;
                        a.timestamp  = tp_to_string(f->timestamp);
                        a.attack_id  = "T1078";
                        a.technique  = "Valid Accounts — credential brute-force success";
                        a.severity   = 4;
                        a.description = "IP " + ip +
                            " had failed login(s) then successful login within window.";
                        a.evidence.push_back(f->message.substr(0, 120));
                        a.evidence.push_back(s->message.substr(0, 120));
                        emit_alert(std::move(a));
                        goto next_success;  // one alert per success event
                    }
                }
                next_success:;
            }
        }
    }

    // T1021 — Remote Services: same user on >3 distinct hosts in window
    {
        std::map<std::string, std::vector<const LogEvent*>> by_user;
        for (auto* ev : sorted)
            if (ev->type == EventType::LOGIN_SUCCESS && !ev->user.empty())
                by_user[ev->user].push_back(ev);

        for (auto& [user, list] : by_user) {
            for (size_t i = 0; i < list.size(); ++i) {
                std::set<std::string> hosts;
                for (size_t j = i; j < list.size() &&
                     in_window(list[i]->timestamp, list[j]->timestamp); ++j)
                    if (!list[j]->host.empty()) hosts.insert(list[j]->host);

                if (hosts.size() > 3) {
                    Alert a;
                    a.timestamp  = tp_to_string(list[i]->timestamp);
                    a.attack_id  = "T1021";
                    a.technique  = "Remote Services — lateral movement";
                    a.severity   = 3;
                    a.description = "User " + user + " authenticated to " +
                        std::to_string(hosts.size()) +
                        " distinct hosts in window.";
                    for (auto& h : hosts) a.evidence.push_back("host: " + h);
                    emit_alert(std::move(a));
                    break;  // one alert per user
                }
            }
        }
    }

    // T1074 — Data Staged: large file write to /tmp then NETWORK_CONNECT
    {
        for (size_t i = 0; i < sorted.size(); ++i) {
            const auto* fw = sorted[i];
            if (fw->type != EventType::FILE_WRITE_LARGE) continue;
            if (fw->file_size < 1'000'000) continue;  // > 1 MB threshold

            for (size_t j = i + 1; j < sorted.size(); ++j) {
                const auto* nc = sorted[j];
                if (!in_window(fw->timestamp, nc->timestamp)) break;
                if (nc->type != EventType::NETWORK_CONNECT) continue;

                Alert a;
                a.timestamp  = tp_to_string(fw->timestamp);
                a.attack_id  = "T1074";
                a.technique  = "Data Staged";
                a.severity   = 3;
                a.description = "Large file write (" +
                    std::to_string(fw->file_size / 1024) +
                    " KB) to temp dir followed by network connection on host " +
                    fw->host + ".";
                a.evidence.push_back(fw->message.substr(0, 120));
                a.evidence.push_back(nc->message.substr(0, 120));
                emit_alert(std::move(a));
                break;
            }
        }
    }

    // T1003 — OS Credential Dumping: any access to credential stores
    // Each matching event generates a critical-severity alert immediately;
    // credential access indicators are always high-priority and should not
    // be gated behind a minimum occurrence count.
    {
        for (auto* ev : sorted) {
            if (ev->type != EventType::CREDENTIAL_ACCESS) continue;
            Alert a;
            a.timestamp   = tp_to_string(ev->timestamp);
            a.attack_id   = "T1003";
            a.technique   = "OS Credential Dumping";
            a.severity    = 4;  // Critical
            a.description = "Credential store access indicator on host " +
                ev->host + ": possible LSASS dump, /etc/shadow read, "
                "or credential harvesting tool.";
            a.evidence.push_back(ev->message.substr(0, 120));
            // Corroborate: look for network connection in the same window
            for (auto* nc : sorted) {
                if (nc == ev) continue;
                if (nc->type != EventType::NETWORK_CONNECT) continue;
                if (in_window(ev->timestamp, nc->timestamp) && nc->host == ev->host) {
                    a.severity = 4;
                    a.description += " Exfil candidate: outbound connection within window.";
                    a.evidence.push_back(nc->message.substr(0, 120));
                    break;
                }
            }
            emit_alert(std::move(a));
        }
    }

    // T1059.001 — PowerShell: >=2 suspicious PowerShell executions on same host
    // Single invocations are noisy (legitimate admin use); repeated use of
    // encoded commands, download cradles, or bypass flags is a reliable signal.
    {
        std::map<std::string, std::vector<const LogEvent*>> ps_by_host;
        for (auto* ev : sorted)
            if (ev->type == EventType::POWERSHELL_EXEC)
                ps_by_host[ev->host].push_back(ev);

        for (auto& [host, list] : ps_by_host) {
            for (size_t i = 0; i < list.size(); ) {
                size_t j = i;
                while (j < list.size() &&
                       in_window(list[i]->timestamp, list[j]->timestamp))
                    ++j;
                if (j - i >= 2) {
                    Alert a;
                    a.timestamp  = tp_to_string(list[i]->timestamp);
                    a.attack_id  = "T1059.001";
                    a.technique  = "Command and Scripting Interpreter: PowerShell";
                    a.severity   = 3;
                    a.description = std::to_string(j - i) +
                        " suspicious PowerShell executions (encoded cmd / download "
                        "cradle / execution-policy bypass) on host " + host +
                        " within " + std::to_string(window_secs) + "s window.";
                    for (size_t k = i; k < j && k < i + 3; ++k)
                        a.evidence.push_back(list[k]->message.substr(0, 120));
                    emit_alert(std::move(a));
                }
                i = j ? j : i + 1;
            }
        }
    }

    // T1136 — Create Account: new local account created; severity escalates if
    // the account logs in within the same window (persistence confirmation).
    {
        for (size_t i = 0; i < sorted.size(); ++i) {
            const auto* ca = sorted[i];
            if (ca->type != EventType::ACCOUNT_CREATE) continue;

            Alert a;
            a.timestamp  = tp_to_string(ca->timestamp);
            a.attack_id  = "T1136";
            a.technique  = "Create Account";
            a.severity   = 3;
            a.description = "New local account created on host " + ca->host + ".";
            if (!ca->user.empty())
                a.description += " Account name: " + ca->user + ".";
            a.evidence.push_back(ca->message.substr(0, 120));

            // Escalate: if the new account logs in within the window,
            // this is confirmed persistence — raise severity to critical.
            for (size_t j = i + 1; j < sorted.size(); ++j) {
                const auto* ls = sorted[j];
                if (!in_window(ca->timestamp, ls->timestamp)) break;
                if (ls->type != EventType::LOGIN_SUCCESS) continue;
                if (!ca->user.empty() && ls->user != ca->user) continue;

                using namespace std::chrono;
                long long delta = duration_cast<Seconds>(
                    ls->timestamp - ca->timestamp).count();
                a.severity = 4;
                a.description += " Account used " + std::to_string(delta) +
                    "s after creation — persistence confirmed.";
                a.evidence.push_back(ls->message.substr(0, 120));
                break;
            }
            emit_alert(std::move(a));
        }
    }
}

// ── Output ────────────────────────────────────────────────────────────────────

static void print_alerts()
{
    if (g_alerts.empty()) {
        std::cout << "No alerts generated.\n";
        return;
    }
    std::cout << "\n=== " << g_alerts.size() << " Alert(s) ===\n\n";
    for (size_t i = 0; i < g_alerts.size(); ++i) {
        const auto& a = g_alerts[i];
        std::cout << "Alert #" << (i + 1) << "\n"
                  << "  Timestamp : " << a.timestamp  << "\n"
                  << "  ATT&CK ID : " << a.attack_id  << "\n"
                  << "  Technique : " << a.technique  << "\n"
                  << "  Severity  : " << a.severity   << "/4\n"
                  << "  Details   : " << a.description << "\n"
                  << "  Evidence  :\n";
        for (auto& ev : a.evidence)
            std::cout << "    - " << ev << "\n";
        std::cout << "\n";
    }
}

// ── main ──────────────────────────────────────────────────────────────────────

static void usage(const char* prog)
{
    std::cerr << "Usage: " << prog
              << " [--threads N] [--window SECS] <logfile> [<logfile>...]\n"
              << "Defaults: --threads 4  --window 300\n";
}

int main(int argc, char* argv[])
{
    size_t     n_threads   = std::max(1u, std::thread::hardware_concurrency() / 2);
    long long  window_secs = 300;  // 5-minute default
    std::vector<std::string> files;

    for (int i = 1; i < argc; ++i) {
        std::string arg = argv[i];
        if (arg == "--threads" && i + 1 < argc)
            n_threads = static_cast<size_t>(std::stoul(argv[++i]));
        else if (arg == "--window" && i + 1 < argc)
            window_secs = std::stoll(argv[++i]);
        else if (arg == "--help")  { usage(argv[0]); return 0; }
        else                       files.push_back(arg);
    }

    if (files.empty()) { usage(argv[0]); return 1; }

    std::cout << "Threads: " << n_threads
              << "  Window: " << window_secs << "s  Files: " << files.size() << "\n";

    // Phase 1: parse files in parallel
    ThreadPool pool(n_threads);
    for (auto& path : files) {
        pool.enqueue([path]() {
            auto evs = parse_file(path);
            std::unique_lock<std::mutex> lk(g_events_mutex);
            g_events.insert(g_events.end(), evs.begin(), evs.end());
            std::cout << "Parsed " << evs.size() << " events from " << path << "\n";
        });
    }
    pool.wait_all();

    std::cout << "Total events: " << g_events.size() << "\n";

    // Phase 2: correlate (single-threaded over the merged set)
    correlate(g_events, window_secs);

    print_alerts();
    return 0;
}
