/*
 * mil1553_sim.cpp
 *
 * Description:
 *   MIL-STD-1553B avionics bus simulator with Bus Controller (BC),
 *   Remote Terminal (RT), and Bus Monitor (BM) roles. MIL-STD-1553B is a
 *   time-division-multiplexed serial data bus standard used extensively in
 *   military aircraft, missiles, and ground vehicles (100 kbps Manchester-II
 *   encoding, differential balanced, ±5V bipolar).
 *
 *   Word formats (all 20-bit over the wire = 3-bit sync + 16-bit data + 1-bit parity):
 *
 *   Command Word (CW) — BC to RT:
 *     [15:11] RT Address      (5 bits, 0-30; 31 = broadcast)
 *     [10]    T/R bit         (1=Transmit from RT, 0=Receive by RT)
 *     [9:5]   Subaddress/Mode (5 bits; 00000 and 11111 = mode code)
 *     [4:0]   Word Count / Mode Code (5 bits; 00000 = 32 words)
 *
 *   Status Word (SW) — RT to BC:
 *     [15:11] RT Address      (5 bits, mirrors CW)
 *     [10]    Message Error   (1 = protocol error detected)
 *     [9]     Instrumentation (reserved, normally 0)
 *     [8]     Service Request (1 = RT needs attention)
 *     [7:5]   Reserved
 *     [4]     Broadcast Received
 *     [3]     Busy            (1 = RT cannot comply)
 *     [2]     Subsystem Flag
 *     [1]     Dynamic Bus Acceptance
 *     [0]     Terminal Flag   (1 = RT fault)
 *
 *   Data Words (DW): 16-bit payload, content defined by subaddress mapping.
 *
 * Build:
 *   g++ -std=c++17 -O2 mil1553_sim.cpp -o mil1553-sim
 *
 * Usage:
 *   ./mil1553-sim           # run 10-message BC scenario and print bus transcript
 *   ./mil1553-sim --verbose # include per-bit word dumps
 *
 * Note:
 *   Requires native compilation. Not WASM-compatible due to hardware I/O
 *   dependencies (bus coupler interface stubs and real-time timing).
 *
 * References:
 *   MIL-STD-1553B, Notice 2 (Department of Defense Interface Standard)
 *   MIL-HDBK-1553A (Multiplex Applications Handbook)
 */

#include <algorithm>
#include <array>
#include <bitset>
#include <chrono>
#include <cstdint>
#include <iomanip>
#include <iostream>
#include <optional>
#include <sstream>
#include <stdexcept>
#include <string>
#include <vector>
#include <functional>
#include <random>

// ---------------------------------------------------------------------------
// MIL-STD-1553B word types and constants
// ---------------------------------------------------------------------------

static constexpr uint8_t RT_BROADCAST = 31;   // broadcast address

enum class BusID { A, B };

enum class WordType { COMMAND, STATUS, DATA };

// Parity: MIL-STD-1553B uses ODD parity on the 16 data bits
static bool odd_parity_ok(uint16_t word, bool parity_bit) {
    int pop = __builtin_popcount(word);
    // odd parity: total 1-bits including parity_bit must be odd
    return ((pop + (parity_bit ? 1 : 0)) & 1) == 1;
}

static bool compute_odd_parity(uint16_t word) {
    return (__builtin_popcount(word) & 1) == 0; // parity bit needed to make count odd
}

// ---------------------------------------------------------------------------
// Command Word
// ---------------------------------------------------------------------------
struct CommandWord {
    uint8_t  rt_address;    // 5-bit, 0-30 (31=broadcast)
    bool     tr_bit;        // true=Transmit (RT→BC), false=Receive (BC→RT)
    uint8_t  subaddress;    // 5-bit (0 and 31 are mode codes)
    uint8_t  word_count;    // 5-bit (0 = 32 words; 1-31 = 1-31 words)
    bool     is_mode_code;  // subaddress == 0 or 31

