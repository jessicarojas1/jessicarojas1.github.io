/*
 * arinc429_decoder.cpp
 *
 * Description:
 *   ARINC 429 avionics bus frame decoder supporting BNR and BCD data types.
 *   ARINC 429 is a one-way serial data bus used in commercial and military
 *   aviation. Each 32-bit word carries a label, source/destination identifier,
 *   sign/status matrix, data payload, and an odd parity bit.
 *
 *   Word layout (bit numbering per ARINC 429 spec, LSB = bit 1):
 *     Bits  1-8  : Label (octal, transmitted LSB-first so byte is bit-reversed)
 *     Bits  9-10 : SDI  (Source/Destination Identifier)
 *     Bits 11-28 : Data (BNR or BCD payload)
 *     Bits 29-30 : SSM  (Sign/Status Matrix)
 *     Bit  31    : Parity (odd parity over bits 1-31)
 *     Bit  32    : Unused / filler
 *
 *   Supported labels (octal):
 *     101 = Indicated Airspeed (BNR, knots, range 0-512)
 *     103 = Mach Number        (BNR, Mach, range 0-4.096)
 *     203 = Pressure Altitude  (BNR, feet, range -1280 to 126720)
 *     270 = Selected Heading   (BNR, degrees, range 0-360)
 *     310 = Present Position Latitude  (BNR, degrees, range -90 to +90)
 *     311 = Present Position Longitude (BNR, degrees, range -180 to +180)
 *
 * Build:
 *   g++ -std=c++17 -O2 arinc429_decoder.cpp -o arinc429-decoder
 *
 * Usage:
 *   ./arinc429-decoder              # demo mode: generate and decode synthetic words
 *   ./arinc429-decoder <file.txt>   # decode hex words from file (one per line)
 *   echo "2001AB12" | ./arinc429-decoder -  # read from stdin with '-' argument
 *
 * Note:
 *   Requires native compilation. Not WASM-compatible due to hardware I/O
 *   dependencies (direct bus interface stubs) and platform-specific bit ops.
 *
 * References:
 *   ARINC Specification 429 Part 1-17 (Airlines Electronic Engineering Committee)
 */

#include <algorithm>
#include <array>
#include <bitset>
#include <cmath>
#include <cstdint>
#include <fstream>
#include <iomanip>
#include <iostream>
#include <optional>
#include <sstream>
#include <stdexcept>
#include <string>
#include <unordered_map>
#include <vector>

// ---------------------------------------------------------------------------
// ARINC 429 constants
// ---------------------------------------------------------------------------

// SSM field encoding (bits 29-30) — meaning depends on data type
enum class SSM : uint8_t {
    FW  = 0b00, // Failure Warning (BNR) / Plus (BCD)
    NCD = 0b01, // No Computed Data
    FT  = 0b10, // Functional Test
    NO  = 0b11  // Normal Operation (BNR) / Minus (BCD)
};

static const char* ssm_to_string(SSM s) {
    switch (s) {
        case SSM::FW:  return "FW (Failure Warning)";
        case SSM::NCD: return "NCD (No Computed Data)";
        case SSM::FT:  return "FT (Functional Test)";
        case SSM::NO:  return "NO (Normal Operation)";
    }
    return "UNKNOWN";
}

// Data encoding type for a label
enum class DataType { BNR, BCD };

// Label descriptor: everything needed to decode a particular label
struct LabelInfo {
    const char*  name;
    DataType     type;
    const char*  unit;
    double       lsb;        // BNR: value of bit 11 (the LSB of data field)
    double       offset;     // BNR: signed offset for two's-complement
    int          data_bits;  // number of meaningful data bits (up to 18)
    bool         is_signed;  // BNR signed (two's complement)?
};

