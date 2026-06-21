###############################################################################
# CITADEL — AWS Commercial Deployment
# network.tf — VPC, subnets, NAT, route tables, security groups, flow logs
#
# Public subnets host the ALB and the NAT gateways. Private subnets host the
# ECS Fargate tasks and the RDS instance. Egress from private subnets to AWS
# APIs and the internet (for scanner DB updates) goes through NAT (SC-7).
###############################################################################

###############################################################################
# VPC + subnets (created only when vpc_id not supplied)
###############################################################################

resource "aws_vpc" "this" {
  count                = local.create_vpc ? 1 : 0
  cidr_block           = var.vpc_cidr
  enable_dns_support   = true
  enable_dns_hostnames = true

  tags = { Name = "${local.name}-vpc" }
}

resource "aws_subnet" "public" {
  count                   = local.create_vpc ? length(var.public_subnet_cidrs) : 0
  vpc_id                  = aws_vpc.this[0].id
  cidr_block              = var.public_subnet_cidrs[count.index]
  availability_zone       = local.azs[count.index]
  map_public_ip_on_launch = false # ALB + NAT get explicit EIPs; no auto-assign (SC-7)

  tags = { Name = "${local.name}-public-${count.index}", Tier = "public" }
}

resource "aws_subnet" "private" {
  count             = local.create_vpc ? length(var.private_subnet_cidrs) : 0
  vpc_id            = aws_vpc.this[0].id
  cidr_block        = var.private_subnet_cidrs[count.index]
  availability_zone = local.azs[count.index]

  tags = { Name = "${local.name}-private-${count.index}", Tier = "private" }
}

resource "aws_internet_gateway" "this" {
  count  = local.create_vpc ? 1 : 0
  vpc_id = aws_vpc.this[0].id
  tags   = { Name = "${local.name}-igw" }
}

###############################################################################
# NAT gateways (one per AZ for HA) — private-subnet egress to AWS APIs and to
# scanner vulnerability-DB mirrors (Trivy/Grype/ClamAV updates).
###############################################################################

resource "aws_eip" "nat" {
  count  = local.create_vpc ? length(aws_subnet.public) : 0
  domain = "vpc"
  tags   = { Name = "${local.name}-nat-eip-${count.index}" }
}

resource "aws_nat_gateway" "this" {
  count         = local.create_vpc ? length(aws_subnet.public) : 0
  allocation_id = aws_eip.nat[count.index].id
  subnet_id     = aws_subnet.public[count.index].id
  tags          = { Name = "${local.name}-nat-${count.index}" }

  depends_on = [aws_internet_gateway.this]
}

###############################################################################
# Route tables
###############################################################################

resource "aws_route_table" "public" {
  count  = local.create_vpc ? 1 : 0
  vpc_id = aws_vpc.this[0].id

  route {
    cidr_block = "0.0.0.0/0"
    gateway_id = aws_internet_gateway.this[0].id
  }
  tags = { Name = "${local.name}-rt-public" }
}

resource "aws_route_table_association" "public" {
  count          = local.create_vpc ? length(aws_subnet.public) : 0
  subnet_id      = aws_subnet.public[count.index].id
  route_table_id = aws_route_table.public[0].id
}

# One private route table per AZ, each routing 0.0.0.0/0 to the AZ-local NAT GW.
resource "aws_route_table" "private" {
  count  = local.create_vpc ? length(aws_subnet.private) : 0
  vpc_id = aws_vpc.this[0].id

  route {
    cidr_block     = "0.0.0.0/0"
    nat_gateway_id = aws_nat_gateway.this[count.index].id
  }
  tags = { Name = "${local.name}-rt-private-${count.index}" }
}

resource "aws_route_table_association" "private" {
  count          = local.create_vpc ? length(aws_subnet.private) : 0
  subnet_id      = aws_subnet.private[count.index].id
  route_table_id = aws_route_table.private[count.index].id
}

