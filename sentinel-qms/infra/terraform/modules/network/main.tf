# =============================================================================
# Network module — VPC/VNet, public + private + data subnets, NAT, SGs/NSGs.
#
# Dual-cloud: the `cloud` variable selects AWS or Azure resources via count
# guards. A single module interface keeps the root stacks symmetric.
# =============================================================================

locals {
  is_aws   = var.cloud == "aws"
  is_azure = var.cloud == "azure"
  az_count = length(var.private_subnet_cidrs)
}

# -----------------------------------------------------------------------------
# AWS — VPC, subnets, IGW, NAT, route tables, security groups
# -----------------------------------------------------------------------------
resource "aws_vpc" "this" {
  count                = local.is_aws ? 1 : 0
  cidr_block           = var.cidr_block
  enable_dns_support   = true
  enable_dns_hostnames = true
  tags                 = merge(var.tags, { Name = "${var.name_prefix}-vpc" })
}

resource "aws_internet_gateway" "this" {
  count  = local.is_aws ? 1 : 0
  vpc_id = aws_vpc.this[0].id
  tags   = merge(var.tags, { Name = "${var.name_prefix}-igw" })
}

resource "aws_subnet" "public" {
  count                   = local.is_aws ? length(var.public_subnet_cidrs) : 0
  vpc_id                  = aws_vpc.this[0].id
  cidr_block              = var.public_subnet_cidrs[count.index]
  availability_zone       = var.aws_availability_zones[count.index]
  map_public_ip_on_launch = false
  tags = merge(var.tags, {
    Name                     = "${var.name_prefix}-public-${count.index}"
    Tier                     = "public"
    "kubernetes.io/role/elb" = "1"
  })
}

resource "aws_subnet" "private" {
  count             = local.is_aws ? length(var.private_subnet_cidrs) : 0
  vpc_id            = aws_vpc.this[0].id
  cidr_block        = var.private_subnet_cidrs[count.index]
  availability_zone = var.aws_availability_zones[count.index]
  tags = merge(var.tags, {
    Name                              = "${var.name_prefix}-private-${count.index}"
    Tier                              = "private"
    "kubernetes.io/role/internal-elb" = "1"
  })
}

resource "aws_subnet" "data" {
  count             = local.is_aws ? length(var.data_subnet_cidrs) : 0
  vpc_id            = aws_vpc.this[0].id
  cidr_block        = var.data_subnet_cidrs[count.index]
  availability_zone = var.aws_availability_zones[count.index]
  tags = merge(var.tags, {
    Name = "${var.name_prefix}-data-${count.index}"
    Tier = "data"
  })
}

resource "aws_eip" "nat" {
  count  = local.is_aws ? (var.single_nat_gateway ? 1 : length(var.public_subnet_cidrs)) : 0
  domain = "vpc"
  tags   = merge(var.tags, { Name = "${var.name_prefix}-nat-eip-${count.index}" })
}

resource "aws_nat_gateway" "this" {
  count         = local.is_aws ? (var.single_nat_gateway ? 1 : length(var.public_subnet_cidrs)) : 0
  allocation_id = aws_eip.nat[count.index].id
  subnet_id     = aws_subnet.public[count.index].id
  tags          = merge(var.tags, { Name = "${var.name_prefix}-nat-${count.index}" })
  depends_on    = [aws_internet_gateway.this]
}

resource "aws_route_table" "public" {
  count  = local.is_aws ? 1 : 0
  vpc_id = aws_vpc.this[0].id
  tags   = merge(var.tags, { Name = "${var.name_prefix}-rt-public" })
}

resource "aws_route" "public_internet" {
  count                  = local.is_aws ? 1 : 0
  route_table_id         = aws_route_table.public[0].id
  destination_cidr_block = "0.0.0.0/0"
  gateway_id             = aws_internet_gateway.this[0].id
}

resource "aws_route_table_association" "public" {
  count          = local.is_aws ? length(var.public_subnet_cidrs) : 0
  subnet_id      = aws_subnet.public[count.index].id
  route_table_id = aws_route_table.public[0].id
}

resource "aws_route_table" "private" {
  count  = local.is_aws ? length(var.private_subnet_cidrs) : 0
  vpc_id = aws_vpc.this[0].id
  tags   = merge(var.tags, { Name = "${var.name_prefix}-rt-private-${count.index}" })
}

