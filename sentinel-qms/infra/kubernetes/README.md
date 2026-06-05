# Sentinel QMS — Kubernetes

Manifests for running Sentinel QMS on **EKS (AWS GovCloud)** or **AKS (Azure
Government)**. Two equivalent paths are provided:

- `base/` + `overlays/` — **Kustomize**
- `helm/sentinel-qms/` — **Helm** chart

Both deploy the same workloads: `sentinel-backend` (FastAPI, :8000) and
`sentinel-frontend` (nginx SPA, :8080), fronted by a cloud ingress.

## Security posture (NIST SP 800-171 / CMMC L2)

- Pods run **non-root**, `readOnlyRootFilesystem: true`, `allowPrivilegeEscalation: false`,
  all Linux capabilities dropped, `seccompProfile: RuntimeDefault`.
- Namespace enforces **Pod Security Admission `restricted`**.
- **Default-deny** NetworkPolicies; explicit allows for frontend->backend,
  ingress->pods, and backend egress to DNS / DB(5432) / HTTPS(443) only, with
  the cloud metadata endpoint (169.254.169.254) blocked.
- Probes hit backend `/health` and frontend `/`.
- HPA, PodDisruptionBudget, ResourceQuota, and LimitRange included.
- **No secrets in git**: `base/secret.yaml` is a placeholder. Real secrets come
  from AWS Secrets Manager via the External Secrets Operator (AWS overlay) or
  Azure Key Vault via the Secrets Store CSI Driver (Azure overlay).

## Kustomize

```bash
# Render / diff
kubectl kustomize overlays/aws-govcloud
kubectl kustomize overlays/azure-gov

# Apply
kubectl apply -k overlays/aws-govcloud
kubectl apply -k overlays/azure-gov
```

Edit the overlay placeholders (account ids, ACR/ECR registries, ACM/Key Vault
cert references, IRSA role ARN / workload-identity client id) before applying.

## Helm

```bash
helm upgrade --install sentinel-qms ./helm/sentinel-qms \
  --namespace sentinel-qms --create-namespace \
  -f helm/sentinel-qms/values-aws-govcloud.yaml \
  --set image.backend.tag=$GIT_SHA --set image.frontend.tag=$GIT_SHA
```

Swap `values-azure-gov.yaml` for AKS.

## Prerequisites

- EKS/AKS cluster with the appropriate ingress controller installed:
  AWS Load Balancer Controller (EKS) or AGIC (AKS).
- External Secrets Operator (EKS) or Secrets Store CSI Driver + Azure provider (AKS).
- Workload identity configured (IRSA on EKS, Entra Workload Identity on AKS) so
  the backend can reach object storage and the secret store without static keys.