    uint16_t encode() const {
        uint16_t w = 0;
        w |= static_cast<uint16_t>((rt_address & 0x1Fu) << 11);
        w |= static_cast<uint16_t>((tr_bit ? 1u : 0u) << 10);
        w |= static_cast<uint16_t>((subaddress & 0x1Fu) << 5);
        w |= static_cast<uint16_t>(word_count & 0x1Fu);
        return w;
    }

    static CommandWord decode(uint16_t raw) {
        CommandWord cw{};
        cw.rt_address  = static_cast<uint8_t>((raw >> 11) & 0x1Fu);
        cw.tr_bit      = ((raw >> 10) & 0x1u) != 0;
        cw.subaddress  = static_cast<uint8_t>((raw >> 5) & 0x1Fu);
        cw.word_count  = static_cast<uint8_t>(raw & 0x1Fu);
        cw.is_mode_code = (cw.subaddress == 0 || cw.subaddress == 31);
        return cw;
    }

    int actual_word_count() const {
        return (word_count == 0) ? 32 : word_count;
    }
};

// ---------------------------------------------------------------------------
// Status Word
// ---------------------------------------------------------------------------
struct StatusWord {
    uint8_t rt_address;
    bool    message_error;
    bool    instrumentation;  // always 0 for standard RT
    bool    service_request;
    bool    broadcast_received;
    bool    busy;
    bool    subsystem_flag;
    bool    dynamic_bus_acceptance;
    bool    terminal_flag;

    uint16_t encode() const {
        uint16_t w = 0;
        w |= static_cast<uint16_t>((rt_address & 0x1Fu) << 11);
        w |= static_cast<uint16_t>(message_error         ? (1u << 10) : 0);
        w |= static_cast<uint16_t>(instrumentation       ? (1u <<  9) : 0);
        w |= static_cast<uint16_t>(service_request       ? (1u <<  8) : 0);
        // bits 7-5 reserved
        w |= static_cast<uint16_t>(broadcast_received    ? (1u <<  4) : 0);
        w |= static_cast<uint16_t>(busy                  ? (1u <<  3) : 0);
        w |= static_cast<uint16_t>(subsystem_flag        ? (1u <<  2) : 0);
        w |= static_cast<uint16_t>(dynamic_bus_acceptance? (1u <<  1) : 0);
        w |= static_cast<uint16_t>(terminal_flag         ? (1u <<  0) : 0);
        return w;
    }

    static StatusWord decode(uint16_t raw) {
        StatusWord sw{};
        sw.rt_address            = static_cast<uint8_t>((raw >> 11) & 0x1Fu);
        sw.message_error         = ((raw >> 10) & 1u) != 0;
        sw.instrumentation       = ((raw >>  9) & 1u) != 0;
        sw.service_request       = ((raw >>  8) & 1u) != 0;
        sw.broadcast_received    = ((raw >>  4) & 1u) != 0;
        sw.busy                  = ((raw >>  3) & 1u) != 0;
        sw.subsystem_flag        = ((raw >>  2) & 1u) != 0;
        sw.dynamic_bus_acceptance= ((raw >>  1) & 1u) != 0;
        sw.terminal_flag         = ((raw >>  0) & 1u) != 0;
        return sw;
    }
};

// ---------------------------------------------------------------------------
// Bus log entry — one line in the monitor transcript
// ---------------------------------------------------------------------------
struct BusTransaction {
    uint32_t    sequence;
    BusID       bus;
    WordType    word_type;
    uint16_t    raw_word;
    bool        parity_ok;
    std::string description;
};

// Global bus monitor log
static std::vector<BusTransaction> g_bus_log;
static uint32_t g_seq = 0;

static void log_word(BusID bus, WordType wtype, uint16_t raw,
                     const std::string& desc) {
    bool par_ok = odd_parity_ok(raw, compute_odd_parity(raw));
    g_bus_log.push_back({ ++g_seq, bus, wtype, raw, par_ok, desc });
}

// ---------------------------------------------------------------------------
// Remote Terminal definitions
// ---------------------------------------------------------------------------
struct RTDevice {
    uint8_t     address;
    std::string name;

