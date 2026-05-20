/*
 * aes_vault.cpp — AES-256-GCM Encrypted File Vault
 * =================================================
 * Encrypts and decrypts files using AES-256-GCM authenticated encryption.
 * Keys are derived from a user passphrase via PBKDF2-HMAC-SHA256 (100,000
 * iterations). Each encryption produces a fresh random 32-byte salt and
 * 12-byte IV; both are stored in the vault file header alongside the GCM
 * authentication tag so the file is fully self-contained.
 *
 * File format (binary, big-endian fields where applicable):
 *   Offset  Size  Field
 *   ------  ----  -----
 *        0     4  Magic: "AESV"
 *        4     1  Version: 0x01
 *        5    32  PBKDF2 salt (random, per-file)
 *       37    12  GCM IV / nonce (random, per-file)
 *       49    16  GCM authentication tag
 *       65     N  Ciphertext  (same length as plaintext)
 *
 * Optional CUI marking (--label flag):
 *   When --label <marking> is supplied the marking string is prepended to
 *   the plaintext as a null-terminated ASCII header before encryption, so
 *   the marking travels with the data and is authenticated by the GCM tag.
 *   Example: --label "CUI//SP-CTI"
 *
 * Usage:
 *   aes-vault encrypt [--label <marking>] <infile> <outfile>
 *   aes-vault decrypt <infile> <outfile>
 *
 * Build (native):
 *   g++ -std=c++17 -O2 aes_vault.cpp -lssl -lcrypto -o aes-vault
 *
 * Build (WASM via Emscripten + Emscripten-OpenSSL port):
 *   emcc aes_vault.cpp -lssl -lcrypto -o aes-vault.js
 *
 * Dependencies: OpenSSL >= 1.1.0 (libssl-dev / openssl-devel)
 *
 * Author: Jessica Rojas — Systems & Zero-Trust Portfolio
 * License: MIT
 */

#include <openssl/evp.h>
#include <openssl/rand.h>
#include <openssl/err.h>

#include <array>
#include <cstring>
#include <filesystem>
#include <fstream>
#include <iostream>
#include <stdexcept>
#include <string>
#include <vector>

#ifdef _WIN32
#  include <windows.h>
#else
#  include <termios.h>
#  include <unistd.h>
#endif

namespace fs = std::filesystem;

// ── Constants ────────────────────────────────────────────────────────────────

static constexpr std::array<char, 4> MAGIC   = {'A', 'E', 'S', 'V'};
static constexpr uint8_t             VERSION = 0x01;
static constexpr int  SALT_LEN   = 32;
static constexpr int  IV_LEN     = 12;   // 96-bit nonce recommended for GCM
static constexpr int  TAG_LEN    = 16;
static constexpr int  KEY_LEN    = 32;   // AES-256
static constexpr int  PBKDF2_ITER = 100'000;

// Byte offset of each header field in the vault file
static constexpr std::streamoff OFF_MAGIC   =  0;
static constexpr std::streamoff OFF_VERSION =  4;
static constexpr std::streamoff OFF_SALT    =  5;
static constexpr std::streamoff OFF_IV      = 37;
static constexpr std::streamoff OFF_TAG     = 49;
static constexpr std::streamoff OFF_CIPHER  = 65;

// ── Helpers ──────────────────────────────────────────────────────────────────

static void ssl_check(int rc, const char* context)
{
    if (rc != 1) {
        char buf[256];
        ERR_error_string_n(ERR_get_error(), buf, sizeof(buf));
        throw std::runtime_error(std::string(context) + ": " + buf);
    }
}

static std::string read_passphrase(const char* prompt)
{
    std::cerr << prompt << std::flush;

#ifdef _WIN32
    HANDLE hStdin = GetStdHandle(STD_INPUT_HANDLE);
    DWORD  mode   = 0;
    GetConsoleMode(hStdin, &mode);
    SetConsoleMode(hStdin, mode & ~ENABLE_ECHO_INPUT);
    std::string pass;
    std::getline(std::cin, pass);
    SetConsoleMode(hStdin, mode);
#else
    termios old_tio{}, new_tio{};
    tcgetattr(STDIN_FILENO, &old_tio);
    new_tio = old_tio;
    new_tio.c_lflag &= ~static_cast<tcflag_t>(ECHO);
    tcsetattr(STDIN_FILENO, TCSANOW, &new_tio);
    std::string pass;
    std::getline(std::cin, pass);
    tcsetattr(STDIN_FILENO, TCSANOW, &old_tio);
#endif
    std::cerr << "\n";
    return pass;
}

static std::vector<uint8_t> read_file(const fs::path& path)
{
    std::ifstream f(path, std::ios::binary | std::ios::ate);
    if (!f) throw std::runtime_error("Cannot open input file: " + path.string());
    auto sz = f.tellg();
    if (sz < 0) throw std::runtime_error("File size error");
    f.seekg(0);
    std::vector<uint8_t> buf(static_cast<size_t>(sz));
    f.read(reinterpret_cast<char*>(buf.data()), sz);
    return buf;
}