// ---------------------------------------------------------------------------
// Label table — octal label as key (stored as decimal)
// ARINC 429 BNR resolution = range / 2^(data_bits-1) for signed
// ---------------------------------------------------------------------------
static const std::unordered_map<uint8_t, LabelInfo> LABEL_TABLE = {
    // Octal 101 = decimal 65: Indicated Airspeed
    // Range 0–512 kt, 18-bit unsigned BNR, LSB = 512/2^18 ≈ 0.001953 kt
    { 065, { "Indicated Airspeed",      DataType::BNR, "kt",      512.0 / (1 << 18), 0.0,   18, false } },

    // Octal 103 = decimal 67: Mach Number
    // Range 0–4.096, 18-bit unsigned BNR, LSB = 4.096/2^18
    { 067, { "Mach Number",             DataType::BNR, "Mach",    4.096 / (1 << 18), 0.0,   18, false } },

    // Octal 203 = decimal 131: Pressure Altitude
    // Range -1280 to +126720 ft, 18-bit signed BNR, LSB = 0.25 ft
    // (Actually common ARINC 429 altitude: signed, LSB = 0.125 ft per GAMA)
    { 0203u, { "Pressure Altitude",     DataType::BNR, "ft",      0.5,               0.0,   18, true  } },

    // Octal 270 = decimal 184: Selected Heading
    // Range 0–360 deg, 12-bit unsigned BNR, LSB = 360/2^12 ≈ 0.0879 deg
    { 0270u, { "Selected Heading",      DataType::BNR, "deg",     360.0 / (1 << 12), 0.0,   12, false } },

    // Octal 310 = decimal 200: Present Position Latitude
    // Range -90 to +90 deg, 18-bit signed BNR, LSB = 180/2^18 ≈ 0.000687 deg
    { 0310u, { "Latitude",              DataType::BNR, "deg",     180.0 / (1 << 18), 0.0,   18, true  } },

    // Octal 311 = decimal 201: Present Position Longitude
    // Range -180 to +180 deg, 18-bit signed BNR, LSB = 360/2^18 ≈ 0.001373 deg
    { 0311u, { "Longitude",             DataType::BNR, "deg",     360.0 / (1 << 18), 0.0,   18, true  } },
};

// ---------------------------------------------------------------------------
// Bit-reversal helper
// ARINC 429 transmits the label byte LSB-first, so the 8-bit label field
// in a captured 32-bit word has its bits reversed relative to the octal label
// printed in the ARINC spec.
// ---------------------------------------------------------------------------
static uint8_t reverse_bits_8(uint8_t b) {
    b = static_cast<uint8_t>(((b & 0xF0u) >> 4) | ((b & 0x0Fu) << 4));
    b = static_cast<uint8_t>(((b & 0xCCu) >> 2) | ((b & 0x33u) << 2));
    b = static_cast<uint8_t>(((b & 0xAAu) >> 1) | ((b & 0x55u) << 1));
    return b;
}

// ---------------------------------------------------------------------------
// Parity check — ARINC 429 uses ODD parity over bits 1-31 (bit 32 excluded)
// Returns true if parity is correct.
// ---------------------------------------------------------------------------
static bool check_odd_parity(uint32_t word) {
    // Mask to bits 1-31 (positions 0-30 in zero-indexed)
    uint32_t val = word & 0x7FFFFFFFu;
    return (__builtin_popcount(val) & 1) == 1; // odd population count
}

// ---------------------------------------------------------------------------
// ARINC 429 word decoder
// ---------------------------------------------------------------------------
struct DecodedWord {
    uint32_t    raw;
    uint8_t     label_raw;      // as stored in word (bit-reversed)
    uint8_t     label_octal;    // corrected label (spec-standard)
    uint8_t     sdi;            // 0-3
    uint32_t    data_field;     // bits 11-28 (18 bits)
    SSM         ssm;
    bool        parity_ok;
    bool        label_known;
    double      value;
    const char* unit;
    const char* label_name;
    std::string ssm_str;
};