    // Called by BC for a Receive transaction: BC sends data_words to RT,
    // RT returns a StatusWord.
    // Called by BC for a Transmit request: RT fills response_words, returns SW.
    StatusWord  status;       // current RT status register

    // Synthetic data generation function (returns words for a transmit request)
    std::function<std::vector<uint16_t>(uint8_t subaddr, int count)> generate_data;
};

// ---------------------------------------------------------------------------
// Synthetic data generators for each RT subsystem
// ---------------------------------------------------------------------------

static std::mt19937 g_rng(42); // deterministic seed for reproducibility

// RT1 — Inertial Navigation System (INS)
// Subaddress 1: Euler angles (roll, pitch, yaw) as int16 in 0.01-degree units
// Subaddress 2: Velocity (Vn, Ve, Vd) as int16 in 0.1 m/s units
static std::vector<uint16_t> ins_data(uint8_t subaddr, int count) {
    std::vector<uint16_t> words;
    if (subaddr == 1) {
        // Roll=2.35 deg, Pitch=1.15 deg, Yaw=247.80 deg
        int16_t roll  = static_cast<int16_t>(  235); // 0.01 deg/LSB
        int16_t pitch = static_cast<int16_t>(  115);
        int16_t yaw   = static_cast<int16_t>(24780);
        words.push_back(static_cast<uint16_t>(roll));
        words.push_back(static_cast<uint16_t>(pitch));
        words.push_back(static_cast<uint16_t>(yaw));
        // Pad to requested count
        while (static_cast<int>(words.size()) < count) words.push_back(0xFFFFu);
    } else if (subaddr == 2) {
        // Vn=+245.3 m/s, Ve=-12.8 m/s, Vd=-0.5 m/s (0.1 m/s units)
        words.push_back(static_cast<uint16_t>( 2453));
        words.push_back(static_cast<uint16_t>(static_cast<int16_t>(-128)));
        words.push_back(static_cast<uint16_t>(static_cast<int16_t>( -5)));
        while (static_cast<int>(words.size()) < count) words.push_back(0x0000u);
    } else {
        for (int i = 0; i < count; ++i) words.push_back(static_cast<uint16_t>(i));
    }
    while (static_cast<int>(words.size()) > count) words.pop_back();
    return words;
}

// RT2 — Radar System
// Subaddress 1: Track count (word 0), PRF code (word 1), beam angle (word 2)
static std::vector<uint16_t> radar_data(uint8_t subaddr, int count) {
    std::vector<uint16_t> words;
    if (subaddr == 1) {
        words.push_back(7);             // 7 active tracks
        words.push_back(0x0004);        // PRF code 4 (medium PRF)
        words.push_back(0x0B40);        // Beam azimuth 2880 = 28.80 deg * 100
        while (static_cast<int>(words.size()) < count) words.push_back(0);
    } else {
        for (int i = 0; i < count; ++i) words.push_back(static_cast<uint16_t>(0xA000u | i));
    }
    while (static_cast<int>(words.size()) > count) words.pop_back();
    return words;
}

// RT3 — Fire Control Radar (FCR)
// Subaddress 1: Mode word, selected target range (m), closure rate (m/s * 10)
static std::vector<uint16_t> fcr_data(uint8_t subaddr, int count) {
    std::vector<uint16_t> words;
    if (subaddr == 1) {
        words.push_back(0x0003);        // mode: STT (Single Target Track)
        words.push_back(14200);         // 14.2 km range
        words.push_back(static_cast<uint16_t>(static_cast<int16_t>(-3450))); // -345 m/s closure
        while (static_cast<int>(words.size()) < count) words.push_back(0);
    } else {
        for (int i = 0; i < count; ++i) words.push_back(static_cast<uint16_t>(0xB000u | i));
    }
    while (static_cast<int>(words.size()) > count) words.pop_back();
    return words;
}