static void write_file(const fs::path& path, const std::vector<uint8_t>& data)
{
    std::ofstream f(path, std::ios::binary | std::ios::trunc);
    if (!f) throw std::runtime_error("Cannot open output file: " + path.string());
    f.write(reinterpret_cast<const char*>(data.data()),
            static_cast<std::streamsize>(data.size()));
}

// Derive AES-256 key from passphrase + salt using PBKDF2-HMAC-SHA256
static std::array<uint8_t, KEY_LEN> derive_key(
    const std::string&          pass,
    const std::array<uint8_t, SALT_LEN>& salt)
{
    std::array<uint8_t, KEY_LEN> key{};
    int rc = PKCS5_PBKDF2_HMAC(
        pass.data(), static_cast<int>(pass.size()),
        salt.data(), SALT_LEN,
        PBKDF2_ITER,
        EVP_sha256(),
        KEY_LEN, key.data());
    ssl_check(rc, "PBKDF2");
    return key;
}

// ── Encrypt ──────────────────────────────────────────────────────────────────

static void do_encrypt(const fs::path& infile,
                       const fs::path& outfile,
                       const std::string& label)
{
    // Read plaintext
    auto plaintext = read_file(infile);

    // Optionally prepend the CUI label as a null-terminated string
    if (!label.empty()) {
        std::vector<uint8_t> prefixed;
        prefixed.reserve(label.size() + 1 + plaintext.size());
        prefixed.insert(prefixed.end(), label.begin(), label.end());
        prefixed.push_back(0x00);  // null terminator
        prefixed.insert(prefixed.end(), plaintext.begin(), plaintext.end());
        plaintext = std::move(prefixed);
    }

    // Read passphrase (twice for confirmation)
    auto pass  = read_passphrase("Passphrase        : ");
    auto pass2 = read_passphrase("Confirm passphrase: ");
    if (pass != pass2) throw std::runtime_error("Passphrases do not match.");

    // Generate random salt and IV
    std::array<uint8_t, SALT_LEN> salt{};
    std::array<uint8_t, IV_LEN>   iv{};
    ssl_check(RAND_bytes(salt.data(), SALT_LEN), "RAND salt");
    ssl_check(RAND_bytes(iv.data(),   IV_LEN),   "RAND iv");

    // Derive key
    auto key = derive_key(pass, salt);

    // AES-256-GCM encryption
    EVP_CIPHER_CTX* ctx = EVP_CIPHER_CTX_new();
    if (!ctx) throw std::runtime_error("EVP_CIPHER_CTX_new failed");

    ssl_check(EVP_EncryptInit_ex(ctx, EVP_aes_256_gcm(), nullptr, nullptr, nullptr),
              "EncryptInit");
    ssl_check(EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_SET_IVLEN, IV_LEN, nullptr),
              "Set IV length");
    ssl_check(EVP_EncryptInit_ex(ctx, nullptr, nullptr, key.data(), iv.data()),
              "EncryptInit key/iv");

    std::vector<uint8_t> ciphertext(plaintext.size());
    int out_len = 0;
    ssl_check(EVP_EncryptUpdate(ctx,
                                ciphertext.data(), &out_len,
                                plaintext.data(),
                                static_cast<int>(plaintext.size())),
              "EncryptUpdate");

    int final_len = 0;
    ssl_check(EVP_EncryptFinal_ex(ctx, ciphertext.data() + out_len, &final_len),
              "EncryptFinal");
    ciphertext.resize(static_cast<size_t>(out_len + final_len));

    // Retrieve GCM tag
    std::array<uint8_t, TAG_LEN> tag{};
    ssl_check(EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_GET_TAG, TAG_LEN, tag.data()),
              "Get GCM tag");
    EVP_CIPHER_CTX_free(ctx);

    // Assemble vault file
    std::vector<uint8_t> vault;
    vault.reserve(OFF_CIPHER + ciphertext.size());
    vault.insert(vault.end(), MAGIC.begin(), MAGIC.end());          // 4 bytes
    vault.push_back(VERSION);                                        // 1 byte
    vault.insert(vault.end(), salt.begin(), salt.end());            // 32 bytes
    vault.insert(vault.end(), iv.begin(),   iv.end());              // 12 bytes
    vault.insert(vault.end(), tag.begin(),  tag.end());             // 16 bytes
    vault.insert(vault.end(), ciphertext.begin(), ciphertext.end());// N bytes

    write_file(outfile, vault);
    std::cout << "Encrypted: " << outfile
              << "  (" << ciphertext.size() << " bytes ciphertext)\n";
    if (!label.empty())
        std::cout << "CUI label embedded: " << label << "\n";
}

// ── Decrypt ──────────────────────────────────────────────────────────────────