# S3 gateway endpoint — keep ECR layer pulls off NAT (cost + SC-7).
resource "aws_vpc_endpoint" "s3" {
  count             = local.create_vpc ? 1 : 0
  vpc_id            = local.vpc_id
  service_name      = "com.amazonaws.${var.region}.s3"
  vpc_endpoint_type = "Gateway"
  route_table_ids   = aws_route_table.private[*].id
  tags              = { Name = "${local.name}-vpce-s3" }
}

###############################################################################
# VPC Flow Logs to CloudWatch (AU-2, AU-12, SC-7)
###############################################################################

resource "aws_flow_log" "vpc" {
  count                = local.create_vpc ? 1 : 0
  log_destination      = aws_cloudwatch_log_group.flow.arn
  log_destination_type = "cloud-watch-logs"
  iam_role_arn         = aws_iam_role.flow_logs.arn
  traffic_type         = "ALL"
  vpc_id               = aws_vpc.this[0].id
  tags                 = { Name = "${local.name}-vpc-flowlogs" }
}

resource "aws_iam_role" "flow_logs" {
  name = "${local.name}-flowlogs"
  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect    = "Allow"
      Principal = { Service = "vpc-flow-logs.amazonaws.com" }
      Action    = "sts:AssumeRole"
    }]
  })
  tags = { Name = "${local.name}-flowlogs" }
}

resource "aws_iam_role_policy" "flow_logs" {
  name = "${local.name}-flowlogs-policy"
  role = aws_iam_role.flow_logs.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect   = "Allow"
      Action   = ["logs:CreateLogStream", "logs:PutLogEvents", "logs:DescribeLogStreams"]
      Resource = "${aws_cloudwatch_log_group.flow.arn}:*"
    }]
  })
}

###############################################################################
# Security groups (least privilege — SC-7)
#   ALB  : 443 from approved CIDRs            -> egress to ECS on container port
#   ECS  : container port from ALB only       -> egress to RDS(5432) + 443 out
#   RDS  : 5432 from ECS only                 -> no egress needed
###############################################################################

resource "aws_security_group" "alb" {
  name        = "${local.name}-alb-sg"
  description = "ALB ingress 443 (and 80 for redirect) from approved CIDRs; egress to ECS tasks"
  vpc_id      = local.vpc_id

  ingress {
    description = "HTTPS from approved CIDRs"
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = var.ingress_allowed_cidrs
  }

  ingress {
    description = "HTTP from approved CIDRs (redirected to HTTPS)"
    from_port   = 80
    to_port     = 80
    protocol    = "tcp"
    cidr_blocks = var.ingress_allowed_cidrs
  }

  egress {
    description     = "To ECS tasks on container port"
    from_port       = var.container_port
    to_port         = var.container_port
    protocol        = "tcp"
    security_groups = [aws_security_group.ecs.id]
  }

  tags = { Name = "${local.name}-alb-sg" }
}

resource "aws_security_group" "ecs" {
  name        = "${local.name}-ecs-sg"
  description = "ECS tasks: ingress only from ALB; egress to RDS and HTTPS (ECR/logs/secrets/scanner DBs)"
  vpc_id      = local.vpc_id

  ingress {
    description     = "From ALB"
    from_port       = var.container_port
    to_port         = var.container_port
    protocol        = "tcp"
    security_groups = [aws_security_group.alb.id]
  }

  egress {
    description = "HTTPS egress to AWS APIs and scanner vuln-DB mirrors"
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  egress {
    description     = "PostgreSQL to RDS"
    from_port       = 5432
    to_port         = 5432
    protocol        = "tcp"
    security_groups = [aws_security_group.rds.id]
  }

  tags = { Name = "${local.name}-ecs-sg" }
}

resource "aws_security_group" "rds" {
  name        = "${local.name}-rds-sg"
  description = "RDS PostgreSQL: ingress 5432 from ECS tasks only; no egress"
  vpc_id      = local.vpc_id

  ingress {
    description     = "PostgreSQL from ECS tasks"
    from_port       = 5432
    to_port         = 5432
    protocol        = "tcp"
    security_groups = [aws_security_group.ecs.id]
  }

  tags = { Name = "${local.name}-rds-sg" }
}
