FROM python:3.10-slim

# Build context must be the repo root:
#   docker build -f bridge/Dockerfile .

WORKDIR /app

# Install runtime deps in a cacheable layer (invalidates only if requirements.txt changes)
COPY bridge/requirements.txt bridge/requirements.txt
RUN pip install --no-cache-dir -r bridge/requirements.txt

# Copy the bridge package
COPY bridge/ bridge/

ENV PYTHONPATH=/app

RUN useradd -m -u 1000 bridge && chown -R bridge:bridge /app
USER bridge

EXPOSE 5000

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
  CMD python -c "import urllib.request; urllib.request.urlopen('http://localhost:5000/health')"

CMD ["python", "-m", "bridge.bridge"]

