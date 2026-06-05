# Sentinel QMS — developer convenience targets
# Run `make help` for the full list.

.DEFAULT_GOAL := help
COMPOSE := docker compose -f infra/docker-compose.yml

.PHONY: help up down logs build seed migrate test test-backend test-frontend lint fmt \
        backend-shell db-shell tf-aws-plan tf-azure-plan clean

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | \
	  awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

up: ## Start the full local stack (postgres + backend + frontend + minio)
	$(COMPOSE) up --build

down: ## Stop the local stack
	$(COMPOSE) down

logs: ## Tail logs from the local stack
	$(COMPOSE) logs -f

build: ## Build all container images
	$(COMPOSE) build

migrate: ## Apply database migrations inside the backend container
	$(COMPOSE) exec backend alembic upgrade head

seed: ## Seed reference data and demo records
	$(COMPOSE) exec backend python -m app.seed

test: test-backend test-frontend ## Run all tests

test-backend: ## Run backend unit/integration tests
	cd backend && pytest -q

test-frontend: ## Run frontend tests
	cd frontend && npm run test --silent

lint: ## Lint backend (ruff) and frontend (eslint)
	cd backend && ruff check .
	cd frontend && npm run lint

fmt: ## Format backend (ruff format) and frontend (prettier)
	cd backend && ruff format .
	cd frontend && npm run format

backend-shell: ## Open a shell in the backend container
	$(COMPOSE) exec backend /bin/sh

db-shell: ## Open a psql shell against the local database
	$(COMPOSE) exec db psql -U sentinel -d sentinel_qms

tf-aws-plan: ## Terraform plan for AWS GovCloud
	cd infra/terraform/aws-govcloud && terraform init && terraform plan

tf-azure-plan: ## Terraform plan for Azure Government
	cd infra/terraform/azure-gov && terraform init && terraform plan

clean: ## Remove local volumes and build artifacts
	$(COMPOSE) down -v
	rm -rf backend/.pytest_cache backend/.ruff_cache frontend/dist
