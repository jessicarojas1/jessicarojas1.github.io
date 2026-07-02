# ============================================================================
#  Dockerfile — CPP Defense & Signal-Processing Tool Collection (BUILD image)
#  ---------------------------------------------------------------------------
#  These are 12 standalone C++ CLI tools, NOT a long-running web service.
#  This image is a *builder + runner* for batch/CLI use: it compiles every tool
#  and ships the binaries so you can `docker run ... <tool> <args>`.
#
#  Build:   docker build -t cpp-tools:latest .
#  Run:     docker run --rm cpp-tools:latest mil1553-sim
#           docker run --rm -v "$PWD:/data" cpp-tools:latest \
#                      cui-classifier /data --json
#
#  There is no daemon, no port, and no healthcheck endpoint — a HEALTHCHECK is
#  provided that simply confirms a representative tool executes.
# ============================================================================

# ---- Stage 1: build --------------------------------------------------------
FROM debian:12-slim AS build

RUN apt-get update && apt-get install -y --no-install-recommends \
        g++ \
        make \
        libssl-dev \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /src
COPY . /src

# Compile all 12 tools into /src/bin
RUN make -j"$(nproc)" all

# ---- Stage 2: runtime ------------------------------------------------------
# Only OpenSSL runtime is needed at run time (for aes-vault); everything else
# is statically satisfied by libstdc++/libc already present in the slim image.
FROM debian:12-slim AS runtime

RUN apt-get update && apt-get install -y --no-install-recommends \
        libssl3 \
        libstdc++6 \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --system --create-home --uid 10001 tools

COPY --from=build /src/bin/ /usr/local/bin/

USER tools
WORKDIR /home/tools

# Confirm the toolset is runnable (no network service to probe).
HEALTHCHECK --interval=1m --timeout=5s --retries=2 \
    CMD ["mil1553-sim"]

# Default: print the ARINC 429 demo. Override with any tool + args.
ENTRYPOINT []
CMD ["arinc429-decoder", "--demo"]