static void do_decrypt(const fs::path& infile, const fs::path& outfile)
{
    auto vault = read_file(infile);

    if (vault.size() < static_cast<size_t>(OFF_CIPHER))
        throw std::runtime_error("File too small to be a valid vault.");

    // Validate magic + version
    if (std::memcmp(vault.data() + OFF_MAGIC, MAGIC.data(), 4) != 0)
        throw std::runtime_error("Not an aes-vault file (bad magic).");
    if (vault[OFF_VERSION] != VERSION)
        throw std::runtime_error("Unsupported vault version.");

    // Extract header fields
    std::array<uint8_t, SALT_LEN> salt{};
    std::array<uint8_t, IV_LEN>   iv{};
    std::array<uint8_t, TAG_LEN>  tag{};
    std::memcpy(salt.data(), vault.data() + OFF_SALT, SALT_LEN);
    std::memcpy(iv.data(),   vault.data() + OFF_IV,   IV_LEN);
    std::memcpy(tag.data(),  vault.data() + OFF_TAG,  TAG_LEN);

    const uint8_t* cipher_ptr  = vault.data() + OFF_CIPHER;
    size_t         cipher_size = vault.size() - static_cast<size_t>(OFF_CIPHER);

    // Passphrase
    auto pass = read_passphrase("Passphrase: ");
    auto key  = derive_key(pass, salt);

    // AES-256-GCM decryption
    EVP_CIPHER_CTX* ctx = EVP_CIPHER_CTX_new();
    if (!ctx) throw std::runtime_error("EVP_CIPHER_CTX_new failed");

    ssl_check(EVP_DecryptInit_ex(ctx, EVP_aes_256_gcm(), nullptr, nullptr, nullptr),
              "DecryptInit");
    ssl_check(EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_SET_IVLEN, IV_LEN, nullptr),
              "Set IV length");
    ssl_check(EVP_DecryptInit_ex(ctx, nullptr, nullptr, key.data(), iv.data()),
              "DecryptInit key/iv");

    std::vector<uint8_t> plaintext(cipher_size);
    int out_len = 0;
    ssl_check(EVP_DecryptUpdate(ctx,
                                plaintext.data(), &out_len,
                                cipher_ptr, static_cast<int>(cipher_size)),
              "DecryptUpdate");

    // Set expected tag before finalising
    ssl_check(EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_SET_TAG, TAG_LEN,
                                  const_cast<uint8_t*>(tag.data())),
              "Set GCM tag");

    int final_len = 0;
    int rc = EVP_DecryptFinal_ex(ctx, plaintext.data() + out_len, &final_len);
    EVP_CIPHER_CTX_free(ctx);

    if (rc != 1)
        throw std::runtime_error(
            "Authentication FAILED — wrong passphrase or file is corrupt/tampered.");

    plaintext.resize(static_cast<size_t>(out_len + final_len));

    // Check for embedded CUI label (null-terminated string at start)
    size_t data_offset = 0;
    if (!plaintext.empty()) {
        void* nul = std::memchr(plaintext.data(), 0x00, plaintext.size());
        if (nul) {
            size_t label_end = static_cast<size_t>(
                static_cast<uint8_t*>(nul) - plaintext.data());
            // Heuristic: if everything before the NUL is printable ASCII it's a label
            bool looks_like_label = true;
            for (size_t i = 0; i < label_end; ++i) {
                char c = static_cast<char>(plaintext[i]);
                if (c < 0x20 || c > 0x7E) { looks_like_label = false; break; }
            }
            if (looks_like_label && label_end > 0 && label_end < 128) {
                std::string label(reinterpret_cast<char*>(plaintext.data()), label_end);
                std::cout << "CUI label: " << label << "\n";
                data_offset = label_end + 1;
            }
        }
    }

    std::vector<uint8_t> output(plaintext.begin() + static_cast<ptrdiff_t>(data_offset),
                                plaintext.end());
    write_file(outfile, output);
    std::cout << "Decrypted: " << outfile
              << "  (" << output.size() << " bytes)\n";
}

// ── main ─────────────────────────────────────────────────────────────────────

static void usage(const char* prog)
{
    std::cerr << "Usage:\n"
              << "  " << prog << " encrypt [--label <marking>] <infile> <outfile>\n"
              << "  " << prog << " decrypt <infile> <outfile>\n\n"
              << "Examples:\n"
              << "  " << prog << " encrypt --label \"CUI//SP-CTI\" report.pdf report.vault\n"
              << "  " << prog << " decrypt report.vault report_out.pdf\n";
}

int main(int argc, char* argv[])
{
    try {
        if (argc < 4) { usage(argv[0]); return 1; }

        std::string cmd   = argv[1];
        std::string label;
        int         argi  = 2;

        if (cmd == "encrypt") {
            if (std::string(argv[argi]) == "--label") {
                if (argi + 1 >= argc) {
                    std::cerr << "--label requires an argument\n"; return 1;
                }
                label = argv[argi + 1];
                argi += 2;
            }
            if (argi + 1 >= argc) { usage(argv[0]); return 1; }
            do_encrypt(argv[argi], argv[argi + 1], label);

        } else if (cmd == "decrypt") {
            if (argi + 1 >= argc) { usage(argv[0]); return 1; }
            do_decrypt(argv[argi], argv[argi + 1]);

        } else {
            std::cerr << "Unknown command: " << cmd << "\n";
            usage(argv[0]);
            return 1;
        }
    } catch (const std::exception& ex) {
        std::cerr << "Error: " << ex.what() << "\n";
        return 2;
    }
    return 0;
}