// RT4 — Environmental Control System (ECS)
// Subaddress 1: Cabin temp (0.1 degC), bleed air pressure (0.1 psi), duct temp
static std::vector<uint16_t> ecs_data(uint8_t subaddr, int count) {
    std::vector<uint16_t> words;
    if (subaddr == 1) {
        words.push_back(215);           // 21.5 degC cabin temp
        words.push_back(152);           // 15.2 psi bleed air
        words.push_back(423);           // 42.3 degC duct temp
        while (static_cast<int>(words.size()) < count) words.push_back(0);
    } else {
        for (int i = 0; i < count; ++i) words.push_back(static_cast<uint16_t>(0xC000u | i));
    }
    while (static_cast<int>(words.size()) > count) words.pop_back();
    return words;
}

// RT5 — Multifunction Display (MFD)
// Subaddress 1: Display mode, brightness (0-255), status flags
static std::vector<uint16_t> mfd_data(uint8_t subaddr, int count) {
    std::vector<uint16_t> words;
    if (subaddr == 1) {
        words.push_back(0x0002);        // display mode: HSI
        words.push_back(200);           // brightness 200/255
        words.push_back(0x0001);        // status: NORM, backlight OK
        while (static_cast<int>(words.size()) < count) words.push_back(0);
    } else {
        for (int i = 0; i < count; ++i) words.push_back(static_cast<uint16_t>(0xD000u | i));
    }
    while (static_cast<int>(words.size()) > count) words.pop_back();
    return words;
}

// ---------------------------------------------------------------------------
// Build the RT roster
// ---------------------------------------------------------------------------
static std::vector<RTDevice> build_rt_roster() {
    std::vector<RTDevice> rts;

    auto make_status = [](uint8_t addr) {
        StatusWord sw{};
        sw.rt_address = addr;
        sw.message_error = false;
        sw.service_request = false;
        sw.busy = false;
        sw.terminal_flag = false;
        return sw;
    };

    rts.push_back({ 1, "INS (RT1)",      make_status(1), ins_data  });
    rts.push_back({ 2, "Radar (RT2)",    make_status(2), radar_data});
    rts.push_back({ 3, "FCR (RT3)",      make_status(3), fcr_data  });
    rts.push_back({ 4, "ECS (RT4)",      make_status(4), ecs_data  });
    rts.push_back({ 5, "MFD (RT5)",      make_status(5), mfd_data  });

    return rts;
}

// ---------------------------------------------------------------------------
// Bus Controller transaction executor
// ---------------------------------------------------------------------------

// Find an RT by address
static RTDevice* find_rt(std::vector<RTDevice>& rts, uint8_t addr) {
    for (auto& rt : rts)
        if (rt.address == addr) return &rt;
    return nullptr;
}

// BC→RT (Receive) transfer: BC sends count data words to RT
static void bc_to_rt(std::vector<RTDevice>& rts, BusID bus,
                     uint8_t rt_addr, uint8_t subaddr, int count,
                     const std::vector<uint16_t>& tx_data) {
    // 1. BC transmits command word
    CommandWord cw{};
    cw.rt_address = rt_addr;
    cw.tr_bit     = false; // Receive by RT
    cw.subaddress = subaddr;
    cw.word_count = static_cast<uint8_t>(count % 32); // 0=32
    uint16_t cw_raw = cw.encode();

    std::ostringstream oss;
    oss << "CW -> RT" << static_cast<int>(rt_addr)
        << " Recv SA=" << static_cast<int>(subaddr)
        << " WC=" << count;
    log_word(bus, WordType::COMMAND, cw_raw, oss.str());

    // 2. BC transmits data words
    for (int i = 0; i < static_cast<int>(tx_data.size()) && i < count; ++i) {
        std::ostringstream ds;
        ds << "DW[" << i << "] 0x" << std::hex << std::uppercase
           << std::setfill('0') << std::setw(4) << tx_data[i];
        log_word(bus, WordType::DATA, tx_data[i], ds.str());
    }

    // 3. RT transmits status word
    RTDevice* rt = find_rt(rts, rt_addr);
    if (!rt) return;
    StatusWord sw = rt->status;
    uint16_t sw_raw = sw.encode();
    oss.str("");
    oss << "SW <- RT" << static_cast<int>(rt_addr)
        << " [" << (sw.busy ? "BUSY " : "")
        << (sw.message_error ? "MSG_ERR " : "")
        << (sw.service_request ? "SVC_REQ " : "")
        << "OK]";
    log_word(bus, WordType::STATUS, sw_raw, oss.str());
}

