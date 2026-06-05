# AEGIS GRC — AWS GovCloud Deployment Guide

Production-grade, FedRAMP-aligned instructions for deploying AEGIS on AWS GovCloud
using ECS Fargate, RDS PostgreSQL 16, S3, and supporting services in the
`us-gov-west-1` or `us-gov-east-1` region.

---

## Table of Contents

1. [Prerequisites](#1-prerequisites)
2. [Architecture Overview](#2-architecture-overview)
3. [Networking — VPC](#3-networking--vpc)
4. [ECR — Container Registry](#4-ecr--container-registry)
5. [RDS PostgreSQL 16](#5-rds-postgresql-16)
6. [Secrets Manager](#6-secrets-manager)
7. [S3 for File Storage](#7-s3-for-file-storage)
8. [ECS Fargate — Task Definitions](#8-ecs-fargate--task-definitions)
9. [EFS for Uploads](#9-efs-for-uploads)
10. [Application Load Balancer](#10-application-load-balancer)
11. [ECS Services](#11-ecs-services)
12. [ACM Certificate](#12-acm-certificate)
13. [CloudWatch — Logs & Alarms](#13-cloudwatch--logs--alarms)
14. [IAM Roles & Policies](#14-iam-roles--policies)
15. [Database Migration](#15-database-migration)
16. [Environment Variable Reference](#16-environment-variable-reference)
17. [FedRAMP / Security Hardening](#17-fedramp--security-hardening)
18. [Cost Estimate](#18-cost-estimate)
19. [Maintenance & Updates](#19-maintenance--updates)

---

## 1. Prerequisites

### AWS Account

- An active **AWS GovCloud (US)** account. GovCloud is a separate AWS partition
  (`aws-us-gov`) from commercial AWS and requires a linked commercial account for
  initial signup.
- Ensure your account is scoped to the appropriate clearance level:
  - **IL2** — Controlled Unclassified Information (CUI), standard FedRAMP Moderate
  - **IL4** — DoD CUI requiring DISA IL4 authorization
  - **IL5** — National Security Systems (NSS)
- Confirm the desired region (`us-gov-west-1` or `us-gov-east-1`) is enabled for
  the services below. `us-gov-west-1` has the broadest service coverage.

### AWS CLI v2 for GovCloud

```bash
# Install or upgrade to AWS CLI v2
curl "https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip" -o "awscliv2.zip"
unzip awscliv2.zip && sudo ./aws/install

# Configure a named profile for GovCloud
aws configure --profile govcloud
# AWS Access Key ID: <GovCloud IAM key>
# AWS Secret Access Key: <GovCloud IAM secret>
# Default region name: us-gov-west-1
# Default output format: json

# Verify the partition
aws sts get-caller-identity --profile govcloud
# The Account field should start with a GovCloud account number.
```

> **Note:** GovCloud service endpoints use the `aws-us-gov` partition, not `aws`.
> For example, ECR is at `ecr.us-gov-west-1.amazonaws.com`, not
> `ecr.us-east-1.amazonaws.com`. All AWS CLI commands below assume the
> `--profile govcloud` flag or a shell export:
>
> ```bash
> export AWS_PROFILE=govcloud
> export AWS_REGION=us-gov-west-1
> export ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
> ```

### Required IAM Permissions (Deployer)

The IAM identity running these commands needs the following managed policies or
equivalent custom policies:

- `AmazonEC2FullAccess` (VPC, subnets, security groups, ALB, EFS)
- `AmazonECS_FullAccess`
- `AmazonRDSFullAccess`
- `AmazonS3FullAccess`
- `AmazonECR_FullAccess` (Note: GovCloud policy name may be `EC2ContainerRegistryFullAccess`)
- `SecretsManagerReadWrite`
- `IAMFullAccess` (to create task roles)
- `CloudWatchFullAccess`
- `AWSCertificateManagerFullAccess`

In production, scope these down to the specific resources after initial setup.

### Required Tools

| Tool | Version | Notes |
|------|---------|-------|
| Docker | 24+ | Build and push images |
| AWS CLI | v2.x | GovCloud endpoint support |
| jq | 1.6+ | Parse JSON responses |
| Terraform | 1.7+ | Optional — IaC alternative to CLI commands |
| psql | 16 | Optional — direct DB access via bastion/SSM |

---

## 2. Architecture Overview

```
Internet (HTTPS only)
        │
        ▼
┌───────────────────────────────────────────────────────┐
│  Public Subnets (3 AZs)                               │
│  ┌─────────────────────────────────────────────────┐  │
│  │  Application Load Balancer (443/TLS → 80)       │  │
│  │  WAF — AWSManagedRulesCommonRuleSet             │  │
│  └──────────────────┬──────────────────────────────┘  │
└─────────────────────│──────────────────────────────────┘
                      │
┌─────────────────────▼──────────────────────────────────┐
│  Private Subnets (3 AZs)                               │
│                                                        │
│  ┌──────────────────────────────────────────────────┐  │
│  │  ECS Fargate — aegis-app service (desired: 2)    │  │
│  │    ┌────────────┐   ┌──────────────────────────┐ │  │
│  │    │   nginx    │   │  app (PHP 8.3 / Apache)  │ │  │
│  │    │  :80 proxy │──▶│       :80                │ │  │
│  │    └────────────┘   └──────────────────────────┘ │  │
│  └──────────────────────────────────────────────────┘  │
│                                                        │
│  ┌──────────────────────────────────────────────────┐  │
│  │  ECS Fargate — aegis-cron service (desired: 1)   │  │
│  │    ┌──────────────────────────────────────────┐  │  │
│  │    │  app (PHP) — workflow/webhook loop       │  │  │
│  │    └──────────────────────────────────────────┘  │  │
│  └──────────────────────────────────────────────────┘  │
│                                                        │
│  ┌─────────────────┐  ┌──────────────────────────────┐ │
│  │  RDS PostgreSQL │  │  EFS (uploads / documents)   │ │
│  │  16 — Multi-AZ  │  │  encrypted, mount :2049      │ │
│  └─────────────────┘  └──────────────────────────────┘ │
└────────────────────────────────────────────────────────┘

Supporting Services (GovCloud region):
  S3          — STORAGE_DRIVER=s3 file storage (SSE-KMS, versioning)
  Secrets Mgr — All sensitive env vars
  CloudWatch  — Logs (/aegis/app, /aegis/cron), alarms, dashboard
  ECR         — Container image registry
  ACM         — TLS certificate
  GuardDuty   — Threat detection
  Security Hub — FedRAMP control baseline
  CloudTrail  — API audit log → S3
  Config      — Continuous compliance rules
  WAF v2      — ALB web application firewall
```

---

## 3. Networking — VPC

### Create the VPC

```bash
# Create VPC
VPC_ID=$(aws ec2 create-vpc \
  --cidr-block 10.0.0.0/16 \
  --tag-specifications 'ResourceType=vpc,Tags=[{Key=Name,Value=aegis-vpc},{Key=Project,Value=AEGIS}]' \
  --query 'Vpc.VpcId' --output text)

# Enable DNS hostnames (required for RDS, EFS)
aws ec2 modify-vpc-attribute --vpc-id $VPC_ID --enable-dns-hostnames
aws ec2 modify-vpc-attribute --vpc-id $VPC_ID --enable-dns-support

echo "VPC: $VPC_ID"
```

### Public Subnets (ALB)

```bash
# Adjust AZ names for us-gov-east-1 if needed (use us-gov-east-1a/b/c)
PUB_SUB_A=$(aws ec2 create-subnet --vpc-id $VPC_ID \
  --cidr-block 10.0.0.0/24 --availability-zone us-gov-west-1a \
  --tag-specifications 'ResourceType=subnet,Tags=[{Key=Name,Value=aegis-pub-a}]' \
  --query 'Subnet.SubnetId' --output text)

PUB_SUB_B=$(aws ec2 create-subnet --vpc-id $VPC_ID \
  --cidr-block 10.0.1.0/24 --availability-zone us-gov-west-1b \
  --tag-specifications 'ResourceType=subnet,Tags=[{Key=Name,Value=aegis-pub-b}]' \
  --query 'Subnet.SubnetId' --output text)

PUB_SUB_C=$(aws ec2 create-subnet --vpc-id $VPC_ID \
  --cidr-block 10.0.2.0/24 --availability-zone us-gov-west-1c \
  --tag-specifications 'ResourceType=subnet,Tags=[{Key=Name,Value=aegis-pub-c}]' \
  --query 'Subnet.SubnetId' --output text)
```

### Private Subnets (ECS + RDS)

```bash
PRIV_SUB_A=$(aws ec2 create-subnet --vpc-id $VPC_ID \
  --cidr-block 10.0.10.0/24 --availability-zone us-gov-west-1a \
  --tag-specifications 'ResourceType=subnet,Tags=[{Key=Name,Value=aegis-priv-a}]' \
  --query 'Subnet.SubnetId' --output text)

PRIV_SUB_B=$(aws ec2 create-subnet --vpc-id $VPC_ID \
  --cidr-block 10.0.11.0/24 --availability-zone us-gov-west-1b \
  --tag-specifications 'ResourceType=subnet,Tags=[{Key=Name,Value=aegis-priv-b}]' \
  --query 'Subnet.SubnetId' --output text)

PRIV_SUB_C=$(aws ec2 create-subnet --vpc-id $VPC_ID \
  --cidr-block 10.0.12.0/24 --availability-zone us-gov-west-1c \
  --tag-specifications 'ResourceType=subnet,Tags=[{Key=Name,Value=aegis-priv-c}]' \
  --query 'Subnet.SubnetId' --output text)
```

### Internet Gateway & NAT Gateway

```bash
# Internet Gateway for public subnets
IGW_ID=$(aws ec2 create-internet-gateway \
  --tag-specifications 'ResourceType=internet-gateway,Tags=[{Key=Name,Value=aegis-igw}]' \
  --query 'InternetGateway.InternetGatewayId' --output text)
aws ec2 attach-internet-gateway --internet-gateway-id $IGW_ID --vpc-id $VPC_ID

# Allocate an Elastic IP and create a NAT Gateway in the first public subnet
EIP_ALLOC=$(aws ec2 allocate-address --domain vpc --query 'AllocationId' --output text)
NAT_GW=$(aws ec2 create-nat-gateway \
  --subnet-id $PUB_SUB_A \
  --allocation-id $EIP_ALLOC \
  --tag-specifications 'ResourceType=natgateway,Tags=[{Key=Name,Value=aegis-nat}]' \
  --query 'NatGateway.NatGatewayId' --output text)
echo "Waiting for NAT Gateway..."
aws ec2 wait nat-gateway-available --nat-gateway-ids $NAT_GW
```

### Route Tables

```bash
# Public route table → IGW
PUB_RT=$(aws ec2 create-route-table --vpc-id $VPC_ID \
  --tag-specifications 'ResourceType=route-table,Tags=[{Key=Name,Value=aegis-pub-rt}]' \
  --query 'RouteTable.RouteTableId' --output text)
aws ec2 create-route --route-table-id $PUB_RT --destination-cidr-block 0.0.0.0/0 --gateway-id $IGW_ID
for sub in $PUB_SUB_A $PUB_SUB_B $PUB_SUB_C; do
  aws ec2 associate-route-table --route-table-id $PUB_RT --subnet-id $sub
done

# Private route table → NAT GW
PRIV_RT=$(aws ec2 create-route-table --vpc-id $VPC_ID \
  --tag-specifications 'ResourceType=route-table,Tags=[{Key=Name,Value=aegis-priv-rt}]' \
  --query 'RouteTable.RouteTableId' --output text)
aws ec2 create-route --route-table-id $PRIV_RT --destination-cidr-block 0.0.0.0/0 --nat-gateway-id $NAT_GW
for sub in $PRIV_SUB_A $PRIV_SUB_B $PRIV_SUB_C; do
  aws ec2 associate-route-table --route-table-id $PRIV_RT --subnet-id $sub
done
```

### Security Groups

```bash
# ALB — inbound HTTPS from Internet
ALB_SG=$(aws ec2 create-security-group \
  --group-name aegis-alb-sg --description "AEGIS ALB" \
  --vpc-id $VPC_ID --query 'GroupId' --output text)
aws ec2 authorize-security-group-ingress --group-id $ALB_SG \
  --ip-permissions '[{"IpProtocol":"tcp","FromPort":443,"ToPort":443,"IpRanges":[{"CidrIp":"0.0.0.0/0"}]},
                    {"IpProtocol":"tcp","FromPort":80,"ToPort":80,"IpRanges":[{"CidrIp":"0.0.0.0/0"}]}]'

# App — inbound port 80 from ALB only
APP_SG=$(aws ec2 create-security-group \
  --group-name aegis-app-sg --description "AEGIS App" \
  --vpc-id $VPC_ID --query 'GroupId' --output text)
aws ec2 authorize-security-group-ingress --group-id $APP_SG \
  --protocol tcp --port 80 --source-group $ALB_SG

# DB — inbound 5432 from App SG only
DB_SG=$(aws ec2 create-security-group \
  --group-name aegis-db-sg --description "AEGIS RDS" \
  --vpc-id $VPC_ID --query 'GroupId' --output text)
aws ec2 authorize-security-group-ingress --group-id $DB_SG \
  --protocol tcp --port 5432 --source-group $APP_SG

# EFS — inbound 2049 from App SG only
EFS_SG=$(aws ec2 create-security-group \
  --group-name aegis-efs-sg --description "AEGIS EFS" \
  --vpc-id $VPC_ID --query 'GroupId' --output text)
aws ec2 authorize-security-group-ingress --group-id $EFS_SG \
  --protocol tcp --port 2049 --source-group $APP_SG

echo "ALB SG: $ALB_SG  APP SG: $APP_SG  DB SG: $DB_SG  EFS SG: $EFS_SG"
```

### VPC Flow Logs

```bash
# Create a CloudWatch log group for flow logs first (see Section 13)
aws ec2 create-flow-logs \
  --resource-type VPC \
  --resource-ids $VPC_ID \
  --traffic-type ALL \
  --log-destination-type cloud-watch-logs \
  --log-group-name /aegis/vpc-flow-logs \
  --deliver-logs-permission-arn arn:aws-us-gov:iam::${ACCOUNT_ID}:role/flowlogsRole
```

> **Note:** The `arn:aws-us-gov:` prefix is mandatory for all GovCloud ARNs.
> Commercial ARNs (`arn:aws:`) will be rejected.

---

## 4. ECR — Container Registry

### Create the Repository

```bash
aws ecr create-repository \
  --repository-name aegis \
  --image-scanning-configuration scanOnPush=true \
  --image-tag-mutability IMMUTABLE \
  --encryption-configuration encryptionType=KMS \
  --region us-gov-west-1

# (Optional) Enable enhanced scanning
aws ecr put-registry-scanning-configuration \
  --scan-type ENHANCED \
  --rules '[{"repositoryFilters":[{"filter":"aegis","filterType":"WILDCARD"}],"scanFrequency":"CONTINUOUS_SCAN"}]'
```

### Build and Push the Image

```bash
# Authenticate Docker to the GovCloud ECR endpoint
aws ecr get-login-password --region us-gov-west-1 \
  | docker login --username AWS --password-stdin \
    ${ACCOUNT_ID}.dkr.ecr.us-gov-west-1.amazonaws.com

# Build from the repository root (where Dockerfile lives)
docker build -t aegis .

# Tag with both a version and latest
VERSION=$(git rev-parse --short HEAD)
docker tag aegis:latest ${ACCOUNT_ID}.dkr.ecr.us-gov-west-1.amazonaws.com/aegis:${VERSION}
docker tag aegis:latest ${ACCOUNT_ID}.dkr.ecr.us-gov-west-1.amazonaws.com/aegis:latest

# Push both tags
docker push ${ACCOUNT_ID}.dkr.ecr.us-gov-west-1.amazonaws.com/aegis:${VERSION}
docker push ${ACCOUNT_ID}.dkr.ecr.us-gov-west-1.amazonaws.com/aegis:latest
```

> **Note:** Because `IMMUTABLE` tags are enabled, re-pushing `latest` will fail
> after the first push. Use git-SHA tags for every deployment and update the ECS
> task definition to reference the new tag. This enforces a strict audit trail of
> deployed versions.

### ECR Lifecycle Policy (optional — keep last 30 images)

```bash
aws ecr put-lifecycle-policy \
  --repository-name aegis \
  --lifecycle-policy-text '{
    "rules": [{
      "rulePriority": 1,
      "description": "Keep last 30 images",
      "selection": {
        "tagStatus": "any",
        "countType": "imageCountMoreThan",
        "countNumber": 30
      },
      "action": {"type": "expire"}
    }]
  }'
```

---

## 5. RDS PostgreSQL 16

### KMS Key for RDS Encryption

```bash
RDS_KMS_KEY=$(aws kms create-key \
  --description "AEGIS RDS encryption key" \
  --key-usage ENCRYPT_DECRYPT \
  --origin AWS_KMS \
  --query 'KeyMetadata.KeyId' --output text)

aws kms create-alias \
  --alias-name alias/aegis-rds \
  --target-key-id $RDS_KMS_KEY
```

### DB Subnet Group

```bash
aws rds create-db-subnet-group \
  --db-subnet-group-name aegis-db-subnet-group \
  --db-subnet-group-description "AEGIS private subnets" \
  --subnet-ids $PRIV_SUB_A $PRIV_SUB_B $PRIV_SUB_C
```

### Parameter Group — Enforce SSL

```bash
aws rds create-db-cluster-parameter-group \
  --db-cluster-parameter-group-name aegis-pg16 \
  --db-parameter-group-family postgres16 \
  --description "AEGIS PostgreSQL 16 — SSL enforced"

# Not applicable for non-Aurora; use instance parameter group:
aws rds create-db-parameter-group \
  --db-parameter-group-name aegis-pg16-params \
  --db-parameter-group-family postgres16 \
  --description "AEGIS PostgreSQL 16 — SSL enforced"

aws rds modify-db-parameter-group \
  --db-parameter-group-name aegis-pg16-params \
  --parameters 'ParameterName=rds.force_ssl,ParameterValue=1,ApplyMethod=immediate'
```

### Create the RDS Instance

```bash
aws rds create-db-instance \
  --db-instance-identifier aegis-db \
  --db-instance-class db.r6g.large \
  --engine postgres \
  --engine-version 16 \
  --master-username aegis_admin \
  --master-user-password "$(openssl rand -base64 32)" \
  --db-name aegis \
  --db-subnet-group-name aegis-db-subnet-group \
  --vpc-security-group-ids $DB_SG \
  --db-parameter-group-name aegis-pg16-params \
  --storage-type gp3 \
  --allocated-storage 100 \
  --max-allocated-storage 500 \
  --storage-encrypted \
  --kms-key-id $RDS_KMS_KEY \
  --multi-az \
  --backup-retention-period 35 \
  --preferred-backup-window "03:00-04:00" \
  --preferred-maintenance-window "sun:05:00-sun:06:00" \
  --no-publicly-accessible \
  --deletion-protection \
  --enable-performance-insights \
  --performance-insights-kms-key-id $RDS_KMS_KEY \
  --performance-insights-retention-period 731 \
  --enable-cloudwatch-logs-exports '["postgresql","upgrade"]' \
  --tags Key=Project,Value=AEGIS Key=Environment,Value=production

# Wait for availability (~10–15 min)
aws rds wait db-instance-available --db-instance-identifier aegis-db

# Retrieve the endpoint
RDS_ENDPOINT=$(aws rds describe-db-instances \
  --db-instance-identifier aegis-db \
  --query 'DBInstances[0].Endpoint.Address' --output text)
echo "RDS endpoint: $RDS_ENDPOINT"
```

> **Note:** `db.r6g.large` (2 vCPU, 16 GB RAM) is the minimum recommended class
> for a production GRC workload. Scale up to `db.r6g.xlarge` or `db.r6g.2xlarge`
> as the compliance control library grows.

---

## 6. Secrets Manager

Store all sensitive configuration values in AWS Secrets Manager. ECS Fargate
retrieves them at task startup — they are never baked into task definitions or
Docker images.

### Create Secrets

```bash
# Database credentials
aws secretsmanager create-secret \
  --name aegis/db \
  --description "AEGIS database credentials" \
  --secret-string "{\"DB_HOST\":\"${RDS_ENDPOINT}\",\"DB_PORT\":\"5432\",\"DB_NAME\":\"aegis\",\"DB_USER\":\"aegis_admin\",\"DB_PASS\":\"<YOUR_DB_PASSWORD>\"}"

# JWT secret (generate a strong random value)
aws secretsmanager create-secret \
  --name aegis/jwt \
  --description "AEGIS JWT signing secret" \
  --secret-string "{\"JWT_SECRET\":\"$(openssl rand -hex 64)\"}"

# SMTP credentials
aws secretsmanager create-secret \
  --name aegis/smtp \
  --description "AEGIS SMTP credentials" \
  --secret-string "{\"SMTP_HOST\":\"email-smtp.us-gov-west-1.amazonaws.com\",\"SMTP_PORT\":\"587\",\"SMTP_USER\":\"<SES_SMTP_USER>\",\"SMTP_PASS\":\"<SES_SMTP_PASS>\",\"SMTP_FROM\":\"no-reply@your-agency.gov\"}"

# AI API key (if using AI features)
aws secretsmanager create-secret \
  --name aegis/ai \
  --description "AEGIS AI API key" \
  --secret-string "{\"AI_API_KEY\":\"<YOUR_AI_API_KEY>\"}"
```

> **Note:** AWS SES is available in GovCloud. If your agency uses an on-premises
> mail relay, substitute the SMTP_HOST with your relay address and open the
> appropriate egress port in the App security group.

### Referencing Secrets in ECS Task Definitions

ECS supports two methods for injecting secrets:

1. **`secrets` array** — ECS pulls the value and injects it as an environment
   variable. Use `valueFrom` pointing to the secret ARN or ARN with JSON key.
2. **`environment` array** — plain-text values only; do not use for sensitive data.

Example `secrets` block in a task definition container:

```json
"secrets": [
  {
    "name": "DB_HOST",
    "valueFrom": "arn:aws-us-gov:secretsmanager:us-gov-west-1:ACCOUNT:secret:aegis/db:DB_HOST::"
  },
  {
    "name": "DB_PASS",
    "valueFrom": "arn:aws-us-gov:secretsmanager:us-gov-west-1:ACCOUNT:secret:aegis/db:DB_PASS::"
  },
  {
    "name": "JWT_SECRET",
    "valueFrom": "arn:aws-us-gov:secretsmanager:us-gov-west-1:ACCOUNT:secret:aegis/jwt:JWT_SECRET::"
  }
]
```

The task execution role (Section 14) must have `secretsmanager:GetSecretValue`
permission on these secret ARNs.

---

## 7. S3 for File Storage

### KMS Key for S3

```bash
S3_KMS_KEY=$(aws kms create-key \
  --description "AEGIS S3 encryption key" \
  --key-usage ENCRYPT_DECRYPT \
  --origin AWS_KMS \
  --query 'KeyMetadata.KeyId' --output text)

aws kms create-alias \
  --alias-name alias/aegis-s3 \
  --target-key-id $S3_KMS_KEY
```

### Create the Bucket

```bash
BUCKET_NAME="aegis-uploads-${ACCOUNT_ID}-us-gov-west-1"

aws s3api create-bucket \
  --bucket $BUCKET_NAME \
  --region us-gov-west-1 \
  --create-bucket-configuration LocationConstraint=us-gov-west-1

# Block all public access
aws s3api put-public-access-block \
  --bucket $BUCKET_NAME \
  --public-access-block-configuration \
    BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true

# Enable versioning
aws s3api put-bucket-versioning \
  --bucket $BUCKET_NAME \
  --versioning-configuration Status=Enabled

# Enable SSE-KMS encryption
aws s3api put-bucket-encryption \
  --bucket $BUCKET_NAME \
  --server-side-encryption-configuration "{
    \"Rules\": [{
      \"ApplyServerSideEncryptionByDefault\": {
        \"SSEAlgorithm\": \"aws:kms\",
        \"KMSMasterKeyID\": \"${S3_KMS_KEY}\"
      },
      \"BucketKeyEnabled\": true
    }]
  }"

# Enforce HTTPS-only bucket policy
aws s3api put-bucket-policy \
  --bucket $BUCKET_NAME \
  --policy "{
    \"Version\": \"2012-10-17\",
    \"Statement\": [{
      \"Sid\": \"DenyNonHTTPS\",
      \"Effect\": \"Deny\",
      \"Principal\": \"*\",
      \"Action\": \"s3:*\",
      \"Resource\": [
        \"arn:aws-us-gov:s3:::${BUCKET_NAME}\",
        \"arn:aws-us-gov:s3:::${BUCKET_NAME}/*\"
      ],
      \"Condition\": {
        \"Bool\": { \"aws:SecureTransport\": \"false\" }
      }
    }]
  }"

echo "S3 bucket: $BUCKET_NAME"
```

### CORS Configuration (if browser uploads are used)

```bash
aws s3api put-bucket-cors \
  --bucket $BUCKET_NAME \
  --cors-configuration '{
    "CORSRules": [{
      "AllowedHeaders": ["*"],
      "AllowedMethods": ["GET","PUT","POST","DELETE","HEAD"],
      "AllowedOrigins": ["https://your-agency-domain.gov"],
      "ExposeHeaders": ["ETag"],
      "MaxAgeSeconds": 3000
    }]
  }'
```

### AEGIS Environment Variables for S3

Set these in the ECS task definition's plain `environment` array (not secrets):

```json
{ "name": "STORAGE_DRIVER", "value": "s3" },
{ "name": "S3_BUCKET",      "value": "aegis-uploads-ACCOUNT-us-gov-west-1" },
{ "name": "S3_REGION",      "value": "us-gov-west-1" }
```

---

## 8. ECS Fargate — Task Definitions

### ECS Cluster

```bash
aws ecs create-cluster \
  --cluster-name aegis \
  --capacity-providers FARGATE FARGATE_SPOT \
  --default-capacity-provider-strategy \
    capacityProvider=FARGATE,weight=1,base=1 \
  --settings name=containerInsights,value=enabled \
  --tags key=Project,value=AEGIS
```

### Task Definition 1 — aegis-app (nginx sidecar + PHP/Apache)

Save as `task-def-app.json`. Replace `ACCOUNT_ID` with your GovCloud account number.

```json
{
  "family": "aegis-app",
  "networkMode": "awsvpc",
  "requiresCompatibilities": ["FARGATE"],
  "cpu": "1024",
  "memory": "2048",
  "executionRoleArn": "arn:aws-us-gov:iam::ACCOUNT_ID:role/aegis-ecs-execution-role",
  "taskRoleArn":      "arn:aws-us-gov:iam::ACCOUNT_ID:role/aegis-ecs-task-role",
  "volumes": [
    {
      "name": "uploads",
      "efsVolumeConfiguration": {
        "fileSystemId": "fs-XXXXXXXX",
        "rootDirectory": "/uploads",
        "transitEncryption": "ENABLED",
        "authorizationConfig": {
          "accessPointId": "fsap-XXXXXXXX",
          "iam": "ENABLED"
        }
      }
    }
  ],
  "containerDefinitions": [
    {
      "name": "nginx",
      "image": "nginx:alpine",
      "essential": true,
      "portMappings": [
        { "containerPort": 80, "protocol": "tcp" }
      ],
      "dependsOn": [
        { "containerName": "app", "condition": "HEALTHY" }
      ],
      "logConfiguration": {
        "logDriver": "awslogs",
        "options": {
          "awslogs-group":         "/aegis/app",
          "awslogs-region":        "us-gov-west-1",
          "awslogs-stream-prefix": "nginx"
        }
      },
      "healthCheck": {
        "command": ["CMD-SHELL", "wget -qO- http://localhost/health || exit 1"],
        "interval": 30,
        "timeout": 10,
        "retries": 3,
        "startPeriod": 60
      },
      "mountPoints": []
    },
    {
      "name": "app",
      "image": "ACCOUNT_ID.dkr.ecr.us-gov-west-1.amazonaws.com/aegis:GIT_SHA",
      "essential": true,
      "portMappings": [
        { "containerPort": 80, "protocol": "tcp" }
      ],
      "environment": [
        { "name": "APP_ENV",        "value": "production" },
        { "name": "APP_URL",        "value": "https://your-agency-domain.gov" },
        { "name": "DB_PORT",        "value": "5432" },
        { "name": "DB_NAME",        "value": "aegis" },
        { "name": "STORAGE_DRIVER", "value": "s3" },
        { "name": "S3_BUCKET",      "value": "aegis-uploads-ACCOUNT_ID-us-gov-west-1" },
        { "name": "S3_REGION",      "value": "us-gov-west-1" },
        { "name": "SMTP_PORT",      "value": "587" }
      ],
      "secrets": [
        { "name": "DB_HOST",    "valueFrom": "arn:aws-us-gov:secretsmanager:us-gov-west-1:ACCOUNT_ID:secret:aegis/db:DB_HOST::" },
        { "name": "DB_USER",    "valueFrom": "arn:aws-us-gov:secretsmanager:us-gov-west-1:ACCOUNT_ID:secret:aegis/db:DB_USER::" },
        { "name": "DB_PASS",    "valueFrom": "arn:aws-us-gov:secretsmanager:us-gov-west-1:ACCOUNT_ID:secret:aegis/db:DB_PASS::" },
        { "name": "JWT_SECRET", "valueFrom": "arn:aws-us-gov:secretsmanager:us-gov-west-1:ACCOUNT_ID:secret:aegis/jwt:JWT_SECRET::" },
        { "name": "SMTP_HOST",  "valueFrom": "arn:aws-us-gov:secretsmanager:us-gov-west-1:ACCOUNT_ID:secret:aegis/smtp:SMTP_HOST::" },
        { "name": "SMTP_USER",  "valueFrom": "arn:aws-us-gov:secretsmanager:us-gov-west-1:ACCOUNT_ID:secret:aegis/smtp:SMTP_USER::" },
        { "name": "SMTP_PASS",  "valueFrom": "arn:aws-us-gov:secretsmanager:us-gov-west-1:ACCOUNT_ID:secret:aegis/smtp:SMTP_PASS::" },
        { "name": "SMTP_FROM",  "valueFrom": "arn:aws-us-gov:secretsmanager:us-gov-west-1:ACCOUNT_ID:secret:aegis/smtp:SMTP_FROM::" },
        { "name": "AI_API_KEY", "valueFrom": "arn:aws-us-gov:secretsmanager:us-gov-west-1:ACCOUNT_ID:secret:aegis/ai:AI_API_KEY::" }
      ],
      "mountPoints": [
        {
          "sourceVolume": "uploads",
          "containerPath": "/var/www/html/uploads",
          "readOnly": false
        }
      ],
      "logConfiguration": {
        "logDriver": "awslogs",
        "options": {
          "awslogs-group":         "/aegis/app",
          "awslogs-region":        "us-gov-west-1",
          "awslogs-stream-prefix": "app"
        }
      },
      "healthCheck": {
        "command": ["CMD-SHELL", "curl -f http://localhost/health || exit 1"],
        "interval": 30,
        "timeout": 10,
        "retries": 3,
        "startPeriod": 60
      }
    }
  ]
}
```

> **Note:** The nginx container in this sidecar pattern handles the `/health`
> endpoint check from the ALB. The nginx config at `docker/nginx.conf` proxies
> all traffic to the app container on port 80. If you prefer a single-container
> approach (app only, no nginx sidecar), point the target group directly at port
> 80 of the app container — the Apache/PHP container exposes port 80 natively.

Register the task definition:

```bash
aws ecs register-task-definition --cli-input-json file://task-def-app.json
```

### Task Definition 2 — aegis-cron

Save as `task-def-cron.json`:

```json
{
  "family": "aegis-cron",
  "networkMode": "awsvpc",
  "requiresCompatibilities": ["FARGATE"],
  "cpu": "512",
  "memory": "1024",
  "executionRoleArn": "arn:aws-us-gov:iam::ACCOUNT_ID:role/aegis-ecs-execution-role",
  "taskRoleArn":      "arn:aws-us-gov:iam::ACCOUNT_ID:role/aegis-ecs-task-role",
  "volumes": [
    {
      "name": "uploads",
      "efsVolumeConfiguration": {
        "fileSystemId": "fs-XXXXXXXX",
        "rootDirectory": "/uploads",
        "transitEncryption": "ENABLED",
        "authorizationConfig": {
          "accessPointId": "fsap-XXXXXXXX",
          "iam": "ENABLED"
        }
      }
    }
  ],
  "containerDefinitions": [
    {
      "name": "cron",
      "image": "ACCOUNT_ID.dkr.ecr.us-gov-west-1.amazonaws.com/aegis:GIT_SHA",
      "essential": true,
      "command": [
        "/bin/sh", "-c",
        "while true; do php /var/www/html/scripts/run_workflows.php >> /proc/1/fd/1 2>&1; php /var/www/html/scripts/dispatch_webhooks.php >> /proc/1/fd/1 2>&1; php /var/www/html/scripts/send_notifications.php >> /proc/1/fd/1 2>&1; sleep 60; done"
      ],
      "environment": [
        { "name": "APP_ENV",        "value": "production" },
        { "name": "APP_URL",        "value": "https://your-agency-domain.gov" },
        { "name": "DB_PORT",        "value": "5432" },
        { "name": "DB_NAME",        "value": "aegis" },
        { "name": "STORAGE_DRIVER", "value": "s3" },
        { "name": "S3_BUCKET",      "value": "aegis-uploads-ACCOUNT_ID-us-gov-west-1" },
        { "name": "S3_REGION",      "value": "us-gov-west-1" }
      ],
      "secrets": [
        { "name": "DB_HOST",    "valueFrom": "arn:aws-us-gov:secretsmanager:us-gov-west-1:ACCOUNT_ID:secret:aegis/db:DB_HOST::" },
        { "name": "DB_USER",    "valueFrom": "arn:aws-us-gov:secretsmanager:us-gov-west-1:ACCOUNT_ID:secret:aegis/db:DB_USER::" },
        { "name": "DB_PASS",    "valueFrom": "arn:aws-us-gov:secretsmanager:us-gov-west-1:ACCOUNT_ID:secret:aegis/db:DB_PASS::" },
        { "name": "JWT_SECRET", "valueFrom": "arn:aws-us-gov:secretsmanager:us-gov-west-1:ACCOUNT_ID:secret:aegis/jwt:JWT_SECRET::" },
        { "name": "SMTP_HOST",  "valueFrom": "arn:aws-us-gov:secretsmanager:us-gov-west-1:ACCOUNT_ID:secret:aegis/smtp:SMTP_HOST::" },
        { "name": "SMTP_USER",  "valueFrom": "arn:aws-us-gov:secretsmanager:us-gov-west-1:ACCOUNT_ID:secret:aegis/smtp:SMTP_USER::" },
        { "name": "SMTP_PASS",  "valueFrom": "arn:aws-us-gov:secretsmanager:us-gov-west-1:ACCOUNT_ID:secret:aegis/smtp:SMTP_PASS::" },
        { "name": "AI_API_KEY", "valueFrom": "arn:aws-us-gov:secretsmanager:us-gov-west-1:ACCOUNT_ID:secret:aegis/ai:AI_API_KEY::" }
      ],
      "mountPoints": [
        {
          "sourceVolume": "uploads",
          "containerPath": "/var/www/html/uploads",
          "readOnly": false
        }
      ],
      "logConfiguration": {
        "logDriver": "awslogs",
        "options": {
          "awslogs-group":         "/aegis/cron",
          "awslogs-region":        "us-gov-west-1",
          "awslogs-stream-prefix": "cron"
        }
      },
      "healthCheck": {
        "command": ["CMD-SHELL", "ps aux | grep '[p]hp' || exit 1"],
        "interval": 60,
        "timeout": 10,
        "retries": 3,
        "startPeriod": 30
      }
    }
  ]
}
```

> **Note:** Cron output is redirected to `/proc/1/fd/1` so CloudWatch Logs picks
> it up via the `awslogs` driver. The startup.sh entrypoint is not used here —
> the `command` override runs the cron loop directly, bypassing the install/migrate
> step that runs on the app containers.

```bash
aws ecs register-task-definition --cli-input-json file://task-def-cron.json
```

---

## 9. EFS for Uploads

> **Note:** EFS is used for the `uploads/` directory to allow multiple Fargate
> tasks to share the same filesystem. If `STORAGE_DRIVER=s3` is set, file uploads
> go directly to S3 and EFS is only needed for temporary processing. You may omit
> EFS entirely when using S3 storage.

### Create the EFS Filesystem

```bash
# KMS key for EFS encryption
EFS_KMS_KEY=$(aws kms create-key \
  --description "AEGIS EFS encryption key" \
  --key-usage ENCRYPT_DECRYPT \
  --origin AWS_KMS \
  --query 'KeyMetadata.KeyId' --output text)

aws kms create-alias --alias-name alias/aegis-efs --target-key-id $EFS_KMS_KEY

# Create the filesystem
EFS_ID=$(aws efs create-file-system \
  --performance-mode generalPurpose \
  --throughput-mode bursting \
  --encrypted \
  --kms-key-id $EFS_KMS_KEY \
  --tags Key=Name,Value=aegis-uploads Key=Project,Value=AEGIS \
  --query 'FileSystemId' --output text)

echo "EFS ID: $EFS_ID"

# Wait for the filesystem to be available
aws efs wait file-system-available --file-system-id $EFS_ID 2>/dev/null || \
  sleep 15  # EFS wait is not available in all GovCloud CLI versions
```

### Mount Targets in Private Subnets

```bash
for SUBNET in $PRIV_SUB_A $PRIV_SUB_B $PRIV_SUB_C; do
  aws efs create-mount-target \
    --file-system-id $EFS_ID \
    --subnet-id $SUBNET \
    --security-groups $EFS_SG
done
```

### EFS Access Point

```bash
EFS_AP=$(aws efs create-access-point \
  --file-system-id $EFS_ID \
  --posix-user Uid=33,Gid=33 \
  --root-directory "{\"Path\":\"/uploads\",\"CreationInfo\":{\"OwnerUid\":33,\"OwnerGid\":33,\"Permissions\":\"750\"}}" \
  --tags Key=Name,Value=aegis-uploads-ap \
  --query 'AccessPointId' --output text)

echo "EFS Access Point: $EFS_AP"
```

Update the `fileSystemId` and `accessPointId` fields in both task definition JSON
files with the values of `$EFS_ID` and `$EFS_AP`.

### EFS File System Policy (enforce TLS)

```bash
aws efs put-file-system-policy \
  --file-system-id $EFS_ID \
  --policy "{
    \"Version\": \"2012-10-17\",
    \"Statement\": [{
      \"Sid\": \"EnforceTLS\",
      \"Effect\": \"Deny\",
      \"Principal\": { \"AWS\": \"*\" },
      \"Action\": \"*\",
      \"Condition\": { \"Bool\": { \"aws:SecureTransport\": \"false\" } }
    }]
  }"
```

---

## 10. Application Load Balancer

### Create the ALB

```bash
ALB_ARN=$(aws elbv2 create-load-balancer \
  --name aegis-alb \
  --subnets $PUB_SUB_A $PUB_SUB_B $PUB_SUB_C \
  --security-groups $ALB_SG \
  --scheme internet-facing \
  --type application \
  --ip-address-type ipv4 \
  --tags Key=Project,Value=AEGIS \
  --query 'LoadBalancers[0].LoadBalancerArn' --output text)

ALB_DNS=$(aws elbv2 describe-load-balancers \
  --load-balancer-arns $ALB_ARN \
  --query 'LoadBalancers[0].DNSName' --output text)

echo "ALB ARN: $ALB_ARN"
echo "ALB DNS: $ALB_DNS"
```

### Target Group

```bash
TG_ARN=$(aws elbv2 create-target-group \
  --name aegis-tg \
  --protocol HTTP \
  --port 80 \
  --vpc-id $VPC_ID \
  --target-type ip \
  --health-check-path /health \
  --health-check-interval-seconds 30 \
  --health-check-timeout-seconds 10 \
  --healthy-threshold-count 2 \
  --unhealthy-threshold-count 3 \
  --matcher HttpCode=200-299 \
  --tags Key=Project,Value=AEGIS \
  --query 'TargetGroups[0].TargetGroupArn' --output text)
```

### HTTP → HTTPS Redirect Listener

```bash
aws elbv2 create-listener \
  --load-balancer-arn $ALB_ARN \
  --protocol HTTP \
  --port 80 \
  --default-actions '[{
    "Type": "redirect",
    "RedirectConfig": {
      "Protocol": "HTTPS",
      "Port": "443",
      "StatusCode": "HTTP_301"
    }
  }]'
```

### HTTPS Listener (add after ACM certificate is issued — Section 12)

```bash
# Replace CERT_ARN with the actual ACM certificate ARN
CERT_ARN="arn:aws-us-gov:acm:us-gov-west-1:ACCOUNT_ID:certificate/CERT_ID"

aws elbv2 create-listener \
  --load-balancer-arn $ALB_ARN \
  --protocol HTTPS \
  --port 443 \
  --ssl-policy ELBSecurityPolicy-TLS13-1-2-2021-06 \
  --certificates CertificateArn=$CERT_ARN \
  --default-actions "[{
    \"Type\": \"forward\",
    \"TargetGroupArn\": \"${TG_ARN}\"
  }]"
```

> **Note:** `ELBSecurityPolicy-TLS13-1-2-2021-06` enforces TLS 1.2 minimum with
> TLS 1.3 support, which satisfies NIST SP 800-52 Rev 2 requirements.

---

## 11. ECS Services

### Service: aegis-app

```bash
aws ecs create-service \
  --cluster aegis \
  --service-name aegis-app \
  --task-definition aegis-app \
  --desired-count 2 \
  --launch-type FARGATE \
  --network-configuration "{
    \"awsvpcConfiguration\": {
      \"subnets\": [\"${PRIV_SUB_A}\",\"${PRIV_SUB_B}\",\"${PRIV_SUB_C}\"],
      \"securityGroups\": [\"${APP_SG}\"],
      \"assignPublicIp\": \"DISABLED\"
    }
  }" \
  --load-balancers "[{
    \"targetGroupArn\": \"${TG_ARN}\",
    \"containerName\": \"nginx\",
    \"containerPort\": 80
  }]" \
  --deployment-configuration '{
    "minimumHealthyPercent": 50,
    "maximumPercent": 200,
    "deploymentCircuitBreaker": {"enable": true, "rollback": true}
  }' \
  --health-check-grace-period-seconds 120 \
  --enable-execute-command \
  --tags key=Project,value=AEGIS
```

### Auto-Scaling for aegis-app

```bash
# Register as scalable target
aws application-autoscaling register-scalable-target \
  --service-namespace ecs \
  --resource-id service/aegis/aegis-app \
  --scalable-dimension ecs:service:DesiredCount \
  --min-capacity 2 \
  --max-capacity 10

# Scale on CPU utilization (target 70%)
aws application-autoscaling put-scaling-policy \
  --policy-name aegis-cpu-scaling \
  --service-namespace ecs \
  --resource-id service/aegis/aegis-app \
  --scalable-dimension ecs:service:DesiredCount \
  --policy-type TargetTrackingScaling \
  --target-tracking-scaling-policy-configuration '{
    "TargetValue": 70.0,
    "PredefinedMetricSpecification": {
      "PredefinedMetricType": "ECSServiceAverageCPUUtilization"
    },
    "ScaleInCooldown": 300,
    "ScaleOutCooldown": 60
  }'

# Scale on Memory utilization (target 70%)
aws application-autoscaling put-scaling-policy \
  --policy-name aegis-memory-scaling \
  --service-namespace ecs \
  --resource-id service/aegis/aegis-app \
  --scalable-dimension ecs:service:DesiredCount \
  --policy-type TargetTrackingScaling \
  --target-tracking-scaling-policy-configuration '{
    "TargetValue": 70.0,
    "PredefinedMetricSpecification": {
      "PredefinedMetricType": "ECSServiceAverageMemoryUtilization"
    },
    "ScaleInCooldown": 300,
    "ScaleOutCooldown": 60
  }'
```

### Service: aegis-cron

```bash
aws ecs create-service \
  --cluster aegis \
  --service-name aegis-cron \
  --task-definition aegis-cron \
  --desired-count 1 \
  --launch-type FARGATE \
  --network-configuration "{
    \"awsvpcConfiguration\": {
      \"subnets\": [\"${PRIV_SUB_A}\"],
      \"securityGroups\": [\"${APP_SG}\"],
      \"assignPublicIp\": \"DISABLED\"
    }
  }" \
  --deployment-configuration '{
    "minimumHealthyPercent": 0,
    "maximumPercent": 100
  }' \
  --enable-execute-command \
  --tags key=Project,value=AEGIS
```

> **Note:** The cron service runs a single task (desired-count 1) with no
> auto-scaling. `minimumHealthyPercent: 0` allows a brief downtime during
> deployments, which is acceptable for the background job runner.
> `enable-execute-command` allows access via SSM for troubleshooting.

---

## 12. ACM Certificate

ACM Public CA is available in AWS GovCloud. DNS validation is recommended.

```bash
# Request the certificate
CERT_ARN=$(aws acm request-certificate \
  --domain-name your-agency-domain.gov \
  --subject-alternative-names "*.your-agency-domain.gov" \
  --validation-method DNS \
  --tags Key=Project,Value=AEGIS \
  --query 'CertificateArn' --output text)

echo "Certificate ARN: $CERT_ARN"

# Retrieve the DNS validation record
aws acm describe-certificate \
  --certificate-arn $CERT_ARN \
  --query 'Certificate.DomainValidationOptions[0].ResourceRecord'
```

Add the returned CNAME record to your DNS zone (Route 53 or your agency's DNS
provider). Once the record propagates, ACM validates and issues the certificate
automatically.

```bash
# Wait for validation
aws acm wait certificate-validated --certificate-arn $CERT_ARN
echo "Certificate issued."
```

> **Note:** If your agency's DNS is managed outside AWS, you may need to use
> email validation instead. Contact your DNS administrator to add the CNAME
> record. Email validation sends messages to standard administrative addresses
> (admin@, hostmaster@, etc.) at the domain.

---

## 13. CloudWatch — Logs & Alarms

### Log Groups

```bash
# Application logs — 90-day retention
for GROUP in /aegis/app /aegis/cron /aegis/vpc-flow-logs /aegis/rds; do
  aws logs create-log-group --log-group-name $GROUP
  aws logs put-retention-policy \
    --log-group-name $GROUP \
    --retention-in-days 90
done
```

> **Note:** Adjust retention to meet your agency's NARA records schedule. FedRAMP
> High typically requires at least 90 days online with 1-year archival. Use
> CloudWatch Logs export to S3 for longer-term archival with Glacier.

### SNS Topic for Alarms

```bash
SNS_ARN=$(aws sns create-topic \
  --name aegis-alerts \
  --query 'TopicArn' --output text)

aws sns subscribe \
  --topic-arn $SNS_ARN \
  --protocol email \
  --notification-endpoint ops-team@your-agency.gov
```

### CloudWatch Alarms

```bash
# CPU utilization > 80%
aws cloudwatch put-metric-alarm \
  --alarm-name "AEGIS-CPU-High" \
  --alarm-description "ECS aegis-app CPU > 80%" \
  --namespace AWS/ECS \
  --metric-name CPUUtilization \
  --dimensions Name=ClusterName,Value=aegis Name=ServiceName,Value=aegis-app \
  --statistic Average \
  --period 300 \
  --threshold 80 \
  --comparison-operator GreaterThanThreshold \
  --evaluation-periods 2 \
  --alarm-actions $SNS_ARN \
  --ok-actions $SNS_ARN

# Memory utilization > 80%
aws cloudwatch put-metric-alarm \
  --alarm-name "AEGIS-Memory-High" \
  --alarm-description "ECS aegis-app Memory > 80%" \
  --namespace AWS/ECS \
  --metric-name MemoryUtilization \
  --dimensions Name=ClusterName,Value=aegis Name=ServiceName,Value=aegis-app \
  --statistic Average \
  --period 300 \
  --threshold 80 \
  --comparison-operator GreaterThanThreshold \
  --evaluation-periods 2 \
  --alarm-actions $SNS_ARN

# ALB 5xx error rate > 1%
aws cloudwatch put-metric-alarm \
  --alarm-name "AEGIS-ALB-5xx-High" \
  --alarm-description "ALB 5xx error rate > 1%" \
  --namespace AWS/ApplicationELB \
  --metric-name HTTPCode_ELB_5XX_Count \
  --dimensions Name=LoadBalancer,Value=$(aws elbv2 describe-load-balancers \
      --load-balancer-arns $ALB_ARN \
      --query 'LoadBalancers[0].LoadBalancerName' --output text) \
  --statistic Sum \
  --period 60 \
  --threshold 10 \
  --comparison-operator GreaterThanThreshold \
  --evaluation-periods 2 \
  --treat-missing-data notBreaching \
  --alarm-actions $SNS_ARN

# RDS freeable memory < 1 GB
aws cloudwatch put-metric-alarm \
  --alarm-name "AEGIS-RDS-LowMemory" \
  --alarm-description "RDS freeable memory < 1 GB" \
  --namespace AWS/RDS \
  --metric-name FreeableMemory \
  --dimensions Name=DBInstanceIdentifier,Value=aegis-db \
  --statistic Average \
  --period 300 \
  --threshold 1073741824 \
  --comparison-operator LessThanThreshold \
  --evaluation-periods 2 \
  --alarm-actions $SNS_ARN
```

### CloudWatch Dashboard

```bash
aws cloudwatch put-dashboard \
  --dashboard-name AEGIS-Production \
  --dashboard-body '{
    "widgets": [
      {
        "type": "metric",
        "properties": {
          "title": "ECS CPU & Memory",
          "metrics": [
            ["AWS/ECS","CPUUtilization","ClusterName","aegis","ServiceName","aegis-app"],
            ["AWS/ECS","MemoryUtilization","ClusterName","aegis","ServiceName","aegis-app"]
          ],
          "period": 60,
          "stat": "Average",
          "view": "timeSeries"
        }
      },
      {
        "type": "metric",
        "properties": {
          "title": "ALB Request Count & 5xx",
          "metrics": [
            ["AWS/ApplicationELB","RequestCount","LoadBalancer","aegis-alb"],
            ["AWS/ApplicationELB","HTTPCode_ELB_5XX_Count","LoadBalancer","aegis-alb"]
          ],
          "period": 60,
          "stat": "Sum",
          "view": "timeSeries"
        }
      },
      {
        "type": "metric",
        "properties": {
          "title": "RDS — Connections & Latency",
          "metrics": [
            ["AWS/RDS","DatabaseConnections","DBInstanceIdentifier","aegis-db"],
            ["AWS/RDS","ReadLatency","DBInstanceIdentifier","aegis-db"],
            ["AWS/RDS","WriteLatency","DBInstanceIdentifier","aegis-db"]
          ],
          "period": 60,
          "stat": "Average",
          "view": "timeSeries"
        }
      }
    ]
  }'
```

---

## 14. IAM Roles & Policies

### ECS Task Execution Role

This role allows ECS to pull images from ECR, write logs to CloudWatch, and
retrieve secrets from Secrets Manager on behalf of the task.

```bash
# Create the execution role
aws iam create-role \
  --role-name aegis-ecs-execution-role \
  --assume-role-policy-document '{
    "Version": "2012-10-17",
    "Statement": [{
      "Effect": "Allow",
      "Principal": { "Service": "ecs-tasks.amazonaws.com" },
      "Action": "sts:AssumeRole"
    }]
  }'

# Attach the AWS-managed execution policy
aws iam attach-role-policy \
  --role-name aegis-ecs-execution-role \
  --policy-arn arn:aws-us-gov:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy

# Add Secrets Manager access
aws iam put-role-policy \
  --role-name aegis-ecs-execution-role \
  --policy-name AegisSecretsManagerAccess \
  --policy-document "{
    \"Version\": \"2012-10-17\",
    \"Statement\": [{
      \"Effect\": \"Allow\",
      \"Action\": [
        \"secretsmanager:GetSecretValue\",
        \"kms:Decrypt\"
      ],
      \"Resource\": [
        \"arn:aws-us-gov:secretsmanager:us-gov-west-1:${ACCOUNT_ID}:secret:aegis/*\",
        \"arn:aws-us-gov:kms:us-gov-west-1:${ACCOUNT_ID}:key/*\"
      ]
    }]
  }"
```

### ECS Task Role

This role is assumed by the running application code. It grants access to S3
and KMS for file storage operations.

```bash
# Create the task role
aws iam create-role \
  --role-name aegis-ecs-task-role \
  --assume-role-policy-document '{
    "Version": "2012-10-17",
    "Statement": [{
      "Effect": "Allow",
      "Principal": { "Service": "ecs-tasks.amazonaws.com" },
      "Action": "sts:AssumeRole"
    }]
  }'

# S3 access policy (least-privilege: only the AEGIS bucket)
aws iam put-role-policy \
  --role-name aegis-ecs-task-role \
  --policy-name AegisS3Access \
  --policy-document "{
    \"Version\": \"2012-10-17\",
    \"Statement\": [
      {
        \"Effect\": \"Allow\",
        \"Action\": [
          \"s3:GetObject\",
          \"s3:PutObject\",
          \"s3:DeleteObject\",
          \"s3:ListBucket\"
        ],
        \"Resource\": [
          \"arn:aws-us-gov:s3:::aegis-uploads-${ACCOUNT_ID}-us-gov-west-1\",
          \"arn:aws-us-gov:s3:::aegis-uploads-${ACCOUNT_ID}-us-gov-west-1/*\"
        ]
      },
      {
        \"Effect\": \"Allow\",
        \"Action\": [
          \"kms:GenerateDataKey\",
          \"kms:Decrypt\"
        ],
        \"Resource\": \"arn:aws-us-gov:kms:us-gov-west-1:${ACCOUNT_ID}:key/${S3_KMS_KEY}\"
      },
      {
        \"Effect\": \"Allow\",
        \"Action\": \"ssmmessages:*\",
        \"Resource\": \"*\"
      }
    ]
  }"

# EFS access
aws iam put-role-policy \
  --role-name aegis-ecs-task-role \
  --policy-name AegisEFSAccess \
  --policy-document "{
    \"Version\": \"2012-10-17\",
    \"Statement\": [{
      \"Effect\": \"Allow\",
      \"Action\": [
        \"elasticfilesystem:ClientMount\",
        \"elasticfilesystem:ClientWrite\",
        \"elasticfilesystem:DescribeFileSystems\"
      ],
      \"Resource\": \"arn:aws-us-gov:elasticfilesystem:us-gov-west-1:${ACCOUNT_ID}:file-system/${EFS_ID}\"
    }]
  }"
```

> **Note:** The `ssmmessages:*` permission on the task role enables
> `aws ecs execute-command` (ECS Exec via SSM), which is required for live
> debugging and running one-off migration commands without a bastion host.

---

## 15. Database Migration

AEGIS uses a `startup.sh` entrypoint that calls `install.php` on every container
start. In ECS, this means every new app task attempts schema initialization. The
`install.php` script should be idempotent (using `CREATE TABLE IF NOT EXISTS`).

The 19 SQL migration files are applied in order after the base schema. For initial
deployment and each upgrade, run a **one-time ECS task** that executes the
migrations.

### Step 1 — Initial Schema + Migrations (first deploy only)

The base schema (`database/schema.sql`) and initial migrations are handled by
`install.php` running in the app container at startup.

For the remaining migrations (002 through 019), run:

```bash
# Run a one-time task to execute all migrations in order
aws ecs run-task \
  --cluster aegis \
  --task-definition aegis-app \
  --launch-type FARGATE \
  --network-configuration "{
    \"awsvpcConfiguration\": {
      \"subnets\": [\"${PRIV_SUB_A}\"],
      \"securityGroups\": [\"${APP_SG}\"],
      \"assignPublicIp\": \"DISABLED\"
    }
  }" \
  --overrides '{
    "containerOverrides": [{
      "name": "app",
      "command": [
        "/bin/bash", "-c",
        "for f in $(ls /var/www/html/database/migrations/*.sql | sort); do echo \"Applying $f\"; psql \"${DB_DSN}\" -f \"$f\" || exit 1; done; echo \"All migrations applied.\""
      ]
    }]
  }'
```

### Step 2 — Using ECS Exec (recommended for troubleshooting)

```bash
# Get the running task ID
TASK_ID=$(aws ecs list-tasks \
  --cluster aegis \
  --service-name aegis-app \
  --query 'taskArns[0]' --output text | awk -F/ '{print $NF}')

# Open an interactive shell in the app container
aws ecs execute-command \
  --cluster aegis \
  --task $TASK_ID \
  --container app \
  --command "/bin/bash" \
  --interactive

# Inside the container, run migrations manually:
# php /var/www/html/install.php
# psql -h $DB_HOST -U $DB_USER -d $DB_NAME -f database/migrations/019_ssp_extended.sql
```

### Step 3 — Applying New Migrations on Update

When deploying a new version that includes additional migration files:

```bash
# 1. Push new image to ECR (see Section 19)
# 2. Run migration task before updating the service
aws ecs run-task \
  --cluster aegis \
  --task-definition aegis-app:NEW_REVISION \
  --launch-type FARGATE \
  --network-configuration "..." \
  --overrides '{
    "containerOverrides": [{
      "name": "app",
      "command": ["/bin/bash","-c","php /var/www/html/install.php"]
    }]
  }'

# 3. Wait for the task to complete, then force a new deployment
aws ecs update-service \
  --cluster aegis \
  --service aegis-app \
  --task-definition aegis-app:NEW_REVISION \
  --force-new-deployment
```

---

## 16. Environment Variable Reference

| Variable | Source | Description |
|----------|--------|-------------|
| `DB_HOST` | Secrets Manager (`aegis/db`) | RDS endpoint hostname |
| `DB_PORT` | ECS env (plain) | PostgreSQL port — always `5432` |
| `DB_NAME` | ECS env (plain) | Database name — `aegis` |
| `DB_USER` | Secrets Manager (`aegis/db`) | Database username |
| `DB_PASS` | Secrets Manager (`aegis/db`) | Database password |
| `APP_URL` | ECS env (plain) | Full public URL, e.g. `https://aegis.agency.gov` |
| `APP_ENV` | ECS env (plain) | Runtime environment — `production` |
| `JWT_SECRET` | Secrets Manager (`aegis/jwt`) | 64-byte hex secret for JWT signing |
| `SMTP_HOST` | Secrets Manager (`aegis/smtp`) | SMTP server hostname |
| `SMTP_PORT` | ECS env (plain) | SMTP port — `587` (STARTTLS) |
| `SMTP_USER` | Secrets Manager (`aegis/smtp`) | SMTP authentication username |
| `SMTP_PASS` | Secrets Manager (`aegis/smtp`) | SMTP authentication password |
| `SMTP_FROM` | Secrets Manager (`aegis/smtp`) | From address for outbound mail |
| `STORAGE_DRIVER` | ECS env (plain) | `s3` for GovCloud deployment |
| `S3_BUCKET` | ECS env (plain) | S3 bucket name |
| `S3_REGION` | ECS env (plain) | `us-gov-west-1` or `us-gov-east-1` |
| `AI_API_KEY` | Secrets Manager (`aegis/ai`) | API key for AI/LLM features |

> **Note:** Never place sensitive values (passwords, secrets, API keys) in the
> `environment` array of ECS task definitions. These are visible in the AWS
> console and API responses. Always use the `secrets` array with Secrets Manager.

---

## 17. FedRAMP / Security Hardening

### AWS Config Rules

```bash
# Enable AWS Config recorder
aws configservice put-configuration-recorder \
  --configuration-recorder name=aegis-recorder,roleARN=arn:aws-us-gov:iam::${ACCOUNT_ID}:role/AWSConfigRole \
  --recording-group allSupported=true,includeGlobalResourceTypes=true

# Enable Config delivery channel
aws configservice put-delivery-channel \
  --delivery-channel name=aegis-delivery,s3BucketName=aegis-config-${ACCOUNT_ID}

aws configservice start-configuration-recorder --configuration-recorder-name aegis-recorder

# Apply FedRAMP-relevant Config rules
for RULE in \
  encrypted-volumes \
  rds-storage-encrypted \
  s3-bucket-ssl-requests-only \
  s3-bucket-server-side-encryption-enabled \
  s3-bucket-versioning-enabled \
  iam-root-access-key-check \
  mfa-enabled-for-iam-console-access \
  root-account-mfa-enabled \
  vpc-flow-logs-enabled \
  cloudtrail-enabled; do
  aws configservice put-config-rule \
    --config-rule "{\"ConfigRuleName\":\"${RULE}\",\"Source\":{\"Owner\":\"AWS\",\"SourceIdentifier\":\"$(echo $RULE | tr '[:lower:]' '[:upper:]' | tr '-' '_')\"}}"
done
```

### CloudTrail

```bash
# Create a dedicated S3 bucket for CloudTrail logs
TRAIL_BUCKET="aegis-cloudtrail-${ACCOUNT_ID}"
aws s3api create-bucket \
  --bucket $TRAIL_BUCKET \
  --region us-gov-west-1 \
  --create-bucket-configuration LocationConstraint=us-gov-west-1

# Enable CloudTrail for all regions
aws cloudtrail create-trail \
  --name aegis-trail \
  --s3-bucket-name $TRAIL_BUCKET \
  --is-multi-region-trail \
  --enable-log-file-validation \
  --cloud-watch-logs-log-group-arn arn:aws-us-gov:logs:us-gov-west-1:${ACCOUNT_ID}:log-group:/aegis/cloudtrail:* \
  --cloud-watch-logs-role-arn arn:aws-us-gov:iam::${ACCOUNT_ID}:role/CloudTrailCloudWatchRole

aws cloudtrail start-logging --name aegis-trail
```

### GuardDuty

```bash
aws guardduty create-detector \
  --enable \
  --finding-publishing-frequency FIFTEEN_MINUTES \
  --data-sources '{
    "S3Logs": {"Enable": true},
    "Kubernetes": {"AuditLogs": {"Enable": false}},
    "MalwareProtection": {"ScanEc2InstanceWithFindings": {"EbsVolumes": false}}
  }'
```

### Security Hub (FedRAMP Baseline)

```bash
aws securityhub enable-security-hub \
  --enable-default-standards \
  --tags Project=AEGIS

# Enable FedRAMP-specific standards (where available in GovCloud)
# Check available standard ARNs:
aws securityhub describe-standards \
  --query 'Standards[].StandardsArn'
```

### WAF on the ALB

```bash
# Create a WAF Web ACL with AWS Managed Rules
WAF_ACL_ARN=$(aws wafv2 create-web-acl \
  --name aegis-waf \
  --scope REGIONAL \
  --default-action Allow={} \
  --rules '[
    {
      "Name": "AWSManagedRulesCommonRuleSet",
      "Priority": 1,
      "Statement": {
        "ManagedRuleGroupStatement": {
          "VendorName": "AWS",
          "Name": "AWSManagedRulesCommonRuleSet"
        }
      },
      "OverrideAction": {"None": {}},
      "VisibilityConfig": {
        "SampledRequestsEnabled": true,
        "CloudWatchMetricsEnabled": true,
        "MetricName": "CommonRuleSet"
      }
    },
    {
      "Name": "AWSManagedRulesKnownBadInputsRuleSet",
      "Priority": 2,
      "Statement": {
        "ManagedRuleGroupStatement": {
          "VendorName": "AWS",
          "Name": "AWSManagedRulesKnownBadInputsRuleSet"
        }
      },
      "OverrideAction": {"None": {}},
      "VisibilityConfig": {
        "SampledRequestsEnabled": true,
        "CloudWatchMetricsEnabled": true,
        "MetricName": "KnownBadInputs"
      }
    }
  ]' \
  --visibility-config SampledRequestsEnabled=true,CloudWatchMetricsEnabled=true,MetricName=AEGIS-WAF \
  --region us-gov-west-1 \
  --query 'Summary.ARN' --output text)

# Associate WAF with ALB
aws wafv2 associate-web-acl \
  --web-acl-arn $WAF_ACL_ARN \
  --resource-arn $ALB_ARN
```

### GovCloud Impact Level Summary

| Configuration | Impact Level |
|---------------|-------------|
| Default GovCloud deployment | IL2 |
| + FIPS 140-2 endpoints + DoD agency account | IL4 |
| + NSS controls + additional DISA STIGs applied | IL5 |

> **Note:** FIPS 140-2 validated endpoints are available for most GovCloud services.
> Use FIPS endpoints by replacing service URLs:
> `s3-fips.us-gov-west-1.amazonaws.com`, `elasticfilesystem-fips.us-gov-west-1.amazonaws.com`, etc.
> Configure the AWS CLI with `aws configure set use_fips_endpoint true`.

---

## 18. Cost Estimate

Approximate monthly costs in `us-gov-west-1` (GovCloud pricing is typically
10–15% higher than commercial us-east-1).

| Service | Configuration | Est. Monthly Cost (USD) |
|---------|--------------|------------------------|
| ECS Fargate — aegis-app | 2 tasks × 1 vCPU / 2 GB, ~730 hrs | ~$140 |
| ECS Fargate — aegis-cron | 1 task × 0.5 vCPU / 1 GB, ~730 hrs | ~$30 |
| RDS PostgreSQL 16 | db.r6g.large, Multi-AZ, 100 GB gp3 | ~$380 |
| Application Load Balancer | 1 ALB + ~100 GB data processed | ~$25 |
| S3 | 100 GB storage + requests | ~$5 |
| EFS | 20 GB provisioned | ~$8 |
| NAT Gateway | 1 gateway + ~50 GB data | ~$50 |
| Secrets Manager | 4 secrets | ~$2 |
| CloudWatch | Logs ingestion + alarms + dashboard | ~$20 |
| ECR | 10 GB storage | ~$1 |
| GuardDuty | Per-event pricing | ~$15 |
| WAF | Web ACL + rule evaluations | ~$10 |
| **Total (estimated)** | | **~$686/month** |

> **Note:** These are rough estimates based on 2024 GovCloud pricing. Actual costs
> depend on traffic volume, data transfer, log ingestion rate, and specific
> instance selection. Use the
> [AWS GovCloud Pricing Calculator](https://calculator.aws.amazon.com/) for a
> precise estimate. Cost can be reduced significantly by using `FARGATE_SPOT`
> capacity for non-critical services (cron) and right-sizing the RDS instance
> after observing production load.

---

## 19. Maintenance & Updates

### Deploying a New Application Version

```bash
# 1. Build and tag the new image
VERSION=$(git rev-parse --short HEAD)
docker build -t aegis .
docker tag aegis:latest ${ACCOUNT_ID}.dkr.ecr.us-gov-west-1.amazonaws.com/aegis:${VERSION}
docker push ${ACCOUNT_ID}.dkr.ecr.us-gov-west-1.amazonaws.com/aegis:${VERSION}

# 2. Register a new task definition revision with the updated image tag
# Edit task-def-app.json to update the image field to :${VERSION}, then:
aws ecs register-task-definition --cli-input-json file://task-def-app.json

NEW_REVISION=$(aws ecs describe-task-definition \
  --task-definition aegis-app \
  --query 'taskDefinition.revision' --output text)

# 3. Run migrations (if any new .sql files were added)
aws ecs run-task \
  --cluster aegis \
  --task-definition aegis-app:${NEW_REVISION} \
  --launch-type FARGATE \
  --network-configuration "..." \
  --overrides '{"containerOverrides":[{"name":"app","command":["/bin/bash","-c","php /var/www/html/install.php"]}]}'

# Wait for migration task to complete before proceeding
aws ecs wait tasks-stopped --cluster aegis --tasks <TASK_ARN>

# 4. Update the service with rolling deployment
aws ecs update-service \
  --cluster aegis \
  --service aegis-app \
  --task-definition aegis-app:${NEW_REVISION} \
  --force-new-deployment

aws ecs update-service \
  --cluster aegis \
  --service aegis-cron \
  --task-definition aegis-cron:${NEW_CRON_REVISION} \
  --force-new-deployment

# 5. Monitor the rollout
aws ecs wait services-stable --cluster aegis --services aegis-app
echo "Deployment complete."
```

### Verifying RDS Automated Backups

```bash
# List available automated snapshots
aws rds describe-db-snapshots \
  --db-instance-identifier aegis-db \
  --snapshot-type automated \
  --query 'DBSnapshots[*].[DBSnapshotIdentifier,SnapshotCreateTime,Status]' \
  --output table

# Test restore to a new instance (do this quarterly)
aws rds restore-db-instance-from-db-snapshot \
  --db-instance-identifier aegis-db-restore-test \
  --db-snapshot-identifier <SNAPSHOT_IDENTIFIER> \
  --db-instance-class db.r6g.large \
  --no-publicly-accessible \
  --deletion-protection

# After verifying the restore, delete the test instance
aws rds delete-db-instance \
  --db-instance-identifier aegis-db-restore-test \
  --skip-final-snapshot
```

### Rotating Secrets

```bash
# Rotate JWT secret (app must handle graceful token expiry)
aws secretsmanager rotate-secret --secret-id aegis/jwt

# Rotate DB password
NEW_PASS=$(openssl rand -base64 32)
aws rds modify-db-instance \
  --db-instance-identifier aegis-db \
  --master-user-password "$NEW_PASS" \
  --apply-immediately

aws secretsmanager update-secret \
  --secret-id aegis/db \
  --secret-string "{\"DB_PASS\":\"${NEW_PASS}\",...}"

# Force new ECS deployment to pick up the new secret
aws ecs update-service --cluster aegis --service aegis-app --force-new-deployment
aws ecs update-service --cluster aegis --service aegis-cron --force-new-deployment
```

### Monthly Security Review Checklist

- [ ] Review GuardDuty findings and remediate High/Critical
- [ ] Review Security Hub compliance score; address new failed controls
- [ ] Verify CloudTrail logs are being delivered to S3
- [ ] Review IAM Access Analyzer findings for unexpected external access
- [ ] Confirm RDS automated backups completed successfully (35-day window)
- [ ] Check ECR image scan results for HIGH/CRITICAL CVEs and rebuild if needed
- [ ] Review CloudWatch alarms — ensure no persistent alarm states
- [ ] Verify VPC Flow Logs are active and being retained
- [ ] Test ECS Exec access for break-glass troubleshooting
- [ ] Review and rotate long-lived credentials if any remain

---

*This guide reflects AWS GovCloud service availability and FedRAMP guidance as of
mid-2025. Service names, pricing, and available controls are subject to change.
Always consult the [AWS GovCloud documentation](https://docs.aws.amazon.com/govcloud-us/)
and your agency's AO (Authorizing Official) before finalizing an architecture for
an ATO package.*