resource "aws_route" "private_nat" {
  count                  = local.is_aws ? length(var.private_subnet_cidrs) : 0
  route_table_id         = aws_route_table.private[count.index].id
  destination_cidr_block = "0.0.0.0/0"
  nat_gateway_id         = var.single_nat_gateway ? aws_nat_gateway.this[0].id : aws_nat_gateway.this[count.index].id
}

resource "aws_route_table_association" "private" {
  count          = local.is_aws ? length(var.private_subnet_cidrs) : 0
  subnet_id      = aws_subnet.private[count.index].id
  route_table_id = aws_route_table.private[count.index].id
}

# Data subnets have NO egress to the internet (isolated DB tier).
resource "aws_route_table" "data" {
  count  = local.is_aws ? 1 : 0
  vpc_id = aws_vpc.this[0].id
  tags   = merge(var.tags, { Name = "${var.name_prefix}-rt-data" })
}

resource "aws_route_table_association" "data" {
  count          = local.is_aws ? length(var.data_subnet_cidrs) : 0
  subnet_id      = aws_subnet.data[count.index].id
  route_table_id = aws_route_table.data[0].id
}

# ── Security groups ───────────────────────────────────────────────────────────
resource "aws_security_group" "alb" {
  count       = local.is_aws ? 1 : 0
  name        = "${var.name_prefix}-alb-sg"
  description = "Public ALB ingress — HTTPS only."
  vpc_id      = aws_vpc.this[0].id

  ingress {
    description = "HTTPS from allowed CIDRs"
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = var.ingress_allowed_cidrs
  }

  egress {
    description = "All egress to app tier"
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = merge(var.tags, { Name = "${var.name_prefix}-alb-sg" })
}

resource "aws_security_group" "app" {
  count       = local.is_aws ? 1 : 0
  name        = "${var.name_prefix}-app-sg"
  description = "App tier — accepts traffic from ALB only."
  vpc_id      = aws_vpc.this[0].id

  ingress {
    description     = "Backend from ALB"
    from_port       = 8000
    to_port         = 8000
    protocol        = "tcp"
    security_groups = [aws_security_group.alb[0].id]
  }

  ingress {
    description     = "Frontend from ALB"
    from_port       = 8080
    to_port         = 8080
    protocol        = "tcp"
    security_groups = [aws_security_group.alb[0].id]
  }

  egress {
    description = "Egress (NAT) for OIDC, package mirrors, AWS APIs"
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = merge(var.tags, { Name = "${var.name_prefix}-app-sg" })
}

resource "aws_security_group" "database" {
  count       = local.is_aws ? 1 : 0
  name        = "${var.name_prefix}-db-sg"
  description = "PostgreSQL — accepts traffic from app tier only."
  vpc_id      = aws_vpc.this[0].id

  ingress {
    description     = "PostgreSQL from app tier"
    from_port       = 5432
    to_port         = 5432
    protocol        = "tcp"
    security_groups = [aws_security_group.app[0].id]
  }

  egress {
    description = "No egress required"
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = merge(var.tags, { Name = "${var.name_prefix}-db-sg" })
}

# VPC flow logs for audit (NIST AU / FedRAMP).
resource "aws_flow_log" "this" {
  count                = local.is_aws ? 1 : 0
  log_destination      = var.flow_log_destination_arn
  log_destination_type = "cloud-watch-logs"
  iam_role_arn         = var.flow_log_role_arn
  traffic_type         = "ALL"
  vpc_id               = aws_vpc.this[0].id
  tags                 = merge(var.tags, { Name = "${var.name_prefix}-flow-log" })
}

# -----------------------------------------------------------------------------
# Azure — VNet, subnets, NSGs, NAT gateway
# -----------------------------------------------------------------------------
resource "azurerm_virtual_network" "this" {
  count               = local.is_azure ? 1 : 0
  name                = "${var.name_prefix}-vnet"
  location            = var.azure_location
  resource_group_name = var.azure_resource_group_name
  address_space       = [var.cidr_block]
  tags                = var.tags
}

resource "azurerm_subnet" "public" {
  count                = local.is_azure ? 1 : 0
  name                 = "${var.name_prefix}-snet-public"
  resource_group_name  = var.azure_resource_group_name
  virtual_network_name = azurerm_virtual_network.this[0].name
  address_prefixes     = [var.public_subnet_cidrs[0]]
}

resource "azurerm_subnet" "private" {
  count                = local.is_azure ? 1 : 0
  name                 = "${var.name_prefix}-snet-private"
  resource_group_name  = var.azure_resource_group_name
  virtual_network_name = azurerm_virtual_network.this[0].name
  address_prefixes     = [var.private_subnet_cidrs[0]]

  # Delegate to Container Apps environment.
  delegation {
    name = "containerapps"
    service_delegation {
      name    = "Microsoft.App/environments"
      actions = ["Microsoft.Network/virtualNetworks/subnets/join/action"]
    }
  }
}

resource "azurerm_subnet" "data" {
  count                = local.is_azure ? 1 : 0
  name                 = "${var.name_prefix}-snet-data"
  resource_group_name  = var.azure_resource_group_name
  virtual_network_name = azurerm_virtual_network.this[0].name
  address_prefixes     = [var.data_subnet_cidrs[0]]
  service_endpoints    = ["Microsoft.Storage"]

  # Delegate to Flexible Server for VNet-injected private DB.
  delegation {
    name = "postgres"
    service_delegation {
      name    = "Microsoft.DBforPostgreSQL/flexibleServers"
      actions = ["Microsoft.Network/virtualNetworks/subnets/join/action"]
    }
  }
}

resource "azurerm_network_security_group" "app" {
  count               = local.is_azure ? 1 : 0
  name                = "${var.name_prefix}-app-nsg"
  location            = var.azure_location
  resource_group_name = var.azure_resource_group_name
  tags                = var.tags

  security_rule {
    name                       = "AllowHttpsInbound"
    priority                   = 100
    direction                  = "Inbound"
    access                     = "Allow"
    protocol                   = "Tcp"
    source_port_range          = "*"
    destination_port_range     = "443"
    source_address_prefix      = "*"
    destination_address_prefix = "*"
  }

  security_rule {
    name                       = "DenyAllInbound"
    priority                   = 4096
    direction                  = "Inbound"
    access                     = "Deny"
    protocol                   = "*"
    source_port_range          = "*"
    destination_port_range     = "*"
    source_address_prefix      = "*"
    destination_address_prefix = "*"
  }
}

resource "azurerm_network_security_group" "data" {
  count               = local.is_azure ? 1 : 0
  name                = "${var.name_prefix}-data-nsg"
  location            = var.azure_location
  resource_group_name = var.azure_resource_group_name
  tags                = var.tags

  security_rule {
    name                       = "AllowPostgresFromApp"
    priority                   = 100
    direction                  = "Inbound"
    access                     = "Allow"
    protocol                   = "Tcp"
    source_port_range          = "*"
    destination_port_range     = "5432"
    source_address_prefix      = var.private_subnet_cidrs[0]
    destination_address_prefix = "*"
  }

  security_rule {
    name                       = "DenyAllInbound"
    priority                   = 4096
    direction                  = "Inbound"
    access                     = "Deny"
    protocol                   = "*"
    source_port_range          = "*"
    destination_port_range     = "*"
    source_address_prefix      = "*"
    destination_address_prefix = "*"
  }
}

resource "azurerm_subnet_network_security_group_association" "app" {
  count                     = local.is_azure ? 1 : 0
  subnet_id                 = azurerm_subnet.private[0].id
  network_security_group_id = azurerm_network_security_group.app[0].id
}

resource "azurerm_subnet_network_security_group_association" "data" {
  count                     = local.is_azure ? 1 : 0
  subnet_id                 = azurerm_subnet.data[0].id
  network_security_group_id = azurerm_network_security_group.data[0].id
}

resource "azurerm_public_ip" "nat" {
  count               = local.is_azure ? 1 : 0
  name                = "${var.name_prefix}-nat-pip"
  location            = var.azure_location
  resource_group_name = var.azure_resource_group_name
  allocation_method   = "Static"
  sku                 = "Standard"
  tags                = var.tags
}

resource "azurerm_nat_gateway" "this" {
  count               = local.is_azure ? 1 : 0
  name                = "${var.name_prefix}-nat"
  location            = var.azure_location
  resource_group_name = var.azure_resource_group_name
  sku_name            = "Standard"
  tags                = var.tags
}

resource "azurerm_nat_gateway_public_ip_association" "this" {
  count                = local.is_azure ? 1 : 0
  nat_gateway_id       = azurerm_nat_gateway.this[0].id
  public_ip_address_id = azurerm_public_ip.nat[0].id
}

resource "azurerm_subnet_nat_gateway_association" "private" {
  count          = local.is_azure ? 1 : 0
  subnet_id      = azurerm_subnet.private[0].id
  nat_gateway_id = azurerm_nat_gateway.this[0].id
}
