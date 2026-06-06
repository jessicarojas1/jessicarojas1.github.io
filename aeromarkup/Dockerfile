# AeroMarkup — production container
# Portable image for Render, AWS GovCloud (ECS/Fargate, EKS) and
# Azure Government (Container Apps, AKS). Single stateless web process;
# all state lives in PostgreSQL (RDS / Azure DB) + the client's IndexedDB.
FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PORT=8080 \
    AUTO_MIGRATE=1

WORKDIR /app

# Install deps first for better layer caching
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# App source
COPY server.py .
COPY db ./db
COPY static ./static

# Run as non-root (FedRAMP / DoD STIG friendly)
RUN useradd -m -u 10001 appuser && chown -R appuser /app
USER appuser

EXPOSE 8080

# Container-level healthcheck hits the readiness endpoint
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD python -c "import urllib.request,os; urllib.request.urlopen('http://127.0.0.1:'+os.environ.get('PORT','8080')+'/api/health').read()" || exit 1

CMD ["sh", "-c", "gunicorn server:app --bind 0.0.0.0:${PORT} --workers 2 --timeout 120"]
