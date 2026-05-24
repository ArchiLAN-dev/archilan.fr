ARG ARCHIPELAGO_VERSION=0.6.7

# Python 3.13 - matches the version bundled in Archipelago 0.6.7 (pyc magic 3571)
FROM python:3.13-slim

ARG ARCHIPELAGO_VERSION

RUN apt-get update && apt-get install -y --no-install-recommends \
    wget \
    tar \
    ca-certificates \
    gcc \
    && rm -rf /var/lib/apt/lists/*

# Binary - provides ArchipelagoGenerate and ArchipelagoServer (uses embedded Python 3.12)
RUN wget -qO /tmp/archipelago_bin.tar.gz \
    "https://github.com/ArchipelagoMW/Archipelago/releases/download/${ARCHIPELAGO_VERSION}/Archipelago_${ARCHIPELAGO_VERSION}_linux-x86_64.tar.gz" \
    && mkdir -p /app/Archipelago \
    && tar -xf /tmp/archipelago_bin.tar.gz -C /app/Archipelago/ \
    && rm /tmp/archipelago_bin.tar.gz

# Source tree - provides worlds/*.py files readable by system Python 3.13
RUN wget -qO /tmp/archipelago_src.tar.gz \
    "https://github.com/ArchipelagoMW/Archipelago/archive/refs/tags/${ARCHIPELAGO_VERSION}.tar.gz" \
    && tar -xf /tmp/archipelago_src.tar.gz -C /tmp/ \
    && mv "/tmp/Archipelago-${ARCHIPELAGO_VERSION}" /app/ArchipelagoSrc \
    && rm /tmp/archipelago_src.tar.gz

# Python dependencies used by the Archipelago framework, worlds, and Bridge.py
# AP source requirements - filter out GUI (kivy), git-hosted, and native-only packages
# that cannot install in a headless Linux container
RUN grep -vE '^\s*(kivy|cython|cymem|pyshortcuts|Pymem|.* @ git\+)' \
        /app/ArchipelagoSrc/requirements.txt \
    | pip install --no-cache-dir -r /dev/stdin

# Additional packages required by bridge.py and reachable.py
RUN pip install --no-cache-dir \
    aiohttp \
    websockets \
    boto3 \
    setuptools

COPY archipelago/generate_template.py /usr/local/bin/generate_template.py
RUN chmod +x /usr/local/bin/generate_template.py

COPY archipelago/introspect_options.py /usr/local/bin/introspect_options.py
RUN chmod +x /usr/local/bin/introspect_options.py

COPY archipelago/generate_multiworld.py /usr/local/bin/generate_multiworld.py
RUN chmod +x /usr/local/bin/generate_multiworld.py

COPY archipelago/ap_server.sh /ap_server.sh
RUN chmod +x /ap_server.sh

# Bridge - real-time observer service (copied from bridge/ at repo root)
COPY bridge/ /bridge/

# Headless reachability checker script
COPY archipelago/reachable.py /reachable/reachable.py

# Save state reader script (parses .apsave files using AP Python env)
COPY archipelago/read_save.py /readsave/read_save.py
RUN chmod +x /readsave/read_save.py

# Entrypoint: starts ArchipelagoServer + Bridge.py when run as a server container
COPY archipelago/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENV PATH="/app/Archipelago/Archipelago:${PATH}"

WORKDIR /workspace

# Default: server + bridge entrypoint (generate jobs override this with explicit command)
CMD ["/entrypoint.sh"]