// RT→BC (Transmit) transfer: BC requests data from RT
static void rt_to_bc(std::vector<RTDevice>& rts, BusID bus,
                     uint8_t rt_addr, uint8_t subaddr, int count) {
    // 1. BC transmits command word (T/R=1, requesting RT to transmit)
    CommandWord cw{};
    cw.rt_address = rt_addr;
    cw.tr_bit     = true;
    cw.subaddress = subaddr;
    cw.word_count = static_cast<uint8_t>(count % 32);
    uint16_t cw_raw = cw.encode();

    std::ostringstream oss;
    oss << "CW -> RT" << static_cast<int>(rt_addr)
        << " Xmit SA=" << static_cast<int>(subaddr)
        << " WC=" << count;
    log_word(bus, WordType::COMMAND, cw_raw, oss.str());

    // 2. RT responds with status word
    RTDevice* rt = find_rt(rts, rt_addr);
    if (!rt) return;
    StatusWord sw = rt->status;
    uint16_t sw_raw = sw.encode();
    oss.str("");
    oss << "SW <- RT" << static_cast<int>(rt_addr)
        << " [" << (sw.busy ? "BUSY " : "")
        << (sw.service_request ? "SVC_REQ " : "")
        << "OK]";
    log_word(bus, WordType::STATUS, sw_raw, oss.str());

    // 3. RT transmits data words
    if (!sw.busy) {
        auto data = rt->generate_data(subaddr, count);
        for (int i = 0; i < static_cast<int>(data.size()); ++i) {
            std::ostringstream ds;
            ds << "DW[" << i << "] 0x" << std::hex << std::uppercase
               << std::setfill('0') << std::setw(4) << data[i];
            log_word(bus, WordType::DATA, data[i], ds.str());
        }
    }
}

// ---------------------------------------------------------------------------
// Print the bus monitor transcript
// ---------------------------------------------------------------------------
static void print_transcript(bool verbose) {
    std::cout << "\n=== MIL-STD-1553B Bus Monitor Transcript ===\n";
    std::cout << std::string(72, '-') << "\n";
    std::cout << std::left
              << std::setw(5)  << "SEQ"
              << std::setw(5)  << "BUS"
              << std::setw(8)  << "TYPE"
              << std::setw(8)  << "RAW(hex)"
              << std::setw(6)  << "PAR"
              << "DESCRIPTION\n";
    std::cout << std::string(72, '-') << "\n";

    for (const auto& t : g_bus_log) {
        std::string bus_str  = (t.bus == BusID::A) ? "A" : "B";
        std::string type_str;
        switch (t.word_type) {
            case WordType::COMMAND: type_str = "CMD"; break;
            case WordType::STATUS:  type_str = "STS"; break;
            case WordType::DATA:    type_str = "DAT"; break;
        }

        std::ostringstream raw_hex;
        raw_hex << std::hex << std::uppercase << std::setfill('0')
                << std::setw(4) << t.raw_word;

        std::cout << std::left << std::dec
                  << std::setw(5) << t.sequence
                  << std::setw(5) << bus_str
                  << std::setw(8) << type_str
                  << std::setw(8) << raw_hex.str()
                  << std::setw(6) << (t.parity_ok ? "OK" : "FAIL")
                  << t.description << "\n";

        if (verbose) {
            std::cout << "         bits: " << std::bitset<16>(t.raw_word) << "\n";
        }
    }
    std::cout << std::string(72, '-') << "\n";
    std::cout << "Total words on bus: " << g_bus_log.size() << "\n\n";
}

