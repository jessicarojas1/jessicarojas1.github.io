# =============================================================================
# Remote state — S3 (GovCloud, encrypted) + DynamoDB lock table.
# Bootstrap these once with scripts/bootstrap-remote-state.sh, then
# `terraform init`. Values are intentionally left as placeholders so the
# bucket/table names can be supplied via -backend-config at init time.
# =============================================================================
terraform {
  backend "s3" {
    # bucket         = "sentinel-qms-tfstate-govcloud"
    key            = "aws-govcloud/sentinel-qms.tfstate"
    region         = "us-gov-west-1"
    encrypt        = true
    use_fips_endpoint = true
    # kms_key_id     = "arn:aws-us-gov:kms:us-gov-west-1:<acct>:key/<id>"
    # dynamodb_table = "sentinel-qms-tflock"
  }
}