static DecodedWord decode_word(uint32_t raw) {
    DecodedWord d{};
    d.raw = raw;

    // Extract label (bits 1-8, zero-indexed 0-7)
    d.label_raw   = static_cast<uint8_t>(raw & 0xFFu);
    d.label_octal = reverse_bits_8(d.label_raw);

    // Extract SDI (bits 9-10, zero-indexed 8-9)
    d.sdi = static_cast<uint8_t>((raw >> 8) & 0x3u);

    // Extract data field (bits 11-28, zero-indexed 10-27) — 18 bits
    d.data_field = (raw >> 10) & 0x3FFFFu;

    // Extract SSM (bits 29-30, zero-indexed 28-29)
    d.ssm = static_cast<SSM>((raw >> 28) & 0x3u);
    d.ssm_str = ssm_to_string(d.ssm);

    // Parity check
    d.parity_ok = check_odd_parity(raw);

    // Look up label
    auto it = LABEL_TABLE.find(d.label_octal);
    if (it != LABEL_TABLE.end()) {
        d.label_known = true;
        const LabelInfo& info = it->second;
        d.label_name = info.name;
        d.unit       = info.unit;

        if (info.type == DataType::BNR) {
            uint32_t bits = d.data_field & ((1u << info.data_bits) - 1u);
            if (info.is_signed) {
                // Two's complement: MSB of the data sub-field is sign bit
                uint32_t sign_mask = 1u << (info.data_bits - 1);
                if (bits & sign_mask) {
                    // Negative: extend sign
                    int32_t signed_val = static_cast<int32_t>(bits) -
                                         static_cast<int32_t>(1u << info.data_bits);
                    d.value = signed_val * info.lsb;
                } else {
                    d.value = static_cast<int32_t>(bits) * info.lsb;
                }
            } else {
                d.value = bits * info.lsb;
            }
        } else {
            // BCD: each nibble is a decimal digit, data bits 11-28 packed as
            // 4-bit BCD groups. Simple linear decode for demo purposes.
            double bcd_val = 0.0;
            double place   = 1.0;
            for (int nibble = 0; nibble < 5; ++nibble) {
                uint32_t digit = (d.data_field >> (nibble * 4)) & 0xFu;
                bcd_val += digit * place;
                place   *= 10.0;
            }
            d.value = bcd_val;
        }
    } else {
        d.label_known = false;
        d.label_name  = "Unknown";
        d.unit        = "?";
        d.value       = 0.0;
    }

    return d;
}

// ---------------------------------------------------------------------------
// Pretty-print a decoded word
// ---------------------------------------------------------------------------
static void print_decoded(const DecodedWord& d, int index) {
    std::cout << "--- Word #" << std::setw(2) << index << " ---\n";
    std::cout << "  Raw (hex)       : 0x" << std::uppercase << std::hex
              << std::setfill('0') << std::setw(8) << d.raw << std::dec << "\n";
    std::cout << "  Label (octal)   : " << std::oct << static_cast<int>(d.label_octal)
              << std::dec << "\n";
    std::cout << "  Label name      : " << d.label_name << "\n";
    std::cout << "  SDI             : " << static_cast<int>(d.sdi) << "\n";
    std::cout << "  SSM             : " << d.ssm_str << "\n";
    std::cout << "  Data field (hex): 0x" << std::hex << d.data_field << std::dec << "\n";
    std::cout << "  Parity          : " << (d.parity_ok ? "OK (odd)" : "FAIL") << "\n";
    if (d.label_known) {
        std::cout << std::fixed << std::setprecision(4);
        std::cout << "  Decoded value   : " << d.value << " " << d.unit << "\n";
    }
    std::cout << "\n";
}

// ---------------------------------------------------------------------------
// Synthetic word generator — builds a valid ARINC 429 word from components
// ---------------------------------------------------------------------------
static uint32_t build_word(uint8_t label_octal, uint8_t sdi, uint32_t data18,
                           SSM ssm) {
    uint8_t  label_raw = reverse_bits_8(label_octal);
    uint32_t word = 0;
    word |= static_cast<uint32_t>(label_raw);                     // bits 1-8
    word |= (static_cast<uint32_t>(sdi)  & 0x3u) << 8;           // bits 9-10
    word |= (static_cast<uint32_t>(data18) & 0x3FFFFu) << 10;    // bits 11-28
    word |= (static_cast<uint32_t>(ssm)  & 0x3u) << 28;          // bits 29-30

    // Set parity bit (bit 31, zero-indexed 30) to achieve odd parity
    uint32_t val = word & 0x7FFFFFFFu;
    if ((__builtin_popcount(val) & 1) == 0) {
        word |= (1u << 30); // flip parity bit to make count odd
    }
    return word;
}