// ---------------------------------------------------------------------------
// Demo scenario: 10-message BC schedule
// ---------------------------------------------------------------------------
static void run_demo(bool verbose) {
    std::cout << "=== MIL-STD-1553B Simulator — Demo Mode ===\n";
    std::cout << "Simulating Bus Controller scenario (10 messages)...\n";

    auto rts = build_rt_roster();

    // Message 1: BC polls INS attitude data (RT1, SA1, 3 words, Bus A)
    rt_to_bc(rts, BusID::A, 1, 1, 3);

    // Message 2: BC polls INS velocity data (RT1, SA2, 3 words, Bus A)
    rt_to_bc(rts, BusID::A, 1, 2, 3);

    // Message 3: BC polls Radar track data (RT2, SA1, 3 words, Bus A)
    rt_to_bc(rts, BusID::A, 2, 1, 3);

    // Message 4: BC polls FCR track data (RT3, SA1, 3 words, Bus B)
    rt_to_bc(rts, BusID::B, 3, 1, 3);

    // Message 5: BC polls ECS status (RT4, SA1, 3 words, Bus A)
    rt_to_bc(rts, BusID::A, 4, 1, 3);

    // Message 6: BC polls MFD status (RT5, SA1, 3 words, Bus A)
    rt_to_bc(rts, BusID::A, 5, 1, 3);

    // Message 7: BC sends display mode command to MFD (RT5, SA1, 3 words, Bus A)
    // Command: switch to MAP mode (0x0003), brightness 180, flags 0x0000
    bc_to_rt(rts, BusID::A, 5, 1, 3, { 0x0003, 180, 0x0000 });

    // Message 8: BC polls INS attitude again (second frame, Bus B — redundancy)
    rt_to_bc(rts, BusID::B, 1, 1, 3);

    // Message 9: BC polls Radar again on Bus B (bus failover test)
    rt_to_bc(rts, BusID::B, 2, 1, 3);

    // Message 10: BC marks RT3 FCR "service request check" (BC→RT mode code)
    // Mode code: SA=0 (mode code), T/R=1 (Transmit), MC=0x04 (Transmit Status Word)
    CommandWord mc{};
    mc.rt_address  = 3;
    mc.tr_bit      = true;
    mc.subaddress  = 0;   // mode code subaddress
    mc.word_count  = 4;   // mode code 4 = Transmit Status Word
    mc.is_mode_code = true;
    uint16_t mc_raw = mc.encode();
    log_word(BusID::A, WordType::COMMAND, mc_raw,
             "CW -> RT3 MODE_CODE=04 (Xmit Status Word)");
    // RT3 responds with status word
    StatusWord sw = rts[2].status; // RT3 is index 2
    sw.service_request = true;     // inject service request flag for drama
    log_word(BusID::A, WordType::STATUS, sw.encode(),
             "SW <- RT3 [SVC_REQ] FCR requests attention");

    print_transcript(verbose);

    // Summary
    std::cout << "RT Subsystem Summary:\n";
    std::cout << "  RT1 INS     : Roll=2.35 deg  Pitch=1.15 deg  Yaw=247.80 deg\n";
    std::cout << "  RT1 INS Vel : Vn=+245.3 m/s  Ve=-12.8 m/s  Vd=-0.5 m/s\n";
    std::cout << "  RT2 Radar   : 7 active tracks  PRF=4  Beam az=28.80 deg\n";
    std::cout << "  RT3 FCR     : STT mode  Range=14.2 km  Closure=-345 m/s\n";
    std::cout << "  RT4 ECS     : Cabin=21.5 C  Bleed=15.2 psi  Duct=42.3 C\n";
    std::cout << "  RT5 MFD     : Mode=HSI  Brightness=200  Status=NORM\n";
}

// ---------------------------------------------------------------------------
// main
// ---------------------------------------------------------------------------
int main(int argc, char* argv[]) {
    bool verbose = false;
    for (int i = 1; i < argc; ++i) {
        std::string a = argv[i];
        if (a == "--verbose" || a == "-v") verbose = true;
    }
    run_demo(verbose);
    return 0;
}