// Encode a BNR value back into an 18-bit data field
static uint32_t encode_bnr(double value, const LabelInfo& info) {
    double raw = value / info.lsb;
    int32_t int_raw = static_cast<int32_t>(std::round(raw));
    uint32_t mask = (1u << info.data_bits) - 1u;
    return static_cast<uint32_t>(int_raw) & mask;
}

// ---------------------------------------------------------------------------
// Demo mode: generate 10 synthetic words and decode them
// ---------------------------------------------------------------------------
static void run_demo() {
    std::cout << "=== ARINC 429 Decoder — Demo Mode ===\n";
    std::cout << "Generating 10 synthetic ARINC 429 words...\n\n";

    struct Scenario {
        uint8_t label_octal;
        double  value;
        SSM     ssm;
        uint8_t sdi;
    };

    const std::array<Scenario, 10> scenarios = {{
        { 0203u, 35000.0,   SSM::NO,  1 },  // Altitude 35,000 ft
        { 0203u, 34975.5,   SSM::NO,  1 },  // Altitude 34,975.5 ft
        { 065,   250.5,     SSM::NO,  0 },  // IAS 250.5 kt
        { 065,   252.0,     SSM::NO,  0 },  // IAS 252.0 kt
        { 067,   0.780,     SSM::NO,  0 },  // Mach 0.780
        { 0270u, 185.625,   SSM::NO,  2 },  // Selected heading 185.625 deg
        { 0310u, 37.7749,   SSM::NO,  3 },  // Latitude 37.7749 deg (San Francisco)
        { 0311u, -122.4194, SSM::NO,  3 },  // Longitude -122.4194 deg (San Francisco)
        { 0203u, 0.0,       SSM::NCD, 1 },  // Altitude — no computed data
        { 065,   0.0,       SSM::FW,  0 },  // IAS — failure warning
    }};

    int idx = 1;
    for (const auto& sc : scenarios) {
        auto it = LABEL_TABLE.find(sc.label_octal);
        uint32_t data18 = 0;
        if (it != LABEL_TABLE.end()) {
            data18 = encode_bnr(sc.value, it->second);
        }
        uint32_t word = build_word(sc.label_octal, sc.sdi, data18, sc.ssm);
        DecodedWord d = decode_word(word);
        print_decoded(d, idx++);
    }
}

// ---------------------------------------------------------------------------
// File / stdin mode: read hex words one per line and decode
// ---------------------------------------------------------------------------
static void decode_stream(std::istream& in) {
    std::string line;
    int idx = 1;
    while (std::getline(in, line)) {
        // Strip whitespace and comments
        auto pos = line.find('#');
        if (pos != std::string::npos) line.resize(pos);
        while (!line.empty() && (line.back() == ' ' || line.back() == '\r'))
            line.pop_back();
        if (line.empty()) continue;

        // Parse hex
        uint32_t word = 0;
        try {
            word = static_cast<uint32_t>(std::stoul(line, nullptr, 16));
        } catch (...) {
            std::cerr << "Skipping unparseable line: " << line << "\n";
            continue;
        }
        DecodedWord d = decode_word(word);
        print_decoded(d, idx++);
    }
}

// ---------------------------------------------------------------------------
// main
// ---------------------------------------------------------------------------
int main(int argc, char* argv[]) {
    if (argc == 1) {
        // No arguments: run demo
        run_demo();
        return 0;
    }

    std::string arg1 = argv[1];

    if (arg1 == "--demo") {
        run_demo();
        return 0;
    }

    if (arg1 == "-") {
        std::cout << "=== ARINC 429 Decoder — stdin mode ===\n\n";
        decode_stream(std::cin);
    } else {
        std::ifstream f(arg1);
        if (!f.is_open()) {
            std::cerr << "Error: cannot open file '" << arg1 << "'\n";
            return 1;
        }
        std::cout << "=== ARINC 429 Decoder — file: " << arg1 << " ===\n\n";
        decode_stream(f);
    }

    return 0;
}
